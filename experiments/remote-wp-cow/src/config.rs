use anyhow::{anyhow, Context, Result};
use serde::{Deserialize, Serialize};
use std::fs::{self, File, OpenOptions};
use std::io::{Read, Write};
use std::os::unix::fs::OpenOptionsExt;
use std::path::{Path, PathBuf};
use std::time::{SystemTime, UNIX_EPOCH};
use url::Url;

pub const MANIFEST_VERSION: u32 = 1;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Manifest {
    pub version: u32,
    pub name: String,
    pub ssh: String,
    pub remote_path: String,
    pub remote_url: String,
    pub local_url: String,
    pub created_at_unix: u64,
    pub probe: Probe,
    pub local_db: LocalDb,
    pub control_url: String,
    pub cache_max_file_bytes: u64,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct Probe {
    pub abspath: String,
    pub wp_content_dir: String,
    pub uploads_dir: String,
    pub table_prefix: String,
    pub db_name: String,
    pub db_host: String,
    pub db_user: String,
    #[serde(default, skip_serializing_if = "String::is_empty")]
    pub db_password: String,
    pub siteurl: String,
    pub home: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct LocalDb {
    pub name: String,
    pub user: String,
    #[serde(default, skip_serializing_if = "String::is_empty")]
    pub password: String,
    pub host: String,
    pub port: u16,
}

#[derive(Debug, Clone)]
pub struct ClonePaths {
    pub root: PathBuf,
    pub manifest: PathBuf,
    pub upper: PathBuf,
    pub file_cache: PathBuf,
    pub db: PathBuf,
    pub generated: PathBuf,
    pub run: PathBuf,
    pub whiteouts: PathBuf,
}

impl Manifest {
    pub fn new(
        name: String,
        ssh: String,
        remote_path: String,
        remote_url: String,
        local_url: String,
        probe: Probe,
    ) -> Self {
        let safe_name = sanitize_name(&name);
        Self {
            version: MANIFEST_VERSION,
            name: safe_name.clone(),
            ssh,
            remote_path,
            remote_url,
            local_url,
            created_at_unix: SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap_or_default()
                .as_secs(),
            probe,
            local_db: LocalDb {
                name: format!("cow_{}", safe_name.replace('-', "_")),
                user: format!("cow_{}", safe_name.replace('-', "_")),
                password: String::new(),
                host: "127.0.0.1".to_string(),
                port: 33071,
            },
            control_url: "http://127.0.0.1:39070".to_string(),
            cache_max_file_bytes: 8 * 1024 * 1024,
        }
    }
}

pub fn default_state_dir() -> Result<PathBuf> {
    if let Ok(home) = std::env::var("WPCOW_HOME") {
        return Ok(PathBuf::from(home));
    }
    let home = std::env::var("HOME").context("HOME is not set; pass --state-dir")?;
    Ok(PathBuf::from(home).join(".wp-cow"))
}

pub fn clone_paths(state_dir: &Path, name: &str) -> ClonePaths {
    let root = state_dir.join("clones").join(name);
    ClonePaths {
        manifest: root.join("manifest.json"),
        upper: root.join("upper"),
        file_cache: root.join("file-cache"),
        db: root.join("db"),
        generated: root.join("generated"),
        run: root.join("run"),
        whiteouts: root.join("whiteouts.json"),
        root,
    }
}

pub fn ensure_clone_dirs(paths: &ClonePaths) -> Result<()> {
    fs::create_dir_all(&paths.upper)?;
    fs::create_dir_all(&paths.file_cache)?;
    fs::create_dir_all(&paths.db)?;
    fs::create_dir_all(paths.db.join("local-mysql"))?;
    fs::create_dir_all(&paths.generated)?;
    fs::create_dir_all(&paths.run)?;
    Ok(())
}

pub fn write_manifest(path: &Path, manifest: &Manifest) -> Result<()> {
    let json = serde_json::to_vec_pretty(manifest)?;
    let mut file = OpenOptions::new()
        .create(true)
        .truncate(true)
        .write(true)
        .mode(0o600)
        .open(path)
        .with_context(|| format!("write {}", path.display()))?;
    file.write_all(&json)?;
    file.write_all(b"\n")?;
    Ok(())
}

pub fn load_manifest(path: &Path) -> Result<Manifest> {
    let mut json = String::new();
    File::open(path)
        .with_context(|| format!("open {}", path.display()))?
        .read_to_string(&mut json)?;
    let manifest: Manifest = serde_json::from_str(&json)?;
    if manifest.version != MANIFEST_VERSION {
        return Err(anyhow!(
            "unsupported manifest version {} in {}",
            manifest.version,
            path.display()
        ));
    }
    Ok(manifest)
}

pub fn derive_name(remote_url: &str, local_url: &str) -> String {
    let from_url = |raw: &str| -> Option<String> {
        let parsed = Url::parse(raw).ok()?;
        let host = parsed.host_str()?;
        let host = host.strip_prefix("www.").unwrap_or(host);
        let first = host.split('.').next().unwrap_or(host);
        Some(sanitize_name(first))
    };

    from_url(remote_url)
        .or_else(|| from_url(local_url))
        .filter(|s| !s.is_empty())
        .unwrap_or_else(|| "site".to_string())
}

pub fn sanitize_name(input: &str) -> String {
    let mut out = String::with_capacity(input.len());
    for ch in input.chars() {
        if ch.is_ascii_alphanumeric() {
            out.push(ch.to_ascii_lowercase());
        } else if ch.is_ascii_whitespace() || ch == '-' || ch == '_' || ch == '.' {
            out.push('-');
        }
    }
    while out.contains("--") {
        out = out.replace("--", "-");
    }
    out.trim_matches('-').to_string()
}

pub fn parse_host_port(host: &str, default_port: u16) -> (String, u16) {
    if let Some((h, p)) = host.rsplit_once(':') {
        if let Ok(port) = p.parse::<u16>() {
            return (h.to_string(), port);
        }
    }
    (host.to_string(), default_port)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn derives_name_from_remote_url() {
        assert_eq!(
            derive_name("https://www.example.com", "http://x.test"),
            "example"
        );
        assert_eq!(
            derive_name("not a url", "http://local-site.test"),
            "local-site"
        );
    }

    #[test]
    fn sanitizes_name() {
        assert_eq!(sanitize_name("Example Site_1"), "example-site-1");
        assert_eq!(sanitize_name("...Cow!!!"), "cow");
    }
}
