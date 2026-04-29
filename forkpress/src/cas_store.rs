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

pub fn create_branch_from(
    store_path: &Path,
    source_branch: &str,
    new_branch: &str,
    dest_root: &Path,
) -> Result<SnapshotReport> {
    if dest_root.exists() {
        bail!("destination already exists: {}", dest_root.display());
    }

    let db = open_database(store_path)?;
    let manifest = {
        let read = db.begin_read().context("failed to start CAS read")?;
        let branches = read
            .open_table(BRANCHES)
            .context("failed to open CAS branches")?;
        let Some(value) = branches
            .get(source_branch)
            .with_context(|| format!("failed to read CAS branch {source_branch}"))?
        else {
            bail!("source branch has no CAS manifest: {source_branch}");
        };
        value.value().to_string()
    };

    let entries = parse_manifest(&manifest)?;
    materialize_manifest(&db, &entries, dest_root)?;

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

fn materialize_manifest(db: &Database, entries: &[ManifestEntry], dest_root: &Path) -> Result<()> {
    fs::create_dir_all(dest_root)
        .with_context(|| format!("failed to create {}", dest_root.display()))?;

    let read = db.begin_read().context("failed to start CAS read")?;
    let blobs = read.open_table(BLOBS).context("failed to open CAS blobs")?;

    for entry in entries {
        match entry {
            ManifestEntry::Dir { path } => {
                let out = dest_root.join(path);
                fs::create_dir_all(&out)
                    .with_context(|| format!("failed to create {}", out.display()))?;
            }
            ManifestEntry::File { path, hash, len } => {
                let out = dest_root.join(path);
                if let Some(parent) = out.parent() {
                    fs::create_dir_all(parent)
                        .with_context(|| format!("failed to create {}", parent.display()))?;
                }
                let Some(blob) = blobs
                    .get(hash.as_str())
                    .with_context(|| format!("failed to read CAS blob {hash}"))?
                else {
                    bail!("CAS blob missing: {hash}");
                };
                let data = blob.value();
                if data.len() as u64 != *len {
                    bail!(
                        "CAS blob {hash} length mismatch: manifest has {len}, store has {}",
                        data.len()
                    );
                }
                fs::write(&out, data)
                    .with_context(|| format!("failed to write {}", out.display()))?;
            }
        }
    }

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
    fn snapshots_and_materializes_branch() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-cas-test-{}-{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let source = root.join("source");
        let dest = root.join("dest");
        let store = root.join("store.redb");
        fs::create_dir_all(source.join("wp-content")).unwrap();
        fs::write(source.join("index.php"), b"<?php echo 'main';").unwrap();
        fs::write(source.join("wp-content/db.sqlite"), b"sqlite bytes").unwrap();

        let report = snapshot_branch(&store, &source, "main").unwrap();
        assert_eq!(report.files, 2);

        let created = create_branch_from(&store, "main", "feature", &dest).unwrap();
        assert_eq!(created.files, 2);
        assert_eq!(
            fs::read(dest.join("wp-content/db.sqlite")).unwrap(),
            b"sqlite bytes"
        );

        let _ = fs::remove_dir_all(root);
    }
}
