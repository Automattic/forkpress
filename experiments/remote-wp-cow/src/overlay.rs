use anyhow::{anyhow, Context, Result};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::cell::RefCell;
use std::collections::{BTreeMap, BTreeSet};
use std::fs::{self, File, OpenOptions};
use std::io::{Read, Seek, SeekFrom, Write};
use std::path::{Component, Path, PathBuf};
use std::time::{SystemTime, UNIX_EPOCH};

use crate::config::ClonePaths;
use crate::remote::{RemoteClient, RemoteEntry};

pub const OPAQUE_MARKER: &str = ".wp-cow-opaque";

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
struct WhiteoutFile {
    deleted: BTreeSet<String>,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
struct MetadataFile {
    entries: BTreeMap<String, RemoteEntry>,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
struct MissingFile {
    expires_at_unix: BTreeMap<String, u64>,
}

#[derive(Debug, Default, Serialize, Deserialize)]
struct CacheProgress {
    phase: String,
    active_path: String,
    active_bytes: u64,
    active_total: u64,
    files_cached: u64,
    bytes_cached: u64,
    last_cached_path: String,
    updated_at_unix_ms: u128,
}

#[derive(Debug, Clone)]
pub struct OverlayStore {
    pub upper: PathBuf,
    pub file_cache: PathBuf,
    whiteouts_path: PathBuf,
    whiteouts: RefCell<Option<WhiteoutFile>>,
    metadata: RefCell<Option<MetadataFile>>,
    metadata_journal_len: RefCell<u64>,
    missing: RefCell<Option<MissingFile>>,
}

impl OverlayStore {
    pub fn new(paths: &ClonePaths) -> Self {
        Self {
            upper: paths.upper.clone(),
            file_cache: paths.file_cache.clone(),
            whiteouts_path: paths.whiteouts.clone(),
            whiteouts: RefCell::new(None),
            metadata: RefCell::new(None),
            metadata_journal_len: RefCell::new(0),
            missing: RefCell::new(None),
        }
    }

    pub fn clean_rel(input: impl AsRef<Path>) -> Result<PathBuf> {
        let mut out = PathBuf::new();
        for component in input.as_ref().components() {
            match component {
                Component::Normal(part) => out.push(part),
                Component::CurDir => {}
                Component::RootDir | Component::Prefix(_) | Component::ParentDir => {
                    return Err(anyhow!(
                        "invalid clone-relative path {}",
                        input.as_ref().display()
                    ));
                }
            }
        }
        Ok(out)
    }

    pub fn rel_string(rel: &Path) -> String {
        rel.to_string_lossy().replace('\\', "/")
    }

    pub fn upper_path(&self, rel: &Path) -> Result<PathBuf> {
        Ok(self.upper.join(Self::clean_rel(rel)?))
    }

    pub fn mirror_path(&self, rel: &Path) -> Result<PathBuf> {
        Ok(self.file_cache.join("mirror").join(Self::clean_rel(rel)?))
    }

    pub fn cache_path(&self, rel: &Path) -> PathBuf {
        let mut hasher = Sha256::new();
        hasher.update(Self::rel_string(rel));
        let hex = hex::encode(hasher.finalize());
        self.file_cache.join(&hex[0..2]).join(hex)
    }

    pub fn cached_file_path(&self, rel: &Path) -> Option<PathBuf> {
        let path = self.cache_path(rel);
        if path.is_file() {
            return Some(path);
        }
        self.mirror_path(rel).ok().filter(|path| path.is_file())
    }

    pub fn cached_entry(&self, rel: &Path) -> Result<Option<RemoteEntry>> {
        let metadata = self.load_metadata()?;
        Ok(metadata.entries.get(&Self::rel_string(rel)).cloned())
    }

    pub fn cached_missing(&self, rel: &Path) -> Result<bool> {
        let mut missing = self.load_missing()?;
        let rel_string = Self::rel_string(&Self::clean_rel(rel)?);
        let now = now_unix_secs();
        let Some(expires_at) = missing.expires_at_unix.get(&rel_string).copied() else {
            return Ok(false);
        };
        if expires_at > now {
            return Ok(true);
        }
        missing.expires_at_unix.remove(&rel_string);
        self.write_missing(&missing)?;
        Ok(false)
    }

    pub fn put_cached_missing(&self, rel: &Path, ttl_secs: u64) -> Result<()> {
        let mut missing = self.load_missing()?;
        let rel_string = Self::rel_string(&Self::clean_rel(rel)?);
        let expires_at = now_unix_secs().saturating_add(ttl_secs.max(1));
        missing.expires_at_unix.insert(rel_string, expires_at);
        self.write_missing(&missing)
    }

    pub fn remove_cached_missing(&self, rel: &Path) -> Result<()> {
        let mut missing = self.load_missing()?;
        let rel_string = Self::rel_string(&Self::clean_rel(rel)?);
        if missing.expires_at_unix.remove(&rel_string).is_some() {
            self.write_missing(&missing)?;
        }
        Ok(())
    }

    pub fn list_cached_metadata_dir(&self, rel: &Path) -> Result<Vec<RemoteEntry>> {
        let metadata = self.load_metadata()?;
        let rel = Self::clean_rel(rel)?;
        let mut out = Vec::new();

        for (entry_rel, entry) in metadata.entries {
            let entry_path = PathBuf::from(&entry_rel);
            let parent = entry_path.parent().unwrap_or_else(|| Path::new(""));
            if parent == rel {
                out.push(entry);
            }
        }

        out.sort_by(|a, b| a.name.cmp(&b.name));
        Ok(out)
    }

    pub fn put_cached_entry(&self, rel: &Path, entry: &RemoteEntry) -> Result<()> {
        let mut metadata = self.load_metadata()?;
        let rel = Self::clean_rel(rel)?;
        let mut journal_entries = Vec::new();
        let rel_string = Self::rel_string(&rel);
        let _ = self.remove_cached_missing(&rel);
        metadata.entries.insert(rel_string.clone(), entry.clone());
        journal_entries.push((rel_string, Some(entry.clone())));
        let mut current = rel.parent();
        while let Some(parent) = current {
            if parent.as_os_str().is_empty() {
                break;
            }
            let Some(name) = parent.file_name() else {
                break;
            };
            let parent_string = Self::rel_string(parent);
            if !metadata.entries.contains_key(&parent_string) {
                let parent_entry = RemoteEntry {
                    name: name.to_string_lossy().to_string(),
                    kind: "dir".to_string(),
                    size: 0,
                    mode: 0o40755,
                    mtime: entry.mtime,
                };
                metadata
                    .entries
                    .insert(parent_string.clone(), parent_entry.clone());
                journal_entries.push((parent_string, Some(parent_entry)));
            }
            current = parent.parent();
        }
        *self.metadata.borrow_mut() = Some(metadata);
        self.append_metadata_journal(&journal_entries)
    }

    pub fn put_cached_file_bytes(
        &self,
        rel: &Path,
        entry: &RemoteEntry,
        bytes: &[u8],
    ) -> Result<()> {
        self.put_cached_file_bytes_inner(rel, entry, bytes, true)
    }

    pub fn put_cached_file_bytes_without_progress(
        &self,
        rel: &Path,
        entry: &RemoteEntry,
        bytes: &[u8],
    ) -> Result<()> {
        self.put_cached_file_bytes_inner(rel, entry, bytes, false)
    }

    fn put_cached_file_bytes_inner(
        &self,
        rel: &Path,
        entry: &RemoteEntry,
        bytes: &[u8],
        update_progress: bool,
    ) -> Result<()> {
        if entry.kind != "file" {
            return self.put_cached_entry(rel, entry);
        }
        let rel = Self::clean_rel(rel)?;
        let rel_string = Self::rel_string(&rel);
        let actual_size = bytes.len() as u64;
        if actual_size != entry.size {
            return Err(anyhow!(
                "remote file changed while prefetching {}: stat size {}, read size {}",
                rel_string,
                entry.size,
                actual_size
            ));
        }

        let cache_path = self.cache_path(&rel);
        if !cache_path.exists() {
            if let Some(parent) = cache_path.parent() {
                fs::create_dir_all(parent)?;
            }
            let tmp = self.cache_tmp_path(&cache_path);
            let mut out = File::create(&tmp)?;
            out.write_all(bytes)?;
            drop(out);
            fs::rename(tmp, &cache_path)?;
            if update_progress {
                let _ = self.finish_cache_progress(&rel_string, entry.size);
            }
        }

        self.put_cached_entry(&rel, entry)
    }

    pub fn note_cache_fetch(
        &self,
        rel: &Path,
        phase: &str,
        active_bytes: u64,
        active_total: u64,
    ) -> Result<()> {
        self.write_cache_progress(
            &Self::rel_string(&Self::clean_rel(rel)?),
            phase,
            active_bytes,
            active_total,
        )
    }

    pub fn remove_cached(&self, rel: &Path) -> Result<()> {
        let path = self.cache_path(rel);
        if path.exists() {
            fs::remove_file(path)?;
        }
        let _ = self.remove_cached_missing(rel);
        let mut metadata = self.load_metadata()?;
        let rel_string = Self::rel_string(rel);
        metadata.entries.remove(&rel_string);
        *self.metadata.borrow_mut() = Some(metadata);
        self.append_metadata_journal(&[(rel_string, None)])
    }

    pub fn is_whiteout(&self, rel: &Path) -> Result<bool> {
        let whiteouts = self.load_whiteouts()?;
        Ok(whiteouts.deleted.contains(&Self::rel_string(rel)))
    }

    pub fn add_whiteout(&self, rel: &Path) -> Result<()> {
        let mut whiteouts = self.load_whiteouts()?;
        whiteouts.deleted.insert(Self::rel_string(rel));
        self.write_whiteouts(&whiteouts)
    }

    pub fn clear_whiteout(&self, rel: &Path) -> Result<()> {
        let mut whiteouts = self.load_whiteouts()?;
        whiteouts.deleted.remove(&Self::rel_string(rel));
        self.write_whiteouts(&whiteouts)
    }

    pub fn remove_upper(&self, rel: &Path) -> Result<()> {
        let path = self.upper_path(rel)?;
        if path.is_dir() {
            fs::remove_dir_all(path)?;
        } else if path.exists() {
            fs::remove_file(path)?;
        }
        Ok(())
    }

    pub fn copy_up(&self, remote: &RemoteClient, rel: &Path) -> Result<PathBuf> {
        let upper = self.upper_path(rel)?;
        if upper.exists() {
            return Ok(upper);
        }

        if let Some(parent) = upper.parent() {
            fs::create_dir_all(parent)?;
        }

        let entry = match self.cached_entry(rel)? {
            Some(entry) => entry,
            None => remote.stat(rel)?,
        };
        if entry.kind == "dir" {
            fs::create_dir_all(&upper)?;
            return Ok(upper);
        }
        if entry.kind != "file" {
            return Err(anyhow!(
                "copy-up only supports regular files and directories"
            ));
        }

        if let Some(cached) = self.cached_file_path(rel) {
            fs::copy(cached, &upper)?;
            return Ok(upper);
        }

        let mut out = File::create(&upper)?;
        let mut offset = 0_u64;
        let chunk = 1024 * 1024;
        while offset < entry.size {
            let wanted = chunk.min((entry.size - offset) as usize);
            let bytes = remote.read_range(rel, offset, wanted)?;
            if bytes.is_empty() {
                break;
            }
            out.write_all(&bytes)?;
            offset += bytes.len() as u64;
        }
        Ok(upper)
    }

    pub fn copy_up_cached_only(&self, rel: &Path) -> Result<PathBuf> {
        let upper = self.upper_path(rel)?;
        if upper.exists() {
            return Ok(upper);
        }

        if let Some(parent) = upper.parent() {
            fs::create_dir_all(parent)?;
        }

        if let Some(cached) = self.cached_file_path(rel) {
            fs::copy(cached, &upper)?;
            return Ok(upper);
        }

        let mirror = self.mirror_path(rel)?;
        if let Ok(metadata) = fs::symlink_metadata(&mirror) {
            if metadata.file_type().is_dir() {
                fs::create_dir_all(&upper)?;
                return Ok(upper);
            }
            if metadata.file_type().is_symlink() {
                let target = fs::read_link(&mirror)?;
                std::os::unix::fs::symlink(target, &upper)?;
                return Ok(upper);
            }
        }

        if let Some(entry) = self.cached_entry(rel)? {
            if entry.kind == "dir" {
                fs::create_dir_all(&upper)?;
                return Ok(upper);
            }
        }

        Err(anyhow!(
            "clone is severed and writable lower file is not cached locally: {}",
            Self::rel_string(rel)
        ))
    }

    #[cfg(test)]
    pub fn read_cached_or_remote(
        &self,
        remote: &RemoteClient,
        rel: &Path,
        offset: i64,
        size: u32,
        cache_limit: u64,
    ) -> Result<Vec<u8>> {
        self.read_cached_or_remote_with_entry(remote, rel, offset, size, cache_limit, None)
    }

    pub fn read_cached_or_remote_with_entry(
        &self,
        remote: &RemoteClient,
        rel: &Path,
        offset: i64,
        size: u32,
        cache_limit: u64,
        entry: Option<RemoteEntry>,
    ) -> Result<Vec<u8>> {
        if offset < 0 {
            return Ok(Vec::new());
        }

        let cache_path = self.cache_path(rel);
        if cache_path.exists() {
            return read_range_from_file(&cache_path, offset as u64, size as usize);
        }

        let entry = match entry {
            Some(entry) => entry,
            None => match self.cached_entry(rel)? {
                Some(entry) => entry,
                None => remote.stat(rel)?,
            },
        };
        if entry.kind == "file" && entry.size <= cache_limit {
            if let Some(parent) = cache_path.parent() {
                fs::create_dir_all(parent)?;
            }
            if cache_path.exists() {
                return read_range_from_file(&cache_path, offset as u64, size as usize);
            }
            let tmp = self.cache_tmp_path(&cache_path);
            let mut out = File::create(&tmp)?;
            let rel_string = Self::rel_string(rel);
            let _ = self.write_cache_progress(&rel_string, "fetching", 0, entry.size);
            let bytes = remote
                .read_file(rel)
                .with_context(|| format!("remote cache fetch {}", rel_string))?;
            let actual_size = bytes.len() as u64;
            if actual_size != entry.size {
                let _ = fs::remove_file(&tmp);
                return Err(anyhow!(
                    "remote file changed while caching {}: stat size {}, read size {}",
                    rel_string,
                    entry.size,
                    actual_size
                ));
            }
            out.write_all(&bytes)?;
            let _ = self.write_cache_progress(&rel_string, "fetching", actual_size, entry.size);
            drop(out);
            fs::rename(tmp, &cache_path)?;
            self.put_cached_entry(rel, &entry)?;
            let _ = self.finish_cache_progress(&rel_string, entry.size);
            return read_range_from_file(&cache_path, offset as u64, size as usize);
        }

        let _ = self.write_cache_progress(
            &Self::rel_string(rel),
            "streaming",
            offset as u64,
            offset as u64 + size as u64,
        );
        remote
            .read_range(rel, offset as u64, size as usize)
            .with_context(|| format!("remote read {}", Self::rel_string(rel)))
    }

    pub fn list_upper(&self, rel: &Path) -> Result<Vec<RemoteEntry>> {
        self.list_local_layer(&self.upper_path(rel)?)
    }

    pub fn list_mirror(&self, rel: &Path) -> Result<Vec<RemoteEntry>> {
        self.list_local_layer(&self.mirror_path(rel)?)
    }

    pub fn is_opaque_dir(&self, rel: &Path) -> Result<bool> {
        Ok(self.upper_path(rel)?.join(OPAQUE_MARKER).is_file())
    }

    fn list_local_layer(&self, path: &Path) -> Result<Vec<RemoteEntry>> {
        if !path.is_dir() {
            return Ok(Vec::new());
        }
        let mut out = Vec::new();
        for entry in fs::read_dir(path)? {
            let entry = entry?;
            if entry.file_name() == OPAQUE_MARKER {
                continue;
            }
            let metadata = fs::symlink_metadata(entry.path())?;
            let file_type = metadata.file_type();
            out.push(RemoteEntry {
                name: entry.file_name().to_string_lossy().to_string(),
                kind: if file_type.is_dir() {
                    "dir".to_string()
                } else if file_type.is_symlink() {
                    "symlink".to_string()
                } else {
                    "file".to_string()
                },
                size: metadata.len(),
                mode: metadata.mode(),
                mtime: metadata
                    .modified()
                    .ok()
                    .and_then(|t| t.duration_since(std::time::UNIX_EPOCH).ok())
                    .map(|d| d.as_secs())
                    .unwrap_or_default(),
            });
        }
        Ok(out)
    }

    fn load_whiteouts(&self) -> Result<WhiteoutFile> {
        if let Some(whiteouts) = self.whiteouts.borrow().as_ref() {
            return Ok(whiteouts.clone());
        }
        if !self.whiteouts_path.exists() {
            let whiteouts = WhiteoutFile::default();
            *self.whiteouts.borrow_mut() = Some(whiteouts.clone());
            return Ok(whiteouts);
        }
        let mut json = String::new();
        File::open(&self.whiteouts_path)?.read_to_string(&mut json)?;
        let whiteouts: WhiteoutFile = serde_json::from_str(&json)?;
        *self.whiteouts.borrow_mut() = Some(whiteouts.clone());
        Ok(whiteouts)
    }

    fn write_whiteouts(&self, whiteouts: &WhiteoutFile) -> Result<()> {
        if let Some(parent) = self.whiteouts_path.parent() {
            fs::create_dir_all(parent)?;
        }
        let json = serde_json::to_vec_pretty(whiteouts)?;
        let mut file = OpenOptions::new()
            .create(true)
            .truncate(true)
            .write(true)
            .open(&self.whiteouts_path)?;
        file.write_all(&json)?;
        file.write_all(b"\n")?;
        *self.whiteouts.borrow_mut() = Some(whiteouts.clone());
        Ok(())
    }

    fn metadata_path(&self) -> PathBuf {
        self.file_cache.join("metadata.json")
    }

    fn metadata_journal_path(&self) -> PathBuf {
        self.file_cache.join("metadata.jsonl")
    }

    fn missing_path(&self) -> PathBuf {
        self.file_cache.join("missing.json")
    }

    fn progress_path(&self) -> PathBuf {
        self.file_cache.join("progress.json")
    }

    fn load_metadata(&self) -> Result<MetadataFile> {
        let journal_len = self.metadata_journal_len_on_disk();
        let cached_metadata = { self.metadata.borrow().clone() };
        if let Some(metadata) = cached_metadata {
            if *self.metadata_journal_len.borrow() == journal_len {
                return Ok(metadata);
            }
            let mut metadata = metadata;
            self.apply_metadata_journal(&mut metadata)?;
            *self.metadata.borrow_mut() = Some(metadata.clone());
            *self.metadata_journal_len.borrow_mut() = journal_len;
            return Ok(metadata.clone());
        }
        let path = self.metadata_path();
        if !path.exists() {
            let mut metadata = MetadataFile::default();
            self.apply_metadata_journal(&mut metadata)?;
            *self.metadata.borrow_mut() = Some(metadata.clone());
            *self.metadata_journal_len.borrow_mut() = journal_len;
            return Ok(metadata);
        }
        let mut json = String::new();
        File::open(path)?.read_to_string(&mut json)?;
        let mut metadata: MetadataFile = serde_json::from_str(&json)?;
        self.apply_metadata_journal(&mut metadata)?;
        *self.metadata.borrow_mut() = Some(metadata.clone());
        *self.metadata_journal_len.borrow_mut() = journal_len;
        Ok(metadata)
    }

    fn load_missing(&self) -> Result<MissingFile> {
        if let Some(missing) = self.missing.borrow().as_ref() {
            return Ok(missing.clone());
        }
        let path = self.missing_path();
        if !path.exists() {
            let missing = MissingFile::default();
            *self.missing.borrow_mut() = Some(missing.clone());
            return Ok(missing);
        }
        let mut json = String::new();
        File::open(path)?.read_to_string(&mut json)?;
        let missing: MissingFile = serde_json::from_str(&json)?;
        *self.missing.borrow_mut() = Some(missing.clone());
        Ok(missing)
    }

    fn write_missing(&self, missing: &MissingFile) -> Result<()> {
        fs::create_dir_all(&self.file_cache)?;
        let json = serde_json::to_vec_pretty(missing)?;
        let tmp = self.missing_path().with_extension("json.tmp");
        let mut file = OpenOptions::new()
            .create(true)
            .truncate(true)
            .write(true)
            .open(&tmp)?;
        file.write_all(&json)?;
        file.write_all(b"\n")?;
        drop(file);
        fs::rename(tmp, self.missing_path())?;
        *self.missing.borrow_mut() = Some(missing.clone());
        Ok(())
    }

    #[allow(dead_code)]
    fn write_metadata(&self, metadata: &MetadataFile) -> Result<()> {
        fs::create_dir_all(&self.file_cache)?;
        let json = serde_json::to_vec_pretty(metadata)?;
        let tmp = self.metadata_path().with_extension("json.tmp");
        let mut file = OpenOptions::new()
            .create(true)
            .truncate(true)
            .write(true)
            .open(&tmp)?;
        file.write_all(&json)?;
        file.write_all(b"\n")?;
        drop(file);
        fs::rename(tmp, self.metadata_path())?;
        let _ = fs::remove_file(self.metadata_journal_path());
        *self.metadata.borrow_mut() = Some(metadata.clone());
        *self.metadata_journal_len.borrow_mut() = 0;
        Ok(())
    }

    fn metadata_journal_len_on_disk(&self) -> u64 {
        fs::metadata(self.metadata_journal_path())
            .map(|metadata| metadata.len())
            .unwrap_or(0)
    }

    fn apply_metadata_journal(&self, metadata: &mut MetadataFile) -> Result<()> {
        let path = self.metadata_journal_path();
        if !path.exists() {
            return Ok(());
        }

        let mut jsonl = String::new();
        File::open(path)?.read_to_string(&mut jsonl)?;
        for line in jsonl.lines().filter(|line| !line.trim().is_empty()) {
            let value: serde_json::Value = serde_json::from_str(line)?;
            let Some(path) = value.get("path").and_then(|value| value.as_str()) else {
                continue;
            };
            match value.get("op").and_then(|value| value.as_str()) {
                Some("put") => {
                    let Some(entry) = value.get("entry") else {
                        continue;
                    };
                    metadata
                        .entries
                        .insert(path.to_string(), serde_json::from_value(entry.clone())?);
                }
                Some("delete") => {
                    metadata.entries.remove(path);
                }
                _ => {}
            }
        }
        Ok(())
    }

    fn append_metadata_journal(&self, entries: &[(String, Option<RemoteEntry>)]) -> Result<()> {
        if entries.is_empty() {
            return Ok(());
        }

        fs::create_dir_all(&self.file_cache)?;
        let mut file = OpenOptions::new()
            .create(true)
            .append(true)
            .open(self.metadata_journal_path())?;
        for (path, entry) in entries {
            let value = match entry {
                Some(entry) => {
                    serde_json::json!({ "op": "put", "path": path, "entry": entry })
                }
                None => serde_json::json!({ "op": "delete", "path": path }),
            };
            serde_json::to_writer(&mut file, &value)?;
            file.write_all(b"\n")?;
        }
        drop(file);
        *self.metadata_journal_len.borrow_mut() = self.metadata_journal_len_on_disk();
        Ok(())
    }

    fn load_progress(&self) -> Result<CacheProgress> {
        let path = self.progress_path();
        if !path.exists() {
            return Ok(CacheProgress {
                phase: "idle".to_string(),
                updated_at_unix_ms: now_unix_ms(),
                ..CacheProgress::default()
            });
        }
        let mut json = String::new();
        File::open(path)?.read_to_string(&mut json)?;
        Ok(serde_json::from_str(&json)?)
    }

    fn write_progress(&self, progress: &CacheProgress) -> Result<()> {
        fs::create_dir_all(&self.file_cache)?;
        let json = serde_json::to_vec_pretty(progress)?;
        let tmp = self.progress_tmp_path();
        let mut file = OpenOptions::new()
            .create(true)
            .truncate(true)
            .write(true)
            .open(&tmp)?;
        file.write_all(&json)?;
        file.write_all(b"\n")?;
        drop(file);
        fs::rename(tmp, self.progress_path())?;
        Ok(())
    }

    fn progress_tmp_path(&self) -> PathBuf {
        self.file_cache.join(format!(
            "progress.json.tmp.{}.{}",
            std::process::id(),
            now_unix_ms()
        ))
    }

    fn cache_tmp_path(&self, cache_path: &Path) -> PathBuf {
        let name = cache_path
            .file_name()
            .and_then(|name| name.to_str())
            .unwrap_or("remote-file");
        cache_path.with_file_name(format!(
            "{}.tmp.{}.{}",
            name,
            std::process::id(),
            now_unix_ms()
        ))
    }

    fn write_cache_progress(
        &self,
        rel: &str,
        phase: &str,
        active_bytes: u64,
        active_total: u64,
    ) -> Result<()> {
        let mut progress = self.load_progress()?;
        progress.phase = phase.to_string();
        progress.active_path = rel.to_string();
        progress.active_bytes = active_bytes;
        progress.active_total = active_total;
        progress.updated_at_unix_ms = now_unix_ms();
        self.write_progress(&progress)
    }

    fn finish_cache_progress(&self, rel: &str, size: u64) -> Result<()> {
        let mut progress = self.load_progress()?;
        progress.phase = "cached".to_string();
        progress.active_path.clear();
        progress.active_bytes = 0;
        progress.active_total = 0;
        progress.files_cached = progress.files_cached.saturating_add(1);
        progress.bytes_cached = progress.bytes_cached.saturating_add(size);
        progress.last_cached_path = rel.to_string();
        progress.updated_at_unix_ms = now_unix_ms();
        self.write_progress(&progress)
    }
}

fn now_unix_ms() -> u128 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|duration| duration.as_millis())
        .unwrap_or_default()
}

fn now_unix_secs() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|duration| duration.as_secs())
        .unwrap_or_default()
}

fn read_range_from_file(path: &Path, offset: u64, size: usize) -> Result<Vec<u8>> {
    let mut file = File::open(path)?;
    file.seek(SeekFrom::Start(offset))?;
    let mut buf = vec![0; size];
    let read = file.read(&mut buf)?;
    buf.truncate(read);
    Ok(buf)
}

#[cfg(unix)]
trait MetadataMode {
    fn mode(&self) -> u32;
}

#[cfg(unix)]
impl MetadataMode for std::fs::Metadata {
    fn mode(&self) -> u32 {
        use std::os::unix::fs::MetadataExt;
        MetadataExt::mode(self)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::{ensure_clone_dirs, ClonePaths, Manifest, Probe};
    use crate::remote::RemoteClient;
    use std::os::unix::fs::PermissionsExt;
    use std::sync::{Mutex, OnceLock};

    static ENV_LOCK: OnceLock<Mutex<()>> = OnceLock::new();

    #[test]
    fn stores_whiteouts() {
        let temp = tempfile::tempdir().unwrap();
        let paths = ClonePaths {
            root: temp.path().to_path_buf(),
            manifest: temp.path().join("manifest.json"),
            upper: temp.path().join("upper"),
            file_cache: temp.path().join("file-cache"),
            db: temp.path().join("db"),
            generated: temp.path().join("generated"),
            run: temp.path().join("run"),
            whiteouts: temp.path().join("whiteouts.json"),
        };
        fs::create_dir_all(&paths.upper).unwrap();
        let store = OverlayStore::new(&paths);
        let rel = Path::new("wp-content/uploads/a.jpg");
        assert!(!store.is_whiteout(rel).unwrap());
        store.add_whiteout(rel).unwrap();
        assert!(store.is_whiteout(rel).unwrap());
        store.clear_whiteout(rel).unwrap();
        assert!(!store.is_whiteout(rel).unwrap());
    }

    #[test]
    fn rejects_path_traversal() {
        assert!(OverlayStore::clean_rel("../wp-config.php").is_err());
        assert!(OverlayStore::clean_rel("/wp-config.php").is_err());
        assert_eq!(
            OverlayStore::clean_rel("./wp-config.php").unwrap(),
            PathBuf::from("wp-config.php")
        );
    }

    #[test]
    fn stores_cached_remote_metadata() {
        let temp = tempfile::tempdir().unwrap();
        let paths = ClonePaths {
            root: temp.path().to_path_buf(),
            manifest: temp.path().join("manifest.json"),
            upper: temp.path().join("upper"),
            file_cache: temp.path().join("file-cache"),
            db: temp.path().join("db"),
            generated: temp.path().join("generated"),
            run: temp.path().join("run"),
            whiteouts: temp.path().join("whiteouts.json"),
        };
        let store = OverlayStore::new(&paths);
        let rel = Path::new("wp-includes/version.php");
        let entry = RemoteEntry {
            name: "version.php".to_string(),
            kind: "file".to_string(),
            size: 123,
            mode: 0o100644,
            mtime: 42,
        };

        store.put_cached_entry(rel, &entry).unwrap();
        assert_eq!(store.cached_entry(rel).unwrap().unwrap().size, 123);
        let reloaded = OverlayStore::new(&paths);
        assert_eq!(
            reloaded.cached_entry(rel).unwrap().unwrap().size,
            123,
            "metadata journal must be enough to rebuild cached entries after restart"
        );
        assert_eq!(
            store
                .cached_entry(Path::new("wp-includes"))
                .unwrap()
                .unwrap()
                .kind,
            "dir",
            "offline lookups need cached parent directory metadata"
        );
        store.remove_cached(rel).unwrap();
        assert!(store.cached_entry(rel).unwrap().is_none());
        let reloaded = OverlayStore::new(&paths);
        assert!(reloaded.cached_entry(rel).unwrap().is_none());
    }

    #[test]
    fn cached_metadata_refreshes_when_another_overlay_appends_journal() {
        let temp = tempfile::tempdir().unwrap();
        let paths = ClonePaths {
            root: temp.path().to_path_buf(),
            manifest: temp.path().join("manifest.json"),
            upper: temp.path().join("upper"),
            file_cache: temp.path().join("file-cache"),
            db: temp.path().join("db"),
            generated: temp.path().join("generated"),
            run: temp.path().join("run"),
            whiteouts: temp.path().join("whiteouts.json"),
        };
        let mounted_view = OverlayStore::new(&paths);
        assert!(mounted_view
            .cached_entry(Path::new("wp-includes/load.php"))
            .unwrap()
            .is_none());

        let pack_writer = OverlayStore::new(&paths);
        let entry = RemoteEntry {
            name: "load.php".to_string(),
            kind: "file".to_string(),
            size: 12,
            mode: 0o100644,
            mtime: 123,
        };
        pack_writer
            .put_cached_file_bytes_without_progress(
                Path::new("wp-includes/load.php"),
                &entry,
                b"<?php // ok\n",
            )
            .unwrap();

        let loaded = mounted_view
            .cached_entry(Path::new("wp-includes/load.php"))
            .unwrap()
            .unwrap();
        assert_eq!(loaded.name, "load.php");
        assert_eq!(loaded.size, 12);
    }

    #[test]
    fn stores_cached_remote_missing_paths() {
        let temp = tempfile::tempdir().unwrap();
        let paths = crate::config::clone_paths(temp.path(), "example");
        ensure_clone_dirs(&paths).unwrap();
        let store = OverlayStore::new(&paths);
        let rel = Path::new("wp-content/missing-plugin");

        assert!(!store.cached_missing(rel).unwrap());
        store.put_cached_missing(rel, 3600).unwrap();
        assert!(store.cached_missing(rel).unwrap());

        let reloaded = OverlayStore::new(&paths);
        assert!(
            reloaded.cached_missing(rel).unwrap(),
            "negative remote metadata should survive daemon restarts"
        );

        store.remove_cached(rel).unwrap();
        assert!(!store.cached_missing(rel).unwrap());
    }

    #[test]
    fn cached_only_copy_up_uses_materialized_files_without_remote() {
        let temp = tempfile::tempdir().unwrap();
        let paths = crate::config::clone_paths(temp.path(), "example");
        ensure_clone_dirs(&paths).unwrap();
        let store = OverlayStore::new(&paths);

        let rel = Path::new("wp-includes/version.php");
        let entry = RemoteEntry {
            name: "version.php".to_string(),
            kind: "file".to_string(),
            size: 15,
            mode: 0o100644,
            mtime: 42,
        };
        let cache_path = store.cache_path(rel);
        fs::create_dir_all(cache_path.parent().unwrap()).unwrap();
        fs::write(&cache_path, b"cached runtime\n").unwrap();
        store.put_cached_entry(rel, &entry).unwrap();

        let upper = store.copy_up_cached_only(rel).unwrap();
        assert_eq!(fs::read(&upper).unwrap(), b"cached runtime\n");

        let mirror_rel = Path::new("wp-admin/index.php");
        let mirror_path = store.mirror_path(mirror_rel).unwrap();
        fs::create_dir_all(mirror_path.parent().unwrap()).unwrap();
        fs::write(&mirror_path, b"mirror runtime\n").unwrap();
        let upper = store.copy_up_cached_only(mirror_rel).unwrap();
        assert_eq!(fs::read(&upper).unwrap(), b"mirror runtime\n");

        let dir_rel = Path::new("wp-content/themes/example");
        store
            .put_cached_entry(
                dir_rel,
                &RemoteEntry {
                    name: "example".to_string(),
                    kind: "dir".to_string(),
                    size: 0,
                    mode: 0o40755,
                    mtime: 42,
                },
            )
            .unwrap();
        assert!(store.copy_up_cached_only(dir_rel).unwrap().is_dir());

        let err = store
            .copy_up_cached_only(Path::new("wp-content/missing.php"))
            .unwrap_err()
            .to_string();
        assert!(
            err.contains("clone is severed and writable lower file is not cached locally"),
            "unexpected error: {err}"
        );
    }

    #[test]
    fn lazy_remote_file_is_cached_and_survives_remote_loss() {
        let _guard = ENV_LOCK.get_or_init(|| Mutex::new(())).lock().unwrap();

        let old_path = std::env::var_os("PATH");
        let old_log = std::env::var_os("WPCOW_FAKE_SSH_LOG");

        let temp = tempfile::tempdir().unwrap();
        let remote_root = temp.path().join("remote");
        let bin = temp.path().join("bin");
        let log = temp.path().join("ssh.log");
        fs::create_dir_all(&remote_root).unwrap();
        fs::create_dir_all(&bin).unwrap();
        fs::write(remote_root.join("index.php"), b"remote wordpress").unwrap();

        let fake_ssh = bin.join("ssh");
        fs::write(
            &fake_ssh,
            r#"#!/usr/bin/env bash
set -euo pipefail
printf 'CALL\n' >> "$WPCOW_FAKE_SSH_LOG"
cmd="${@: -1}"
exec bash -lc "$cmd"
"#,
        )
        .unwrap();
        let mut perms = fs::metadata(&fake_ssh).unwrap().permissions();
        perms.set_mode(0o755);
        fs::set_permissions(&fake_ssh, perms).unwrap();

        let path = match old_path.as_ref() {
            Some(old) => format!("{}:{}", bin.display(), old.to_string_lossy()),
            None => bin.display().to_string(),
        };
        std::env::set_var("PATH", path);
        std::env::set_var("WPCOW_FAKE_SSH_LOG", &log);

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
        manifest.cache_max_file_bytes = 1024;
        let remote = RemoteClient::new(manifest, None);
        let store = OverlayStore::new(&paths);
        let rel = Path::new("index.php");

        let first = store
            .read_cached_or_remote(&remote, rel, 0, 1024, 1024)
            .unwrap();
        assert_eq!(first, b"remote wordpress");
        fs::remove_file(remote_root.join("index.php")).unwrap();
        let ssh_after_first = fs::read_to_string(&log).unwrap().lines().count();

        let second = store
            .read_cached_or_remote(&remote, rel, 0, 1024, 1024)
            .unwrap();
        assert_eq!(second, b"remote wordpress");
        let ssh_after_second = fs::read_to_string(&log).unwrap().lines().count();
        assert_eq!(
            ssh_after_second, ssh_after_first,
            "cached read must not invoke ssh after the remote file disappears"
        );

        match old_path {
            Some(value) => std::env::set_var("PATH", value),
            None => std::env::remove_var("PATH"),
        }
        match old_log {
            Some(value) => std::env::set_var("WPCOW_FAKE_SSH_LOG", value),
            None => std::env::remove_var("WPCOW_FAKE_SSH_LOG"),
        }
    }

    #[test]
    fn supplied_metadata_skips_remote_stat_before_caching_file() {
        let _guard = ENV_LOCK.get_or_init(|| Mutex::new(())).lock().unwrap();

        let old_path = std::env::var_os("PATH");
        let old_log = std::env::var_os("WPCOW_FAKE_SSH_LOG");
        let old_helper = std::env::var_os("WPCOW_REMOTE_FILE_HELPER");

        let temp = tempfile::tempdir().unwrap();
        let remote_root = temp.path().join("remote");
        let bin = temp.path().join("bin");
        let log = temp.path().join("ssh.log");
        fs::create_dir_all(&remote_root).unwrap();
        fs::create_dir_all(&bin).unwrap();
        fs::write(remote_root.join("index.php"), b"remote wordpress").unwrap();

        let fake_ssh = bin.join("ssh");
        fs::write(
            &fake_ssh,
            r#"#!/usr/bin/env bash
	set -euo pipefail
	printf 'CALL\n' >> "$WPCOW_FAKE_SSH_LOG"
	cmd="${@: -1}"
	exec bash -lc "$cmd"
	"#,
        )
        .unwrap();
        let mut perms = fs::metadata(&fake_ssh).unwrap().permissions();
        perms.set_mode(0o755);
        fs::set_permissions(&fake_ssh, perms).unwrap();

        let path = match old_path.as_ref() {
            Some(old) => format!("{}:{}", bin.display(), old.to_string_lossy()),
            None => bin.display().to_string(),
        };
        std::env::set_var("PATH", path);
        std::env::set_var("WPCOW_FAKE_SSH_LOG", &log);
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
        let remote = RemoteClient::new(manifest, None);
        let store = OverlayStore::new(&paths);
        let rel = Path::new("index.php");
        let entry = RemoteEntry {
            name: "index.php".to_string(),
            kind: "file".to_string(),
            size: 16,
            mode: 0o100644,
            mtime: 42,
        };

        let first = store
            .read_cached_or_remote_with_entry(&remote, rel, 0, 1024, 1024, Some(entry))
            .unwrap();
        assert_eq!(first, b"remote wordpress");
        let ssh_lines = fs::read_to_string(&log)
            .unwrap()
            .lines()
            .filter(|line| *line == "CALL")
            .count();
        assert_eq!(
            ssh_lines, 1,
            "FUSE lookup metadata should let the first read fetch file bytes without a second remote stat command"
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
    fn stat_prefetched_bytes_are_reused_without_remote_read() {
        let _guard = ENV_LOCK.get_or_init(|| Mutex::new(())).lock().unwrap();

        let old_path = std::env::var_os("PATH");
        let old_helper = std::env::var_os("WPCOW_REMOTE_FILE_HELPER");

        let temp = tempfile::tempdir().unwrap();
        let paths = crate::config::clone_paths(temp.path().join("state").as_path(), "example");
        ensure_clone_dirs(&paths).unwrap();
        let store = OverlayStore::new(&paths);
        let rel = Path::new("wp-content/themes/example/style.css");
        let entry = RemoteEntry {
            name: "style.css".to_string(),
            kind: "file".to_string(),
            size: 17,
            mode: 0o100644,
            mtime: 42,
        };
        store
            .put_cached_file_bytes(rel, &entry, b"body{color:black}")
            .unwrap();

        std::env::set_var("PATH", temp.path().join("missing-bin"));
        std::env::set_var("WPCOW_REMOTE_FILE_HELPER", "0");
        let remote = RemoteClient::new(
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
            ),
            None,
        );
        let bytes = store
            .read_cached_or_remote_with_entry(&remote, rel, 0, 1024, 1024, Some(entry.clone()))
            .unwrap();
        assert_eq!(bytes, b"body{color:black}");
        assert_eq!(store.cached_entry(rel).unwrap().unwrap().size, 17);

        match old_path {
            Some(value) => std::env::set_var("PATH", value),
            None => std::env::remove_var("PATH"),
        }
        match old_helper {
            Some(value) => std::env::set_var("WPCOW_REMOTE_FILE_HELPER", value),
            None => std::env::remove_var("WPCOW_REMOTE_FILE_HELPER"),
        }
    }
}
