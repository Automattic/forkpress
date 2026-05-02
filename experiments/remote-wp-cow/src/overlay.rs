use anyhow::{anyhow, Context, Result};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, BTreeSet};
use std::fs::{self, File, OpenOptions};
use std::io::{Read, Seek, SeekFrom, Write};
use std::path::{Component, Path, PathBuf};
use std::time::{SystemTime, UNIX_EPOCH};

use crate::config::ClonePaths;
use crate::remote::{RemoteClient, RemoteEntry};

#[derive(Debug, Default, Serialize, Deserialize)]
struct WhiteoutFile {
    deleted: BTreeSet<String>,
}

#[derive(Debug, Default, Serialize, Deserialize)]
struct MetadataFile {
    entries: BTreeMap<String, RemoteEntry>,
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
}

impl OverlayStore {
    pub fn new(paths: &ClonePaths) -> Self {
        Self {
            upper: paths.upper.clone(),
            file_cache: paths.file_cache.clone(),
            whiteouts_path: paths.whiteouts.clone(),
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

    pub fn cache_path(&self, rel: &Path) -> PathBuf {
        let mut hasher = Sha256::new();
        hasher.update(Self::rel_string(rel));
        let hex = hex::encode(hasher.finalize());
        self.file_cache.join(&hex[0..2]).join(hex)
    }

    pub fn cached_file_path(&self, rel: &Path) -> Option<PathBuf> {
        let path = self.cache_path(rel);
        path.is_file().then_some(path)
    }

    pub fn cached_entry(&self, rel: &Path) -> Result<Option<RemoteEntry>> {
        let metadata = self.load_metadata()?;
        Ok(metadata.entries.get(&Self::rel_string(rel)).cloned())
    }

    pub fn put_cached_entry(&self, rel: &Path, entry: &RemoteEntry) -> Result<()> {
        let mut metadata = self.load_metadata()?;
        metadata
            .entries
            .insert(Self::rel_string(rel), entry.clone());
        self.write_metadata(&metadata)
    }

    pub fn remove_cached(&self, rel: &Path) -> Result<()> {
        let path = self.cache_path(rel);
        if path.exists() {
            fs::remove_file(path)?;
        }
        let mut metadata = self.load_metadata()?;
        metadata.entries.remove(&Self::rel_string(rel));
        self.write_metadata(&metadata)
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

        let entry = remote.stat(rel)?;
        if entry.kind == "dir" {
            fs::create_dir_all(&upper)?;
            return Ok(upper);
        }
        if entry.kind != "file" {
            return Err(anyhow!(
                "copy-up only supports regular files and directories"
            ));
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

    pub fn read_cached_or_remote(
        &self,
        remote: &RemoteClient,
        rel: &Path,
        offset: i64,
        size: u32,
        cache_limit: u64,
    ) -> Result<Vec<u8>> {
        if offset < 0 {
            return Ok(Vec::new());
        }

        let cache_path = self.cache_path(rel);
        if cache_path.exists() {
            return read_range_from_file(&cache_path, offset as u64, size as usize);
        }

        let entry = remote.stat(rel)?;
        if entry.kind == "file" && entry.size <= cache_limit {
            if let Some(parent) = cache_path.parent() {
                fs::create_dir_all(parent)?;
            }
            let tmp = cache_path.with_extension("tmp");
            let mut out = File::create(&tmp)?;
            let mut cursor = 0_u64;
            let chunk = 1024 * 1024;
            let rel_string = Self::rel_string(rel);
            let _ = self.write_cache_progress(&rel_string, "fetching", 0, entry.size);
            while cursor < entry.size {
                let wanted = chunk.min((entry.size - cursor) as usize);
                let bytes = remote.read_range(rel, cursor, wanted)?;
                if bytes.is_empty() {
                    break;
                }
                out.write_all(&bytes)?;
                cursor += bytes.len() as u64;
                let _ = self.write_cache_progress(&rel_string, "fetching", cursor, entry.size);
            }
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
        let path = self.upper_path(rel)?;
        if !path.is_dir() {
            return Ok(Vec::new());
        }
        let mut out = Vec::new();
        for entry in fs::read_dir(path)? {
            let entry = entry?;
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
        if !self.whiteouts_path.exists() {
            return Ok(WhiteoutFile::default());
        }
        let mut json = String::new();
        File::open(&self.whiteouts_path)?.read_to_string(&mut json)?;
        Ok(serde_json::from_str(&json)?)
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
        Ok(())
    }

    fn metadata_path(&self) -> PathBuf {
        self.file_cache.join("metadata.json")
    }

    fn progress_path(&self) -> PathBuf {
        self.file_cache.join("progress.json")
    }

    fn load_metadata(&self) -> Result<MetadataFile> {
        let path = self.metadata_path();
        if !path.exists() {
            return Ok(MetadataFile::default());
        }
        let mut json = String::new();
        File::open(path)?.read_to_string(&mut json)?;
        Ok(serde_json::from_str(&json)?)
    }

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
    use crate::config::ClonePaths;

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
        store.remove_cached(rel).unwrap();
        assert!(store.cached_entry(rel).unwrap().is_none());
    }
}
