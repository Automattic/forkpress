use anyhow::{Context, Result, anyhow, bail};
use redb::{Database, ReadableDatabase, TableDefinition};
use sha2::{Digest, Sha256};
use std::fs::{self, File};
use std::io::Read;
use std::path::{Path, PathBuf};

const BLOBS: TableDefinition<&str, &[u8]> = TableDefinition::new("blobs");
const BRANCHES: TableDefinition<&str, &str> = TableDefinition::new("branches");
const MANIFEST_VERSION: &str = "forkpress-cas-manifest-v1";

#[derive(Debug, Clone, PartialEq, Eq)]
enum ManifestEntry {
    Dir {
        path: String,
    },
    File {
        path: String,
        hash: String,
        len: u64,
    },
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct SnapshotReport {
    pub files: usize,
    pub dirs: usize,
    pub bytes: u64,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CasFileStat {
    pub is_dir: bool,
    pub len: u64,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CasDirEntry {
    pub name: String,
    pub is_dir: bool,
}

pub fn snapshot_branch(
    store_path: &Path,
    branch_root: &Path,
    branch: &str,
) -> Result<SnapshotReport> {
    if !branch_root.is_dir() {
        bail!("branch root not found: {}", branch_root.display());
    }

    let db = open_database(store_path)?;
    let write = db.begin_write().context("failed to start CAS write")?;
    let mut entries = Vec::new();
    let mut report = SnapshotReport {
        files: 0,
        dirs: 0,
        bytes: 0,
    };

    {
        let mut blobs = write
            .open_table(BLOBS)
            .context("failed to open CAS blobs")?;
        for path in sorted_tree(branch_root)? {
            let rel = rel_path(branch_root, &path)?;
            let metadata = fs::metadata(&path)
                .with_context(|| format!("failed to stat {}", path.display()))?;
            if metadata.is_dir() {
                if !rel.is_empty() {
                    entries.push(ManifestEntry::Dir { path: rel });
                    report.dirs += 1;
                }
                continue;
            }
            if !metadata.is_file() {
                continue;
            }

            let data = read_file(&path)?;
            let hash = sha256_hex(&data);
            blobs
                .insert(hash.as_str(), data.as_slice())
                .with_context(|| format!("failed to write CAS blob for {}", path.display()))?;
            report.files += 1;
            report.bytes += data.len() as u64;
            entries.push(ManifestEntry::File {
                path: rel,
                hash,
                len: data.len() as u64,
            });
        }
    }

    let manifest = serialize_manifest(&entries);
    {
        let mut branches = write
            .open_table(BRANCHES)
            .context("failed to open CAS branches")?;
        branches
            .insert(branch, manifest.as_str())
            .with_context(|| format!("failed to write CAS branch manifest for {branch}"))?;
    }
    write.commit().context("failed to commit CAS snapshot")?;
    Ok(report)
}

pub fn branch_exists(store_path: &Path, branch: &str) -> Result<bool> {
    let db = open_database(store_path)?;
    let read = db.begin_read().context("failed to start CAS read")?;
    let Ok(branches) = read.open_table(BRANCHES) else {
        return Ok(false);
    };
    Ok(branches
        .get(branch)
        .with_context(|| format!("failed to read CAS branch {branch}"))?
        .is_some())
}

pub fn create_empty_branch(store_path: &Path, branch: &str) -> Result<SnapshotReport> {
    let db = open_database(store_path)?;
    let manifest = serialize_manifest(&[]);
    let write = db.begin_write().context("failed to start CAS write")?;
    {
        let mut branches = write
            .open_table(BRANCHES)
            .context("failed to open CAS branches")?;
        branches
            .insert(branch, manifest.as_str())
            .with_context(|| format!("failed to write CAS branch manifest for {branch}"))?;
    }
    write
        .commit()
        .context("failed to commit CAS branch creation")?;
    Ok(SnapshotReport {
        files: 0,
        dirs: 0,
        bytes: 0,
    })
}

pub fn clone_branch_manifest(
    store_path: &Path,
    source_branch: &str,
    new_branch: &str,
) -> Result<SnapshotReport> {
    let db = open_database(store_path)?;
    let manifest = read_branch_manifest_string(&db, source_branch)?;
    let entries = parse_manifest(&manifest)?;

    let write = db.begin_write().context("failed to start CAS write")?;
    {
        let mut branches = write
            .open_table(BRANCHES)
            .context("failed to open CAS branches")?;
        branches
            .insert(new_branch, manifest.as_str())
            .with_context(|| format!("failed to write CAS branch manifest for {new_branch}"))?;
    }
    write
        .commit()
        .context("failed to commit CAS branch creation")?;

    Ok(report_for_entries(&entries))
}

pub fn read_file_from_branch(store_path: &Path, branch: &str, path: &str) -> Result<Vec<u8>> {
    let path = normalize_store_path(path);
    let db = open_database(store_path)?;
    let entries = read_branch_manifest(&db, branch)?;
    let Some(ManifestEntry::File { hash, len, .. }) = find_entry(&entries, &path) else {
        bail!("CAS file not found: {branch}:{path}");
    };

    let read = db.begin_read().context("failed to start CAS read")?;
    let blobs = read.open_table(BLOBS).context("failed to open CAS blobs")?;
    let Some(blob) = blobs
        .get(hash.as_str())
        .with_context(|| format!("failed to read CAS blob {hash}"))?
    else {
        bail!("CAS blob missing: {hash}");
    };
    let data = blob.value().to_vec();
    if data.len() as u64 != *len {
        bail!(
            "CAS blob {hash} length mismatch: manifest has {len}, store has {}",
            data.len()
        );
    }
    Ok(data)
}

pub fn write_file_to_branch(
    store_path: &Path,
    branch: &str,
    path: &str,
    data: &[u8],
) -> Result<SnapshotReport> {
    let path = normalize_store_path(path);
    let db = open_database(store_path)?;
    let mut entries = read_branch_manifest(&db, branch).unwrap_or_default();
    add_parent_dirs(&mut entries, &path);
    remove_path_and_children(&mut entries, &path);

    let hash = sha256_hex(data);
    entries.push(ManifestEntry::File {
        path,
        hash: hash.clone(),
        len: data.len() as u64,
    });

    let manifest = serialize_manifest(&entries);
    let write = db.begin_write().context("failed to start CAS write")?;
    {
        let mut blobs = write
            .open_table(BLOBS)
            .context("failed to open CAS blobs")?;
        blobs
            .insert(hash.as_str(), data)
            .context("failed to write CAS blob")?;
    }
    {
        let mut branches = write
            .open_table(BRANCHES)
            .context("failed to open CAS branches")?;
        branches
            .insert(branch, manifest.as_str())
            .with_context(|| format!("failed to write CAS branch manifest for {branch}"))?;
    }
    write.commit().context("failed to commit CAS write")?;
    Ok(report_for_entries(&entries))
}

pub fn mkdir_in_branch(store_path: &Path, branch: &str, path: &str) -> Result<SnapshotReport> {
    let path = normalize_store_path(path);
    let db = open_database(store_path)?;
    let mut entries = read_branch_manifest(&db, branch).unwrap_or_default();
    if !path.is_empty() {
        add_parent_dirs(&mut entries, &path);
        if !matches!(find_entry(&entries, &path), Some(ManifestEntry::Dir { .. })) {
            remove_exact_path(&mut entries, &path);
            entries.push(ManifestEntry::Dir { path });
        }
    }
    write_entries(&db, branch, &entries)?;
    Ok(report_for_entries(&entries))
}

pub fn unlink_path(store_path: &Path, branch: &str, path: &str) -> Result<SnapshotReport> {
    let path = normalize_store_path(path);
    let db = open_database(store_path)?;
    let mut entries = read_branch_manifest(&db, branch)?;
    remove_path_and_children(&mut entries, &path);
    write_entries(&db, branch, &entries)?;
    Ok(report_for_entries(&entries))
}

pub fn rename_path(
    store_path: &Path,
    branch: &str,
    from: &str,
    to: &str,
) -> Result<SnapshotReport> {
    let from = normalize_store_path(from);
    let to = normalize_store_path(to);
    let db = open_database(store_path)?;
    let mut entries = read_branch_manifest(&db, branch)?;
    let Some(entry) = find_entry(&entries, &from).cloned() else {
        bail!("CAS path not found: {branch}:{from}");
    };
    add_parent_dirs(&mut entries, &to);
    match entry {
        ManifestEntry::Dir { .. } => {
            let child_prefix = format!("{from}/");
            let mut renamed = Vec::new();
            entries.retain(|entry| {
                let entry_path = manifest_path(entry);
                if entry_path == from {
                    renamed.push(ManifestEntry::Dir { path: to.clone() });
                    return false;
                }
                if let Some(suffix) = entry_path.strip_prefix(&child_prefix) {
                    let new_path = format!("{to}/{suffix}");
                    renamed.push(match entry {
                        ManifestEntry::Dir { .. } => ManifestEntry::Dir { path: new_path },
                        ManifestEntry::File { hash, len, .. } => ManifestEntry::File {
                            path: new_path,
                            hash: hash.clone(),
                            len: *len,
                        },
                    });
                    return false;
                }
                true
            });
            entries.extend(renamed);
        }
        ManifestEntry::File { hash, len, .. } => {
            remove_path_and_children(&mut entries, &from);
            entries.push(ManifestEntry::File {
                path: to,
                hash,
                len,
            });
        }
    }
    write_entries(&db, branch, &entries)?;
    Ok(report_for_entries(&entries))
}

pub fn stat_path(store_path: &Path, branch: &str, path: &str) -> Result<CasFileStat> {
    let path = normalize_store_path(path);
    let db = open_database(store_path)?;
    let entries = read_branch_manifest(&db, branch)?;
    if path.is_empty() {
        return Ok(CasFileStat {
            is_dir: true,
            len: 0,
        });
    }
    match find_entry(&entries, &path) {
        Some(ManifestEntry::Dir { .. }) => Ok(CasFileStat {
            is_dir: true,
            len: 0,
        }),
        Some(ManifestEntry::File { len, .. }) => Ok(CasFileStat {
            is_dir: false,
            len: *len,
        }),
        None => bail!("CAS path not found: {branch}:{path}"),
    }
}

pub fn list_dir(store_path: &Path, branch: &str, dir_path: &str) -> Result<Vec<CasDirEntry>> {
    let dir_path = normalize_store_path(dir_path);
    let db = open_database(store_path)?;
    let entries = read_branch_manifest(&db, branch)?;
    let prefix = if dir_path.is_empty() {
        String::new()
    } else {
        format!("{dir_path}/")
    };
    let mut out: Vec<CasDirEntry> = Vec::new();
    for entry in entries {
        let path = manifest_path(&entry);
        if !path.starts_with(&prefix) {
            continue;
        }
        let rest = &path[prefix.len()..];
        if rest.is_empty() || rest.contains('/') {
            continue;
        }
        let is_dir = matches!(entry, ManifestEntry::Dir { .. });
        if let Some(existing) = out.iter_mut().find(|item| item.name == rest) {
            existing.is_dir = existing.is_dir || is_dir;
            continue;
        }
        out.push(CasDirEntry {
            name: rest.to_string(),
            is_dir,
        });
    }
    out.sort_by(|a, b| a.name.cmp(&b.name));
    Ok(out)
}

fn read_branch_manifest_string(db: &Database, branch: &str) -> Result<String> {
    let read = db.begin_read().context("failed to start CAS read")?;
    let branches = read
        .open_table(BRANCHES)
        .context("failed to open CAS branches")?;
    let Some(value) = branches
        .get(branch)
        .with_context(|| format!("failed to read CAS branch {branch}"))?
    else {
        bail!("branch has no CAS manifest: {branch}");
    };
    Ok(value.value().to_string())
}

fn read_branch_manifest(db: &Database, branch: &str) -> Result<Vec<ManifestEntry>> {
    parse_manifest(&read_branch_manifest_string(db, branch)?)
}

fn write_entries(db: &Database, branch: &str, entries: &[ManifestEntry]) -> Result<()> {
    let manifest = serialize_manifest(entries);
    let write = db.begin_write().context("failed to start CAS write")?;
    {
        let mut branches = write
            .open_table(BRANCHES)
            .context("failed to open CAS branches")?;
        branches
            .insert(branch, manifest.as_str())
            .with_context(|| format!("failed to write CAS branch manifest for {branch}"))?;
    }
    write.commit().context("failed to commit CAS manifest")?;
    Ok(())
}

fn open_database(path: &Path) -> Result<Database> {
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent)
            .with_context(|| format!("failed to create {}", parent.display()))?;
    }
    if path.exists() {
        Database::open(path).with_context(|| format!("failed to open CAS store {}", path.display()))
    } else {
        Database::create(path)
            .with_context(|| format!("failed to create CAS store {}", path.display()))
    }
}

fn normalize_store_path(path: &str) -> String {
    let mut out: Vec<&str> = Vec::new();
    for part in path.split('/') {
        match part {
            "" | "." => {}
            ".." => {
                out.pop();
            }
            other => out.push(other),
        }
    }
    out.join("/")
}

fn find_entry<'a>(entries: &'a [ManifestEntry], path: &str) -> Option<&'a ManifestEntry> {
    entries.iter().find(|entry| manifest_path(entry) == path)
}

fn remove_exact_path(entries: &mut Vec<ManifestEntry>, path: &str) {
    entries.retain(|entry| manifest_path(entry) != path);
}

fn remove_path_and_children(entries: &mut Vec<ManifestEntry>, path: &str) {
    let child_prefix = format!("{path}/");
    entries.retain(|entry| {
        let entry_path = manifest_path(entry);
        entry_path != path && !entry_path.starts_with(&child_prefix)
    });
}

fn add_parent_dirs(entries: &mut Vec<ManifestEntry>, path: &str) {
    let mut current = String::new();
    let mut parts = path.split('/').peekable();
    while let Some(part) = parts.next() {
        if parts.peek().is_none() {
            break;
        }
        if !current.is_empty() {
            current.push('/');
        }
        current.push_str(part);
        if !matches!(
            find_entry(entries, &current),
            Some(ManifestEntry::Dir { .. })
        ) {
            remove_exact_path(entries, &current);
            entries.push(ManifestEntry::Dir {
                path: current.clone(),
            });
        }
    }
}

fn sorted_tree(root: &Path) -> Result<Vec<PathBuf>> {
    let mut paths = Vec::new();
    let mut stack = vec![root.to_path_buf()];
    while let Some(dir) = stack.pop() {
        let mut children = Vec::new();
        for entry in fs::read_dir(&dir)
            .with_context(|| format!("failed to read directory {}", dir.display()))?
        {
            let entry = entry?;
            children.push(entry.path());
        }
        children.sort();
        for child in children {
            if child.is_dir() {
                stack.push(child.clone());
            }
            paths.push(child);
        }
    }
    paths.sort();
    Ok(paths)
}

fn read_file(path: &Path) -> Result<Vec<u8>> {
    let mut file =
        File::open(path).with_context(|| format!("failed to open {}", path.display()))?;
    let mut data = Vec::new();
    file.read_to_end(&mut data)
        .with_context(|| format!("failed to read {}", path.display()))?;
    Ok(data)
}

fn sha256_hex(data: &[u8]) -> String {
    use std::fmt::Write as _;

    let digest = Sha256::digest(data);
    let mut out = String::with_capacity(digest.len() * 2);
    for byte in digest {
        let _ = write!(&mut out, "{byte:02x}");
    }
    out
}

fn rel_path(root: &Path, path: &Path) -> Result<String> {
    let rel = path
        .strip_prefix(root)
        .with_context(|| format!("{} is not under {}", path.display(), root.display()))?;
    Ok(rel
        .to_string_lossy()
        .replace(std::path::MAIN_SEPARATOR, "/"))
}

fn serialize_manifest(entries: &[ManifestEntry]) -> String {
    let mut entries = entries.to_vec();
    entries.sort_by(|a, b| manifest_path(a).cmp(manifest_path(b)));

    let mut out = String::new();
    out.push_str(MANIFEST_VERSION);
    out.push('\n');
    for entry in entries {
        match entry {
            ManifestEntry::Dir { path } => {
                out.push_str("D\t");
                out.push_str(&escape_field(&path));
                out.push('\n');
            }
            ManifestEntry::File { path, hash, len } => {
                out.push_str("F\t");
                out.push_str(&escape_field(&path));
                out.push('\t');
                out.push_str(&len.to_string());
                out.push('\t');
                out.push_str(&hash);
                out.push('\n');
            }
        }
    }
    out
}

fn parse_manifest(manifest: &str) -> Result<Vec<ManifestEntry>> {
    let mut lines = manifest.lines();
    let Some(version) = lines.next() else {
        bail!("empty CAS manifest");
    };
    if version != MANIFEST_VERSION {
        bail!("unsupported CAS manifest version: {version}");
    }

    let mut entries = Vec::new();
    for line in lines {
        if line.is_empty() {
            continue;
        }
        let fields: Vec<_> = line.split('\t').collect();
        match fields.as_slice() {
            ["D", path] => entries.push(ManifestEntry::Dir {
                path: unescape_field(path)?,
            }),
            ["F", path, len, hash] => entries.push(ManifestEntry::File {
                path: unescape_field(path)?,
                len: len
                    .parse()
                    .with_context(|| format!("invalid CAS manifest length: {len}"))?,
                hash: (*hash).to_string(),
            }),
            _ => bail!("invalid CAS manifest line: {line}"),
        }
    }
    Ok(entries)
}

fn report_for_entries(entries: &[ManifestEntry]) -> SnapshotReport {
    let mut report = SnapshotReport {
        files: 0,
        dirs: 0,
        bytes: 0,
    };
    for entry in entries {
        match entry {
            ManifestEntry::Dir { .. } => report.dirs += 1,
            ManifestEntry::File { len, .. } => {
                report.files += 1;
                report.bytes += *len;
            }
        }
    }
    report
}

fn manifest_path(entry: &ManifestEntry) -> &str {
    match entry {
        ManifestEntry::Dir { path } | ManifestEntry::File { path, .. } => path,
    }
}

fn escape_field(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for ch in value.chars() {
        match ch {
            '\\' => out.push_str("\\\\"),
            '\t' => out.push_str("\\t"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            _ => out.push(ch),
        }
    }
    out
}

fn unescape_field(value: &str) -> Result<String> {
    let mut out = String::with_capacity(value.len());
    let mut chars = value.chars();
    while let Some(ch) = chars.next() {
        if ch != '\\' {
            out.push(ch);
            continue;
        }
        let Some(next) = chars.next() else {
            return Err(anyhow!("invalid trailing escape in CAS manifest field"));
        };
        match next {
            '\\' => out.push('\\'),
            't' => out.push('\t'),
            'n' => out.push('\n'),
            'r' => out.push('\r'),
            other => bail!("invalid escape in CAS manifest field: \\{other}"),
        }
    }
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn manifest_round_trips() {
        let entries = vec![
            ManifestEntry::Dir {
                path: "wp-content/uploads".to_string(),
            },
            ManifestEntry::File {
                path: "wp-content/a\tb.txt".to_string(),
                hash: "abc123".to_string(),
                len: 42,
            },
        ];
        let rendered = serialize_manifest(&entries);
        let parsed = parse_manifest(&rendered).unwrap();
        assert_eq!(parsed.len(), 2);
        assert!(parsed.contains(&entries[0]));
        assert!(parsed.contains(&entries[1]));
    }

    #[test]
    fn snapshots_clones_and_mutates_lazy_branch_manifest() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-cas-test-{}-{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let source = root.join("source");
        let store = root.join("store.redb");
        fs::create_dir_all(source.join("wp-content/uploads")).unwrap();
        fs::write(source.join("index.php"), b"<?php echo 'main';").unwrap();
        fs::write(source.join("wp-content/uploads/a.txt"), b"upload bytes").unwrap();

        let report = snapshot_branch(&store, &source, "main").unwrap();
        assert_eq!(report.files, 2);
        assert!(branch_exists(&store, "main").unwrap());

        let created = clone_branch_manifest(&store, "main", "feature").unwrap();
        assert_eq!(created.files, 2);
        assert_eq!(
            read_file_from_branch(&store, "feature", "wp-content/uploads/a.txt").unwrap(),
            b"upload bytes"
        );

        write_file_to_branch(&store, "feature", "wp-content/uploads/b.txt", b"new").unwrap();
        assert!(read_file_from_branch(&store, "main", "wp-content/uploads/b.txt").is_err());
        assert_eq!(
            read_file_from_branch(&store, "feature", "wp-content/uploads/b.txt").unwrap(),
            b"new"
        );

        rename_path(&store, "feature", "wp-content/uploads", "wp-content/media").unwrap();
        assert_eq!(
            read_file_from_branch(&store, "feature", "wp-content/media/a.txt").unwrap(),
            b"upload bytes"
        );
        assert!(read_file_from_branch(&store, "feature", "wp-content/uploads/a.txt").is_err());

        let entries = list_dir(&store, "feature", "wp-content/media").unwrap();
        assert_eq!(
            entries
                .iter()
                .map(|entry| entry.name.as_str())
                .collect::<Vec<_>>(),
            vec!["a.txt", "b.txt"]
        );

        let _ = fs::remove_dir_all(root);
    }
}
