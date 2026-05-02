use anyhow::Result;
use fuser::{
    FileAttr, FileType, Filesystem, KernelConfig, MountOption, ReplyAttr, ReplyCreate, ReplyData,
    ReplyDirectory, ReplyEmpty, ReplyEntry, ReplyOpen, ReplyWrite, Request,
};
use libc::{EIO, ENOENT, ENOTSUP};
use std::collections::{BTreeMap, HashMap};
use std::ffi::{OsStr, OsString};
use std::fs::{self, File, OpenOptions};
use std::io;
use std::os::unix::fs::{FileExt, MetadataExt, OpenOptionsExt, PermissionsExt};
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use crate::config::{ClonePaths, Manifest};
use crate::overlay::OverlayStore;
use crate::remote::{RemoteClient, RemoteEntry};

const ROOT_INO: u64 = 1;
const TTL: Duration = Duration::from_secs(1);

#[derive(Clone)]
struct Timed<T> {
    value: T,
    expires_at: Instant,
}

enum Handle {
    Local(File),
    Remote(PathBuf),
}

pub struct CowFs {
    manifest: Manifest,
    remote: RemoteClient,
    overlay: OverlayStore,
    ino_to_path: HashMap<u64, PathBuf>,
    path_to_ino: HashMap<PathBuf, u64>,
    next_ino: u64,
    handles: HashMap<u64, Handle>,
    next_fh: u64,
    remote_stat_cache: HashMap<PathBuf, Timed<RemoteEntry>>,
    remote_readdir_cache: HashMap<PathBuf, Timed<Vec<RemoteEntry>>>,
    remote_cache_ttl: Duration,
    uid: u32,
    gid: u32,
}

impl CowFs {
    pub fn new(manifest: Manifest, paths: &ClonePaths, remote: RemoteClient) -> Self {
        let mut ino_to_path = HashMap::new();
        let mut path_to_ino = HashMap::new();
        ino_to_path.insert(ROOT_INO, PathBuf::new());
        path_to_ino.insert(PathBuf::new(), ROOT_INO);
        let remote_cache_ttl = Duration::from_secs(manifest.remote_metadata_cache_ttl_secs);
        Self {
            manifest,
            remote,
            overlay: OverlayStore::new(paths),
            ino_to_path,
            path_to_ino,
            next_ino: ROOT_INO + 1,
            handles: HashMap::new(),
            next_fh: 1,
            remote_stat_cache: HashMap::new(),
            remote_readdir_cache: HashMap::new(),
            remote_cache_ttl,
            uid: unsafe { libc::getuid() },
            gid: unsafe { libc::getgid() },
        }
    }

    fn ino_for_path(&mut self, rel: &Path) -> u64 {
        let rel = rel.to_path_buf();
        if let Some(ino) = self.path_to_ino.get(&rel) {
            return *ino;
        }
        let ino = self.next_ino;
        self.next_ino += 1;
        self.path_to_ino.insert(rel.clone(), ino);
        self.ino_to_path.insert(ino, rel);
        ino
    }

    fn path_for_ino(&self, ino: u64) -> Option<PathBuf> {
        self.ino_to_path.get(&ino).cloned()
    }

    fn child_path(&self, parent: u64, name: &OsStr) -> io::Result<PathBuf> {
        let parent_path = self
            .path_for_ino(parent)
            .ok_or_else(|| io::Error::new(io::ErrorKind::NotFound, "unknown parent inode"))?;
        let mut child = parent_path;
        child.push(name);
        OverlayStore::clean_rel(&child)
            .map_err(|err| io::Error::new(io::ErrorKind::InvalidInput, err.to_string()))
    }

    fn attr_for_path(&mut self, rel: &Path, ino: u64) -> io::Result<FileAttr> {
        if self.overlay.is_whiteout(rel).map_err(anyhow_to_io)? {
            return Err(io::Error::new(io::ErrorKind::NotFound, "whiteout"));
        }

        let upper = self.overlay.upper_path(rel).map_err(anyhow_to_io)?;
        if let Ok(metadata) = fs::symlink_metadata(&upper) {
            return Ok(self.attr_from_metadata(ino, &metadata));
        }

        let entry = self.remote_stat(rel)?;
        Ok(self.attr_from_remote(ino, &entry))
    }

    fn remote_stat(&mut self, rel: &Path) -> io::Result<RemoteEntry> {
        if let Some(cached) = self.remote_stat_cache.get(rel) {
            if cached.expires_at > Instant::now() {
                return Ok(cached.value.clone());
            }
        }

        if let Some(entry) = self.overlay.cached_entry(rel).map_err(anyhow_to_io)? {
            self.remote_stat_cache.insert(
                rel.to_path_buf(),
                Timed {
                    value: entry.clone(),
                    expires_at: Instant::now() + self.remote_cache_ttl,
                },
            );
            return Ok(entry);
        }

        let entry = self.remote.stat(rel)?;
        self.remote_stat_cache.insert(
            rel.to_path_buf(),
            Timed {
                value: entry.clone(),
                expires_at: Instant::now() + self.remote_cache_ttl,
            },
        );
        Ok(entry)
    }

    fn remote_readdir(&mut self, rel: &Path) -> io::Result<Vec<RemoteEntry>> {
        if let Some(cached) = self.remote_readdir_cache.get(rel) {
            if cached.expires_at > Instant::now() {
                return Ok(cached.value.clone());
            }
        }

        let entries = self.remote.readdir(rel)?;
        let expires_at = Instant::now() + self.remote_cache_ttl;
        for entry in &entries {
            let _ = self.overlay.put_cached_entry(&rel.join(&entry.name), entry);
            self.remote_stat_cache.insert(
                rel.join(&entry.name),
                Timed {
                    value: entry.clone(),
                    expires_at,
                },
            );
        }
        self.remote_readdir_cache.insert(
            rel.to_path_buf(),
            Timed {
                value: entries.clone(),
                expires_at,
            },
        );
        Ok(entries)
    }

    fn invalidate_remote_cache(&mut self, rel: &Path) {
        self.remote_stat_cache.remove(rel);
        self.remote_readdir_cache.remove(rel);
        if let Some(parent) = rel.parent() {
            self.remote_readdir_cache.remove(parent);
        }
        let _ = self.overlay.remove_cached(rel);
    }

    fn attr_from_metadata(&self, ino: u64, metadata: &fs::Metadata) -> FileAttr {
        let kind = if metadata.file_type().is_dir() {
            FileType::Directory
        } else if metadata.file_type().is_symlink() {
            FileType::Symlink
        } else {
            FileType::RegularFile
        };
        let mtime = unix_time(metadata.mtime() as u64);
        FileAttr {
            ino,
            size: metadata.len(),
            blocks: metadata.blocks(),
            atime: unix_time(metadata.atime() as u64),
            mtime,
            ctime: unix_time(metadata.ctime() as u64),
            crtime: mtime,
            kind,
            perm: (metadata.mode() & 0o7777) as u16,
            nlink: metadata.nlink() as u32,
            uid: metadata.uid(),
            gid: metadata.gid(),
            rdev: metadata.rdev() as u32,
            blksize: metadata.blksize() as u32,
            flags: 0,
        }
    }

    fn attr_from_remote(&self, ino: u64, entry: &RemoteEntry) -> FileAttr {
        let kind = match entry.kind.as_str() {
            "dir" => FileType::Directory,
            "symlink" => FileType::Symlink,
            _ => FileType::RegularFile,
        };
        let default_perm = match kind {
            FileType::Directory => 0o755,
            FileType::Symlink => 0o777,
            _ => 0o644,
        };
        FileAttr {
            ino,
            size: entry.size,
            blocks: entry.size.div_ceil(512),
            atime: unix_time(entry.mtime),
            mtime: unix_time(entry.mtime),
            ctime: unix_time(entry.mtime),
            crtime: unix_time(entry.mtime),
            kind,
            perm: ((entry.mode & 0o7777) as u16).max(default_perm),
            nlink: if kind == FileType::Directory { 2 } else { 1 },
            uid: self.uid,
            gid: self.gid,
            rdev: 0,
            blksize: 4096,
            flags: 0,
        }
    }

    fn allocate_handle(&mut self, handle: Handle) -> u64 {
        let fh = self.next_fh;
        self.next_fh += 1;
        self.handles.insert(fh, handle);
        fh
    }
}

impl Filesystem for CowFs {
    fn init(
        &mut self,
        _req: &Request<'_>,
        _config: &mut KernelConfig,
    ) -> std::result::Result<(), i32> {
        Ok(())
    }

    fn lookup(&mut self, _req: &Request<'_>, parent: u64, name: &OsStr, reply: ReplyEntry) {
        let result = (|| {
            let rel = self.child_path(parent, name)?;
            let ino = self.ino_for_path(&rel);
            self.attr_for_path(&rel, ino)
        })();
        match result {
            Ok(attr) => reply.entry(&TTL, &attr, 0),
            Err(err) => reply.error(io_errno(&err)),
        }
    }

    fn getattr(&mut self, _req: &Request<'_>, ino: u64, _fh: Option<u64>, reply: ReplyAttr) {
        let result = (|| {
            if ino == ROOT_INO {
                return Ok(root_attr(self.uid, self.gid));
            }
            let rel = self
                .path_for_ino(ino)
                .ok_or_else(|| io::Error::new(io::ErrorKind::NotFound, "unknown inode"))?;
            self.attr_for_path(&rel, ino)
        })();
        match result {
            Ok(attr) => reply.attr(&TTL, &attr),
            Err(err) => reply.error(io_errno(&err)),
        }
    }

    fn readlink(&mut self, _req: &Request<'_>, ino: u64, reply: ReplyData) {
        let result = (|| {
            let rel = self
                .path_for_ino(ino)
                .ok_or_else(|| io::Error::new(io::ErrorKind::NotFound, "unknown inode"))?;
            let upper = self.overlay.upper_path(&rel).map_err(anyhow_to_io)?;
            if upper.exists() {
                return fs::read_link(upper).map(|p| p.to_string_lossy().into_owned());
            }
            self.remote.readlink(&rel)
        })();
        match result {
            Ok(target) => reply.data(target.as_bytes()),
            Err(err) => reply.error(io_errno(&err)),
        }
    }

    fn mkdir(
        &mut self,
        _req: &Request<'_>,
        parent: u64,
        name: &OsStr,
        mode: u32,
        _umask: u32,
        reply: ReplyEntry,
    ) {
        let result = (|| {
            let rel = self.child_path(parent, name)?;
            let upper = self.overlay.upper_path(&rel).map_err(anyhow_to_io)?;
            fs::create_dir_all(&upper)?;
            fs::set_permissions(&upper, fs::Permissions::from_mode(mode & 0o7777))?;
            self.overlay.clear_whiteout(&rel).map_err(anyhow_to_io)?;
            self.invalidate_remote_cache(&rel);
            let ino = self.ino_for_path(&rel);
            self.attr_for_path(&rel, ino)
        })();
        match result {
            Ok(attr) => reply.entry(&TTL, &attr, 0),
            Err(err) => reply.error(io_errno(&err)),
        }
    }

    fn unlink(&mut self, _req: &Request<'_>, parent: u64, name: &OsStr, reply: ReplyEmpty) {
        self.remove_path(parent, name, reply);
    }

    fn rmdir(&mut self, _req: &Request<'_>, parent: u64, name: &OsStr, reply: ReplyEmpty) {
        self.remove_path(parent, name, reply);
    }

    fn rename(
        &mut self,
        _req: &Request<'_>,
        parent: u64,
        name: &OsStr,
        newparent: u64,
        newname: &OsStr,
        flags: u32,
        reply: ReplyEmpty,
    ) {
        let result = (|| {
            if flags != 0 {
                return Err(io::Error::from_raw_os_error(ENOTSUP));
            }
            let old_rel = self.child_path(parent, name)?;
            let new_rel = self.child_path(newparent, newname)?;
            let old_upper = self.overlay.upper_path(&old_rel).map_err(anyhow_to_io)?;
            let new_upper = self.overlay.upper_path(&new_rel).map_err(anyhow_to_io)?;
            if let Some(parent) = new_upper.parent() {
                fs::create_dir_all(parent)?;
            }

            if !old_upper.exists() {
                let entry = self.remote_stat(&old_rel)?;
                if entry.kind == "dir" {
                    return Err(io::Error::from_raw_os_error(ENOTSUP));
                }
                self.overlay
                    .copy_up(&self.remote, &old_rel)
                    .map_err(anyhow_to_io)?;
            }

            fs::rename(&old_upper, &new_upper)?;
            self.overlay.add_whiteout(&old_rel).map_err(anyhow_to_io)?;
            self.overlay
                .clear_whiteout(&new_rel)
                .map_err(anyhow_to_io)?;
            self.invalidate_remote_cache(&old_rel);
            self.invalidate_remote_cache(&new_rel);
            let ino = self.ino_for_path(&new_rel);
            self.ino_to_path.insert(ino, new_rel.clone());
            self.path_to_ino.insert(new_rel, ino);
            Ok(())
        })();
        match result {
            Ok(()) => reply.ok(),
            Err(err) => reply.error(io_errno(&err)),
        }
    }

    fn open(&mut self, _req: &Request<'_>, ino: u64, flags: i32, reply: ReplyOpen) {
        let result = (|| {
            let rel = self
                .path_for_ino(ino)
                .ok_or_else(|| io::Error::new(io::ErrorKind::NotFound, "unknown inode"))?;
            if wants_write(flags) {
                let upper = self
                    .overlay
                    .copy_up(&self.remote, &rel)
                    .map_err(anyhow_to_io)?;
                let mut opts = OpenOptions::new();
                opts.read(true).write(true).create(true);
                if flags & libc::O_TRUNC != 0 {
                    opts.truncate(true);
                }
                if flags & libc::O_APPEND != 0 {
                    opts.append(true);
                }
                let file = opts.open(upper)?;
                Ok((self.allocate_handle(Handle::Local(file)), flags as u32))
            } else {
                let upper = self.overlay.upper_path(&rel).map_err(anyhow_to_io)?;
                if upper.exists() {
                    let file = File::open(upper)?;
                    Ok((self.allocate_handle(Handle::Local(file)), flags as u32))
                } else if let Some(cache_path) = self.overlay.cached_file_path(&rel) {
                    let file = File::open(cache_path)?;
                    Ok((self.allocate_handle(Handle::Local(file)), flags as u32))
                } else {
                    Ok((self.allocate_handle(Handle::Remote(rel)), flags as u32))
                }
            }
        })();
        match result {
            Ok((fh, open_flags)) => reply.opened(fh, open_flags),
            Err(err) => reply.error(io_errno(&err)),
        }
    }

    fn read(
        &mut self,
        _req: &Request<'_>,
        _ino: u64,
        fh: u64,
        offset: i64,
        size: u32,
        _flags: i32,
        _lock_owner: Option<u64>,
        reply: ReplyData,
    ) {
        let result = match self.handles.get(&fh) {
            Some(Handle::Local(file)) => {
                let mut buf = vec![0; size as usize];
                if offset < 0 {
                    Ok(Vec::new())
                } else {
                    match file.read_at(&mut buf, offset as u64) {
                        Ok(read) => {
                            buf.truncate(read);
                            Ok(buf)
                        }
                        Err(err) if err.kind() == io::ErrorKind::UnexpectedEof => Ok(Vec::new()),
                        Err(err) => Err(err),
                    }
                }
            }
            Some(Handle::Remote(rel)) => self
                .overlay
                .read_cached_or_remote(
                    &self.remote,
                    rel,
                    offset,
                    size,
                    self.manifest.cache_max_file_bytes,
                )
                .map_err(anyhow_to_io),
            None => Err(io::Error::new(io::ErrorKind::NotFound, "unknown handle")),
        };
        match result {
            Ok(bytes) => reply.data(&bytes),
            Err(err) => reply.error(io_errno(&err)),
        }
    }

    fn write(
        &mut self,
        _req: &Request<'_>,
        _ino: u64,
        fh: u64,
        offset: i64,
        data: &[u8],
        _write_flags: u32,
        _flags: i32,
        _lock_owner: Option<u64>,
        reply: ReplyWrite,
    ) {
        let result = match self.handles.get(&fh) {
            Some(Handle::Local(file)) => {
                if offset < 0 {
                    Err(io::Error::new(
                        io::ErrorKind::InvalidInput,
                        "negative offset",
                    ))
                } else {
                    file.write_at(data, offset as u64)
                        .map(|written| written as u32)
                }
            }
            _ => Err(io::Error::new(
                io::ErrorKind::Other,
                "handle is not writable",
            )),
        };
        match result {
            Ok(written) => reply.written(written),
            Err(err) => reply.error(io_errno(&err)),
        }
    }

    fn create(
        &mut self,
        _req: &Request<'_>,
        parent: u64,
        name: &OsStr,
        mode: u32,
        _umask: u32,
        flags: i32,
        reply: ReplyCreate,
    ) {
        let result = (|| {
            let rel = self.child_path(parent, name)?;
            let upper = self.overlay.upper_path(&rel).map_err(anyhow_to_io)?;
            if let Some(parent) = upper.parent() {
                fs::create_dir_all(parent)?;
            }
            let mut opts = OpenOptions::new();
            opts.read(true)
                .write(true)
                .create(true)
                .truncate(flags & libc::O_TRUNC != 0)
                .mode(mode & 0o7777);
            let file = opts.open(&upper)?;
            self.overlay.clear_whiteout(&rel).map_err(anyhow_to_io)?;
            self.invalidate_remote_cache(&rel);
            let ino = self.ino_for_path(&rel);
            let attr = self.attr_for_path(&rel, ino)?;
            let fh = self.allocate_handle(Handle::Local(file));
            Ok((attr, fh))
        })();
        match result {
            Ok((attr, fh)) => reply.created(&TTL, &attr, 0, fh, flags as u32),
            Err(err) => reply.error(io_errno(&err)),
        }
    }

    fn flush(
        &mut self,
        _req: &Request<'_>,
        _ino: u64,
        fh: u64,
        _lock_owner: u64,
        reply: ReplyEmpty,
    ) {
        if let Some(Handle::Local(file)) = self.handles.get(&fh) {
            if let Err(err) = file.sync_data() {
                reply.error(io_errno(&err));
                return;
            }
        }
        reply.ok();
    }

    fn release(
        &mut self,
        _req: &Request<'_>,
        _ino: u64,
        fh: u64,
        _flags: i32,
        _lock_owner: Option<u64>,
        _flush: bool,
        reply: ReplyEmpty,
    ) {
        self.handles.remove(&fh);
        reply.ok();
    }

    fn readdir(
        &mut self,
        _req: &Request<'_>,
        ino: u64,
        _fh: u64,
        offset: i64,
        mut reply: ReplyDirectory,
    ) {
        let result = self.collect_dir_entries(ino);
        let entries = match result {
            Ok(entries) => entries,
            Err(err) => {
                reply.error(io_errno(&err));
                return;
            }
        };

        for (idx, (entry_ino, kind, name)) in entries.into_iter().enumerate().skip(offset as usize)
        {
            let next_offset = (idx + 1) as i64;
            if reply.add(entry_ino, next_offset, kind, name) {
                break;
            }
        }
        reply.ok();
    }
}

impl CowFs {
    fn remove_path(&mut self, parent: u64, name: &OsStr, reply: ReplyEmpty) {
        let result = (|| {
            let rel = self.child_path(parent, name)?;
            self.overlay.remove_upper(&rel).map_err(anyhow_to_io)?;
            if self.remote_stat(&rel).is_ok() {
                self.overlay.add_whiteout(&rel).map_err(anyhow_to_io)?;
            }
            self.invalidate_remote_cache(&rel);
            Ok(())
        })();
        match result {
            Ok(()) => reply.ok(),
            Err(err) => reply.error(io_errno(&err)),
        }
    }

    fn collect_dir_entries(&mut self, ino: u64) -> io::Result<Vec<(u64, FileType, OsString)>> {
        let rel = self
            .path_for_ino(ino)
            .ok_or_else(|| io::Error::new(io::ErrorKind::NotFound, "unknown inode"))?;

        let mut entries = Vec::new();
        entries.push((ino, FileType::Directory, OsString::from(".")));
        let parent_rel = rel.parent().unwrap_or_else(|| Path::new(""));
        let parent_ino = self.ino_for_path(parent_rel);
        entries.push((parent_ino, FileType::Directory, OsString::from("..")));

        let mut by_name: BTreeMap<String, RemoteEntry> = BTreeMap::new();
        match self.remote_readdir(&rel) {
            Ok(remote_entries) => {
                for entry in remote_entries {
                    by_name.insert(entry.name.clone(), entry);
                }
            }
            Err(err) if err.kind() == io::ErrorKind::NotFound => {}
            Err(err) => return Err(err),
        }

        for entry in self.overlay.list_upper(&rel).map_err(anyhow_to_io)? {
            by_name.insert(entry.name.clone(), entry);
        }

        for (name, entry) in by_name {
            let child_rel = rel.join(&name);
            if self.overlay.is_whiteout(&child_rel).map_err(anyhow_to_io)? {
                continue;
            }
            let child_ino = self.ino_for_path(&child_rel);
            entries.push((
                child_ino,
                file_type_from_kind(&entry.kind),
                OsString::from(name),
            ));
        }

        Ok(entries)
    }
}

pub fn mount_foreground(manifest: Manifest, paths: ClonePaths, mountpoint: &Path) -> Result<()> {
    fs::create_dir_all(mountpoint)?;
    let control_path = paths.run.join("ssh-control.sock");
    let remote = RemoteClient::new(manifest.clone(), Some(control_path));
    remote.ensure_master()?;
    let fs = CowFs::new(manifest.clone(), &paths, remote);
    let options = vec![
        MountOption::FSName(format!("wp-cow-{}", manifest.name)),
        MountOption::Subtype("wp-cow".to_string()),
        MountOption::AutoUnmount,
        MountOption::DefaultPermissions,
    ];
    fuser::mount2(fs, mountpoint, &options)?;
    Ok(())
}

fn root_attr(uid: u32, gid: u32) -> FileAttr {
    FileAttr {
        ino: ROOT_INO,
        size: 0,
        blocks: 0,
        atime: SystemTime::now(),
        mtime: SystemTime::now(),
        ctime: SystemTime::now(),
        crtime: SystemTime::now(),
        kind: FileType::Directory,
        perm: 0o755,
        nlink: 2,
        uid,
        gid,
        rdev: 0,
        blksize: 4096,
        flags: 0,
    }
}

fn file_type_from_kind(kind: &str) -> FileType {
    match kind {
        "dir" => FileType::Directory,
        "symlink" => FileType::Symlink,
        _ => FileType::RegularFile,
    }
}

fn unix_time(secs: u64) -> SystemTime {
    UNIX_EPOCH + Duration::from_secs(secs)
}

fn wants_write(flags: i32) -> bool {
    (flags & libc::O_ACCMODE) != libc::O_RDONLY
        || flags & libc::O_TRUNC != 0
        || flags & libc::O_APPEND != 0
}

fn io_errno(err: &io::Error) -> i32 {
    match err.kind() {
        io::ErrorKind::NotFound => ENOENT,
        io::ErrorKind::Unsupported => ENOTSUP,
        _ => err.raw_os_error().unwrap_or(EIO),
    }
}

fn anyhow_to_io(err: anyhow::Error) -> io::Error {
    io::Error::new(io::ErrorKind::Other, err.to_string())
}
