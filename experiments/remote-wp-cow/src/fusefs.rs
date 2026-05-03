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

use crate::config::{self, ClonePaths, Manifest};
use crate::overlay::OverlayStore;
use crate::remote::{RemoteClient, RemoteEntry};

const ROOT_INO: u64 = 1;
const DEFAULT_KERNEL_CACHE_TTL_SECS: u64 = 60;

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
    remote_missing_cache: HashMap<PathBuf, Instant>,
    remote_readdir_cache: HashMap<PathBuf, Timed<Vec<RemoteEntry>>>,
    remote_cache_ttl: Duration,
    kernel_cache_ttl: Duration,
    offline: bool,
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
        let kernel_cache_ttl = Duration::from_secs(env_u64(
            "WPCOW_FUSE_TTL_SECS",
            DEFAULT_KERNEL_CACHE_TTL_SECS,
        ));
        let offline = config::is_offline(paths);
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
            remote_missing_cache: HashMap::new(),
            remote_readdir_cache: HashMap::new(),
            remote_cache_ttl,
            kernel_cache_ttl,
            offline,
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

        let mirror = self.overlay.mirror_path(rel).map_err(anyhow_to_io)?;
        if let Ok(metadata) = fs::symlink_metadata(&mirror) {
            return Ok(self.attr_from_metadata(ino, &metadata));
        }

        if self.has_opaque_ancestor_active(rel)? {
            return Err(io::Error::new(
                io::ErrorKind::NotFound,
                "hidden by local opaque directory",
            ));
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
        if let Some(expires_at) = self.remote_missing_cache.get(rel) {
            if *expires_at > Instant::now() {
                return Err(io::Error::new(
                    io::ErrorKind::NotFound,
                    "cached remote miss",
                ));
            }
        }

        if let Some(entry) = self.overlay.cached_entry(rel).map_err(anyhow_to_io)? {
            self.remote_missing_cache.remove(rel);
            self.remote_stat_cache.insert(
                rel.to_path_buf(),
                Timed {
                    value: entry.clone(),
                    expires_at: Instant::now() + self.remote_cache_ttl,
                },
            );
            return Ok(entry);
        }
        if self.overlay.cached_missing(rel).map_err(anyhow_to_io)? {
            self.remote_missing_cache
                .insert(rel.to_path_buf(), Instant::now() + self.remote_cache_ttl);
            return Err(io::Error::new(
                io::ErrorKind::NotFound,
                "cached remote miss",
            ));
        }

        if self.offline {
            return Err(io::Error::new(
                io::ErrorKind::NotFound,
                "clone is severed and path is not cached locally",
            ));
        }

        let entry = match self.remote.stat(rel) {
            Ok(entry) => entry,
            Err(err) if err.kind() == io::ErrorKind::NotFound => {
                self.remote_missing_cache
                    .insert(rel.to_path_buf(), Instant::now() + self.remote_cache_ttl);
                let _ = self
                    .overlay
                    .put_cached_missing(rel, self.remote_cache_ttl.as_secs());
                return Err(err);
            }
            Err(err) => return Err(err),
        };
        self.remote_missing_cache.remove(rel);
        let _ = self.overlay.put_cached_entry(rel, &entry);
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
        if self.offline {
            return self
                .overlay
                .list_cached_metadata_dir(rel)
                .map_err(anyhow_to_io);
        }

        if let Some(cached) = self.remote_readdir_cache.get(rel) {
            if cached.expires_at > Instant::now() {
                return Ok(cached.value.clone());
            }
        }
        if self.overlay.cached_missing(rel).map_err(anyhow_to_io)? {
            self.remote_missing_cache
                .insert(rel.to_path_buf(), Instant::now() + self.remote_cache_ttl);
            return Err(io::Error::new(
                io::ErrorKind::NotFound,
                "cached remote miss",
            ));
        }

        let entries = match self.remote.readdir(rel) {
            Ok(entries) => entries,
            Err(err) if err.kind() == io::ErrorKind::NotFound => {
                self.remote_missing_cache
                    .insert(rel.to_path_buf(), Instant::now() + self.remote_cache_ttl);
                let _ = self
                    .overlay
                    .put_cached_missing(rel, self.remote_cache_ttl.as_secs());
                return Err(err);
            }
            Err(err) => return Err(err),
        };
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
        self.remote_missing_cache.remove(rel);
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
            trace_fuse("lookup", &rel);
            let ino = self.ino_for_path(&rel);
            self.attr_for_path(&rel, ino)
        })();
        match result {
            Ok(attr) => reply.entry(&self.kernel_cache_ttl, &attr, 0),
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
            trace_fuse("getattr", &rel);
            self.attr_for_path(&rel, ino)
        })();
        match result {
            Ok(attr) => reply.attr(&self.kernel_cache_ttl, &attr),
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
            if self.offline {
                return Err(io::Error::new(
                    io::ErrorKind::NotFound,
                    "clone is severed and symlink is not cached locally",
                ));
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
            Ok(attr) => reply.entry(&self.kernel_cache_ttl, &attr, 0),
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
                self.copy_up_for_write(&old_rel)?;
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
            trace_fuse("open", &rel);
            if wants_write(flags) {
                let upper = self.copy_up_for_write(&rel)?;
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
                    Ok((self.allocate_handle(Handle::Local(file)), 0))
                } else if let Some(cache_path) = self.overlay.cached_file_path(&rel) {
                    let file = File::open(cache_path)?;
                    Ok((self.allocate_handle(Handle::Local(file)), 0))
                } else if self.offline {
                    Err(io::Error::new(
                        io::ErrorKind::NotFound,
                        "clone is severed and file is not cached locally",
                    ))
                } else {
                    Ok((self.allocate_handle(Handle::Remote(rel)), 0))
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
            Some(Handle::Remote(rel)) => {
                let rel = rel.clone();
                if self.offline {
                    return reply.error(ENOENT);
                }
                trace_fuse("read-remote", &rel);
                let entry = self.remote_stat(&rel).ok();
                self.overlay
                    .read_cached_or_remote_with_entry(
                        &self.remote,
                        &rel,
                        offset,
                        size,
                        self.manifest.cache_max_file_bytes,
                        entry,
                    )
                    .map_err(anyhow_to_io)
            }
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
            Ok((attr, fh)) => reply.created(&self.kernel_cache_ttl, &attr, 0, fh, flags as u32),
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
        trace_fuse("readdir", &rel);

        let mut entries = Vec::new();
        entries.push((ino, FileType::Directory, OsString::from(".")));
        let parent_rel = rel.parent().unwrap_or_else(|| Path::new(""));
        let parent_ino = self.ino_for_path(parent_rel);
        entries.push((parent_ino, FileType::Directory, OsString::from("..")));

        let mut by_name: BTreeMap<String, RemoteEntry> = BTreeMap::new();
        let opaque = self.is_opaque_dir_active(&rel)?;
        if !opaque {
            match self.remote_readdir(&rel) {
                Ok(remote_entries) => {
                    for entry in remote_entries {
                        by_name.insert(entry.name.clone(), entry);
                    }
                }
                Err(err) if err.kind() == io::ErrorKind::NotFound => {}
                Err(err) => return Err(err),
            }
        }

        for entry in self.overlay.list_upper(&rel).map_err(anyhow_to_io)? {
            by_name.insert(entry.name.clone(), entry);
        }
        if !opaque {
            for entry in self.overlay.list_mirror(&rel).map_err(anyhow_to_io)? {
                by_name.insert(entry.name.clone(), entry);
            }
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

    fn copy_up_for_write(&self, rel: &Path) -> io::Result<PathBuf> {
        if self.offline {
            self.overlay
                .copy_up_cached_only(rel)
                .map_err(|err| io::Error::new(io::ErrorKind::NotFound, err.to_string()))
        } else {
            self.overlay
                .copy_up(&self.remote, rel)
                .map_err(anyhow_to_io)
        }
    }

    fn is_opaque_dir_active(&self, rel: &Path) -> io::Result<bool> {
        let is_opaque = self.overlay.is_opaque_dir(rel).map_err(anyhow_to_io)?;
        if !is_opaque {
            return Ok(false);
        }
        if rel.starts_with(Path::new("wp-content/plugins"))
            && !env_is_explicit_false("WPCOW_ENABLE_PLUGINS")
        {
            return Ok(false);
        }
        if rel.starts_with(Path::new("wp-content/languages")) {
            return Ok(false);
        }
        Ok(true)
    }

    fn has_opaque_ancestor_active(&self, rel: &Path) -> io::Result<bool> {
        let mut current = rel.parent();
        while let Some(parent) = current {
            if self.is_opaque_dir_active(parent)? {
                return Ok(true);
            }
            current = parent.parent();
        }
        Ok(false)
    }
}

pub fn mount_foreground(manifest: Manifest, paths: ClonePaths, mountpoint: &Path) -> Result<()> {
    fs::create_dir_all(mountpoint)?;
    let control_path = config::ssh_control_path(&paths);
    let remote = RemoteClient::new(manifest.clone(), Some(control_path));
    if !config::is_offline(&paths) {
        remote.ensure_master()?;
    }
    let fs = CowFs::new(manifest.clone(), &paths, remote);
    let options = vec![
        MountOption::FSName(format!("wp-cow-{}", manifest.name)),
        MountOption::Subtype("wp-cow".to_string()),
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

fn trace_fuse(op: &str, rel: &Path) {
    if std::env::var("WPCOW_TRACE_FUSE").ok().as_deref() == Some("1") {
        eprintln!("wp-cow fuse {op} {}", OverlayStore::rel_string(rel));
    }
}

fn wants_write(flags: i32) -> bool {
    (flags & libc::O_ACCMODE) != libc::O_RDONLY
        || flags & libc::O_TRUNC != 0
        || flags & libc::O_APPEND != 0
}

fn env_is_explicit_false(name: &str) -> bool {
    std::env::var(name)
        .ok()
        .map(|raw| {
            matches!(
                raw.to_ascii_lowercase().as_str(),
                "0" | "false" | "no" | "off"
            )
        })
        .unwrap_or(false)
}

fn env_u64(name: &str, default: u64) -> u64 {
    std::env::var(name)
        .ok()
        .and_then(|raw| raw.parse::<u64>().ok())
        .unwrap_or(default)
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

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::{ensure_clone_dirs, write_offline_marker, Manifest, OfflineMarker, Probe};
    use std::sync::{Mutex, OnceLock};

    fn test_manifest() -> Manifest {
        Manifest::new(
            "example".to_string(),
            "unreachable-host".to_string(),
            "/remote/wp".to_string(),
            "https://example.com".to_string(),
            "http://example.test".to_string(),
            Probe {
                table_prefix: "wp_".to_string(),
                ..Probe::default()
            },
        )
    }

    #[test]
    fn offline_write_copy_up_uses_cached_lower_without_remote() {
        let temp = tempfile::tempdir().unwrap();
        let paths = crate::config::clone_paths(temp.path(), "example");
        ensure_clone_dirs(&paths).unwrap();
        write_offline_marker(
            &paths,
            &OfflineMarker {
                severed_at_unix: 1,
                materialized_tables: Vec::new(),
                admin_user: None,
            },
        )
        .unwrap();

        let manifest = test_manifest();
        let store = OverlayStore::new(&paths);
        let rel = Path::new("wp-admin/index.php");
        let cache_path = store.cache_path(rel);
        fs::create_dir_all(cache_path.parent().unwrap()).unwrap();
        fs::write(&cache_path, b"cached admin runtime\n").unwrap();
        store
            .put_cached_entry(
                rel,
                &RemoteEntry {
                    name: "index.php".to_string(),
                    kind: "file".to_string(),
                    size: 21,
                    mode: 0o100644,
                    mtime: 42,
                },
            )
            .unwrap();

        let fs = CowFs::new(manifest.clone(), &paths, RemoteClient::new(manifest, None));
        let upper = fs.copy_up_for_write(rel).unwrap();
        assert_eq!(std::fs::read(&upper).unwrap(), b"cached admin runtime\n");

        let err = fs
            .copy_up_for_write(Path::new("wp-admin/missing.php"))
            .unwrap_err();
        assert_eq!(err.kind(), io::ErrorKind::NotFound);
        assert!(
            err.to_string()
                .contains("clone is severed and writable lower file is not cached locally"),
            "unexpected error: {err}"
        );
    }

    #[test]
    fn offline_readdir_uses_cached_remote_metadata_without_remote() {
        let temp = tempfile::tempdir().unwrap();
        let paths = crate::config::clone_paths(temp.path(), "example");
        ensure_clone_dirs(&paths).unwrap();
        write_offline_marker(
            &paths,
            &OfflineMarker {
                severed_at_unix: 1,
                materialized_tables: Vec::new(),
                admin_user: None,
            },
        )
        .unwrap();

        let manifest = test_manifest();
        let store = OverlayStore::new(&paths);
        store
            .put_cached_entry(
                Path::new("wp-content/plugins/hello.php"),
                &RemoteEntry {
                    name: "hello.php".to_string(),
                    kind: "file".to_string(),
                    size: 18,
                    mode: 0o100644,
                    mtime: 42,
                },
            )
            .unwrap();
        store
            .put_cached_entry(
                Path::new("wp-content/plugins/sample"),
                &RemoteEntry {
                    name: "sample".to_string(),
                    kind: "dir".to_string(),
                    size: 0,
                    mode: 0o40755,
                    mtime: 42,
                },
            )
            .unwrap();

        let mut fs = CowFs::new(manifest.clone(), &paths, RemoteClient::new(manifest, None));
        let entries = fs.remote_readdir(Path::new("wp-content/plugins")).unwrap();
        let names = entries
            .into_iter()
            .map(|entry| entry.name)
            .collect::<Vec<_>>();

        assert_eq!(
            names,
            vec!["hello.php".to_string(), "sample".to_string()],
            "offline readdir should use cached remote metadata without touching SSH"
        );
    }

    #[test]
    fn remote_stat_metadata_survives_severed_mode_without_remote() {
        static ENV_LOCK: OnceLock<Mutex<()>> = OnceLock::new();
        let _guard = ENV_LOCK.get_or_init(|| Mutex::new(())).lock().unwrap();
        let old_path = std::env::var_os("PATH");
        let old_helper = std::env::var_os("WPCOW_REMOTE_FILE_HELPER");

        let temp = tempfile::tempdir().unwrap();
        let remote_root = temp.path().join("remote");
        let fake_bin = temp.path().join("bin");
        fs::create_dir_all(remote_root.join("wp-content/themes/neve/assets/js/build/modern"))
            .unwrap();
        fs::create_dir_all(&fake_bin).unwrap();
        fs::write(
            remote_root.join("wp-content/themes/neve/assets/js/build/modern/frontend.js"),
            b"/* theme build asset */",
        )
        .unwrap();
        let fake_ssh = fake_bin.join("ssh");
        fs::write(
            &fake_ssh,
            r#"#!/usr/bin/env bash
set -euo pipefail
cmd="${@: -1}"
exec bash -lc "$cmd"
"#,
        )
        .unwrap();
        let mut perms = fs::metadata(&fake_ssh).unwrap().permissions();
        perms.set_mode(0o755);
        fs::set_permissions(&fake_ssh, perms).unwrap();

        let path = match old_path.as_ref() {
            Some(old) => format!("{}:{}", fake_bin.display(), old.to_string_lossy()),
            None => fake_bin.display().to_string(),
        };
        std::env::set_var("PATH", path);
        std::env::set_var("WPCOW_REMOTE_FILE_HELPER", "0");

        let paths = crate::config::clone_paths(temp.path().join("state").as_path(), "example");
        ensure_clone_dirs(&paths).unwrap();
        let manifest = Manifest::new(
            "example".to_string(),
            "fake-host".to_string(),
            remote_root.to_string_lossy().to_string(),
            "https://example.com".to_string(),
            "http://example.test".to_string(),
            Probe {
                table_prefix: "wp_".to_string(),
                ..Probe::default()
            },
        );
        let rel = Path::new("wp-content/themes/neve/assets/js/build/modern/frontend.js");

        let mut fs = CowFs::new(
            manifest.clone(),
            &paths,
            RemoteClient::new(manifest.clone(), None),
        );
        let entry = fs.remote_stat(rel).unwrap();
        assert_eq!(entry.size, 23);
        assert_eq!(
            OverlayStore::new(&paths)
                .cached_entry(rel)
                .unwrap()
                .unwrap()
                .size,
            23,
            "successful stat-only lookups must persist metadata for later offline theme checks"
        );

        write_offline_marker(
            &paths,
            &OfflineMarker {
                severed_at_unix: 1,
                materialized_tables: Vec::new(),
                admin_user: None,
            },
        )
        .unwrap();
        fs::remove_file(remote_root.join(rel)).unwrap();
        let mut offline_fs =
            CowFs::new(manifest.clone(), &paths, RemoteClient::new(manifest, None));
        assert_eq!(
            offline_fs.remote_stat(rel).unwrap().size,
            23,
            "severed clones need stat-only metadata without consulting SSH"
        );

        match old_path {
            Some(value) => std::env::set_var("PATH", value),
            None => std::env::remove_var("PATH"),
        }
        match old_helper {
            Some(value) => std::env::set_var("WPCOW_REMOTE_FILE_HELPER", value),
            None => std::env::remove_var("WPCOW_REMOTE_FILE_HELPER"),
        }
    }

    #[test]
    fn remote_missing_metadata_survives_daemon_restart() {
        static ENV_LOCK: OnceLock<Mutex<()>> = OnceLock::new();
        let _guard = ENV_LOCK.get_or_init(|| Mutex::new(())).lock().unwrap();
        let old_path = std::env::var_os("PATH");
        let old_log = std::env::var_os("WPCOW_FAKE_SSH_LOG");
        let old_helper = std::env::var_os("WPCOW_REMOTE_FILE_HELPER");

        let temp = tempfile::tempdir().unwrap();
        let remote_root = temp.path().join("remote");
        let fake_bin = temp.path().join("bin");
        let fake_ssh_log = temp.path().join("fake-ssh.log");
        fs::create_dir_all(&remote_root).unwrap();
        fs::create_dir_all(&fake_bin).unwrap();
        fs::write(
            fake_bin.join("ssh"),
            r#"#!/usr/bin/env bash
set -euo pipefail
printf 'CALL\n' >> "$WPCOW_FAKE_SSH_LOG"
cmd="${@: -1}"
exec bash -lc "$cmd"
"#,
        )
        .unwrap();
        let mut perms = fs::metadata(fake_bin.join("ssh")).unwrap().permissions();
        perms.set_mode(0o755);
        fs::set_permissions(fake_bin.join("ssh"), perms).unwrap();

        let path = match old_path.as_ref() {
            Some(old) => format!("{}:{}", fake_bin.display(), old.to_string_lossy()),
            None => fake_bin.display().to_string(),
        };
        std::env::set_var("PATH", path);
        std::env::set_var("WPCOW_FAKE_SSH_LOG", &fake_ssh_log);
        std::env::set_var("WPCOW_REMOTE_FILE_HELPER", "0");

        let paths = crate::config::clone_paths(temp.path().join("state").as_path(), "example");
        ensure_clone_dirs(&paths).unwrap();
        let mut manifest = Manifest::new(
            "example".to_string(),
            "fake-host".to_string(),
            remote_root.to_string_lossy().to_string(),
            "https://example.com".to_string(),
            "http://example.test".to_string(),
            Probe {
                table_prefix: "wp_".to_string(),
                ..Probe::default()
            },
        );
        manifest.remote_metadata_cache_ttl_secs = 3600;
        let rel = Path::new("wp-content/missing-plugin");

        let mut fs = CowFs::new(
            manifest.clone(),
            &paths,
            RemoteClient::new(manifest.clone(), None),
        );
        assert_eq!(
            fs.remote_stat(rel).unwrap_err().kind(),
            io::ErrorKind::NotFound
        );
        let ssh_lines_after_first = fs::read_to_string(&fake_ssh_log).unwrap().lines().count();

        let mut reloaded_fs =
            CowFs::new(manifest.clone(), &paths, RemoteClient::new(manifest, None));
        assert_eq!(
            reloaded_fs.remote_stat(rel).unwrap_err().kind(),
            io::ErrorKind::NotFound
        );
        assert_eq!(
            fs::read_to_string(&fake_ssh_log).unwrap().lines().count(),
            ssh_lines_after_first,
            "cached missing metadata should avoid repeated remote stats after restart"
        );

        match old_path {
            Some(value) => std::env::set_var("PATH", value),
            None => std::env::remove_var("PATH"),
        }
        match old_log {
            Some(value) => std::env::set_var("WPCOW_FAKE_SSH_LOG", value),
            None => std::env::remove_var("WPCOW_FAKE_SSH_LOG"),
        }
        match old_helper {
            Some(value) => std::env::set_var("WPCOW_REMOTE_FILE_HELPER", value),
            None => std::env::remove_var("WPCOW_REMOTE_FILE_HELPER"),
        }
    }

    #[test]
    fn legacy_opaque_runtime_markers_stay_transparent_by_default() {
        static ENV_LOCK: OnceLock<Mutex<()>> = OnceLock::new();
        let _guard = ENV_LOCK.get_or_init(|| Mutex::new(())).lock().unwrap();
        let old = std::env::var_os("WPCOW_ENABLE_PLUGINS");
        std::env::remove_var("WPCOW_ENABLE_PLUGINS");

        let temp = tempfile::tempdir().unwrap();
        let paths = crate::config::clone_paths(temp.path(), "example");
        ensure_clone_dirs(&paths).unwrap();
        for rel in ["wp-content/plugins", "wp-content/languages"] {
            let dir = paths.upper.join(rel);
            fs::create_dir_all(&dir).unwrap();
            fs::write(dir.join(crate::overlay::OPAQUE_MARKER), b"legacy marker\n").unwrap();
        }

        let manifest = test_manifest();
        let fs = CowFs::new(manifest.clone(), &paths, RemoteClient::new(manifest, None));
        assert!(!fs
            .is_opaque_dir_active(Path::new("wp-content/plugins"))
            .unwrap());
        assert!(!fs
            .is_opaque_dir_active(Path::new("wp-content/languages"))
            .unwrap());

        std::env::set_var("WPCOW_ENABLE_PLUGINS", "0");
        assert!(fs
            .is_opaque_dir_active(Path::new("wp-content/plugins"))
            .unwrap());
        assert!(!fs
            .is_opaque_dir_active(Path::new("wp-content/languages"))
            .unwrap());

        match old {
            Some(value) => std::env::set_var("WPCOW_ENABLE_PLUGINS", value),
            None => std::env::remove_var("WPCOW_ENABLE_PLUGINS"),
        }
    }
}
