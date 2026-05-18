use anyhow::{Context, Result, anyhow, bail};
use forkpress_core::{Layout, SharedPaths, validate_branch_name};
use forkpress_runtime::PortableRuntime;
use serde::{Deserialize, Serialize};
use std::fs::{self, File, OpenOptions};
use std::io::{Read, Write};
use std::path::{Path, PathBuf};
use std::time::{SystemTime, UNIX_EPOCH};

use crate::create_cow_branch_from_external_tree;

const REMOTE_SITE_MANIFEST_VERSION: u32 = 1;

#[derive(Debug, Clone)]
pub struct RemoteSiteAdd {
    pub name: String,
    pub ssh: Option<String>,
    pub remote_path: Option<String>,
    pub remote_url: Option<String>,
    pub local_url: Option<String>,
    pub cache_root: Option<PathBuf>,
    pub wp_cow_clone_root: Option<PathBuf>,
    pub force: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RemoteSiteManifest {
    pub version: u32,
    pub name: String,
    #[serde(default, skip_serializing_if = "String::is_empty")]
    pub ssh: String,
    #[serde(default, skip_serializing_if = "String::is_empty")]
    pub remote_path: String,
    #[serde(default, skip_serializing_if = "String::is_empty")]
    pub remote_url: String,
    #[serde(default, skip_serializing_if = "String::is_empty")]
    pub local_url: String,
    pub cache_root: PathBuf,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub wp_cow_clone_root: Option<PathBuf>,
    pub created_at_unix: u64,
}

#[derive(Debug, Clone)]
pub struct RemoteCacheStats {
    pub cache_root: PathBuf,
    pub files: usize,
    pub bytes: u64,
    pub has_wp_load: bool,
}

#[derive(Debug, Clone)]
pub struct RemoteSiteProbe {
    pub manifest: RemoteSiteManifest,
    pub cache: RemoteCacheStats,
}

#[derive(Debug, Clone)]
pub struct RemoteBranchOptions {
    pub remote: String,
    pub branch: String,
    pub url_hint: Option<(String, String)>,
}

#[derive(Debug, Clone)]
pub struct RemoteBranchReport {
    pub remote: RemoteSiteManifest,
    pub branch: String,
    pub cache: RemoteCacheStats,
}

#[derive(Debug, Clone, Deserialize)]
struct WpCowManifest {
    #[serde(default)]
    ssh: String,
    #[serde(default)]
    remote_path: String,
    #[serde(default)]
    remote_url: String,
    #[serde(default)]
    local_url: String,
}

pub fn sanitize_remote_site_name(input: &str) -> String {
    let mut out = String::with_capacity(input.len());
    for ch in input.chars() {
        if ch.is_ascii_alphanumeric() {
            out.push(ch.to_ascii_lowercase());
        } else if ch.is_ascii_whitespace() || matches!(ch, '-' | '_' | '.') {
            out.push('-');
        }
    }
    while out.contains("--") {
        out = out.replace("--", "-");
    }
    out.trim_matches('-').to_string()
}

pub fn add_remote_site(layout: &Layout, add: RemoteSiteAdd) -> Result<RemoteSiteManifest> {
    let name = sanitize_remote_site_name(&add.name);
    if name.is_empty() {
        bail!("remote site name must contain at least one ASCII letter or number");
    }

    let wp_cow = match add.wp_cow_clone_root {
        Some(root) => Some(read_wp_cow_clone(root)?),
        None => None,
    };
    let wp_cow_manifest = wp_cow.as_ref().map(|clone| clone.manifest.clone());
    let wp_cow_root = wp_cow.as_ref().map(|clone| clone.root.clone());

    let cache_root = add
        .cache_root
        .or_else(|| wp_cow.as_ref().map(|clone| clone.file_cache_mirror.clone()))
        .ok_or_else(|| anyhow!("remote add requires --cache-root or --wp-cow-clone"))?;
    let cache_root = absolutize(cache_root)?;

    let manifest = RemoteSiteManifest {
        version: REMOTE_SITE_MANIFEST_VERSION,
        name: name.clone(),
        ssh: add
            .ssh
            .or_else(|| {
                wp_cow_manifest
                    .as_ref()
                    .map(|manifest| manifest.ssh.clone())
            })
            .unwrap_or_default(),
        remote_path: add
            .remote_path
            .or_else(|| {
                wp_cow_manifest
                    .as_ref()
                    .map(|manifest| manifest.remote_path.clone())
            })
            .unwrap_or_default(),
        remote_url: add
            .remote_url
            .or_else(|| {
                wp_cow_manifest
                    .as_ref()
                    .map(|manifest| manifest.remote_url.clone())
            })
            .unwrap_or_default(),
        local_url: add
            .local_url
            .or_else(|| {
                wp_cow_manifest
                    .as_ref()
                    .map(|manifest| manifest.local_url.clone())
            })
            .unwrap_or_default(),
        cache_root,
        wp_cow_clone_root: wp_cow_root,
        created_at_unix: SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs(),
    };

    let manifest_path = remote_site_manifest_path(layout, &name);
    if manifest_path.exists() && !add.force {
        bail!("remote site already exists: {name}; pass --force to update it");
    }
    write_remote_site_manifest_path(&manifest_path, &manifest)?;
    Ok(manifest)
}

pub fn list_remote_sites(layout: &Layout) -> Result<Vec<RemoteSiteManifest>> {
    let dir = remote_sites_dir(layout);
    let Ok(entries) = fs::read_dir(&dir) else {
        return Ok(Vec::new());
    };
    let mut sites = Vec::new();
    for entry in entries {
        let entry = entry?;
        if !entry.file_type()?.is_dir() {
            continue;
        }
        let name = entry.file_name().to_string_lossy().into_owned();
        if let Ok(site) = read_remote_site_manifest(layout, &name) {
            sites.push(site);
        }
    }
    sites.sort_by(|a, b| a.name.cmp(&b.name));
    Ok(sites)
}

pub fn read_remote_site_manifest(layout: &Layout, name: &str) -> Result<RemoteSiteManifest> {
    let name = sanitize_remote_site_name(name);
    if name.is_empty() {
        bail!("remote site name must not be empty");
    }
    let path = remote_site_manifest_path(layout, &name);
    let mut json = String::new();
    File::open(&path)
        .with_context(|| format!("failed to open {}", path.display()))?
        .read_to_string(&mut json)
        .with_context(|| format!("failed to read {}", path.display()))?;
    let manifest: RemoteSiteManifest = serde_json::from_str(&json)
        .with_context(|| format!("failed to parse {}", path.display()))?;
    if manifest.version != REMOTE_SITE_MANIFEST_VERSION {
        bail!(
            "unsupported remote-site manifest version {} in {}",
            manifest.version,
            path.display()
        );
    }
    Ok(manifest)
}

pub fn probe_remote_site(layout: &Layout, name: &str) -> Result<RemoteSiteProbe> {
    let manifest = read_remote_site_manifest(layout, name)?;
    let cache = remote_site_cache_stats(&manifest)?;
    Ok(RemoteSiteProbe { manifest, cache })
}

pub fn remote_site_cache_stats(manifest: &RemoteSiteManifest) -> Result<RemoteCacheStats> {
    let mut files = 0usize;
    let mut bytes = 0u64;
    if manifest.cache_root.is_dir() {
        count_files(&manifest.cache_root, &mut files, &mut bytes)?;
    }
    Ok(RemoteCacheStats {
        cache_root: manifest.cache_root.clone(),
        files,
        bytes,
        has_wp_load: manifest.cache_root.join("wp-load.php").is_file(),
    })
}

pub fn branch_remote_site(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    options: RemoteBranchOptions,
) -> Result<RemoteBranchReport> {
    validate_branch_name(&options.branch)?;
    let manifest = read_remote_site_manifest(layout, &options.remote)?;
    let cache = remote_site_cache_stats(&manifest)?;
    if !cache.has_wp_load {
        bail!(
            "remote cache for '{}' is not a materialized WordPress root: {}",
            manifest.name,
            cache.cache_root.display()
        );
    }
    create_cow_branch_from_external_tree(
        layout,
        runtime,
        shared,
        &options.branch,
        &manifest.cache_root,
        &format!("remote site '{}'", manifest.name),
        None,
        options.url_hint,
    )?;
    Ok(RemoteBranchReport {
        remote: manifest,
        branch: options.branch,
        cache,
    })
}

fn remote_sites_dir(layout: &Layout) -> PathBuf {
    layout.cow_dir.join("remote-sites")
}

fn remote_site_manifest_path(layout: &Layout, name: &str) -> PathBuf {
    remote_sites_dir(layout).join(name).join("manifest.json")
}

fn write_remote_site_manifest_path(path: &Path, manifest: &RemoteSiteManifest) -> Result<()> {
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent)
            .with_context(|| format!("failed to create {}", parent.display()))?;
    }
    let json = serde_json::to_vec_pretty(manifest)?;
    let mut file = OpenOptions::new()
        .create(true)
        .truncate(true)
        .write(true)
        .open(path)
        .with_context(|| format!("failed to write {}", path.display()))?;
    file.write_all(&json)?;
    file.write_all(b"\n")?;
    Ok(())
}

#[derive(Debug)]
struct WpCowClone {
    root: PathBuf,
    manifest: WpCowManifest,
    file_cache_mirror: PathBuf,
}

fn read_wp_cow_clone(root: PathBuf) -> Result<WpCowClone> {
    let root = absolutize(root)?;
    let manifest_path = root.join("manifest.json");
    let mut json = String::new();
    File::open(&manifest_path)
        .with_context(|| format!("failed to open {}", manifest_path.display()))?
        .read_to_string(&mut json)
        .with_context(|| format!("failed to read {}", manifest_path.display()))?;
    let manifest: WpCowManifest = serde_json::from_str(&json)
        .with_context(|| format!("failed to parse {}", manifest_path.display()))?;
    Ok(WpCowClone {
        file_cache_mirror: root.join("file-cache/mirror"),
        root,
        manifest,
    })
}

fn count_files(root: &Path, files: &mut usize, bytes: &mut u64) -> Result<()> {
    for entry in fs::read_dir(root).with_context(|| format!("failed to read {}", root.display()))? {
        let entry = entry?;
        let metadata = entry.metadata()?;
        if metadata.is_dir() {
            count_files(&entry.path(), files, bytes)?;
        } else if metadata.is_file() {
            *files += 1;
            *bytes = bytes.saturating_add(metadata.len());
        }
    }
    Ok(())
}

fn absolutize(path: PathBuf) -> Result<PathBuf> {
    let raw = if path.is_absolute() {
        path
    } else {
        std::env::current_dir()
            .context("failed to read current working directory")?
            .join(path)
    };

    let mut out = PathBuf::new();
    for comp in raw.components() {
        match comp {
            std::path::Component::ParentDir => {
                out.pop();
            }
            std::path::Component::CurDir => {}
            other => out.push(other.as_os_str()),
        }
    }
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;
    use forkpress_core::Layout;

    #[test]
    fn remote_site_names_are_sanitized() {
        assert_eq!(
            sanitize_remote_site_name("Example Site_1"),
            "example-site-1"
        );
        assert_eq!(sanitize_remote_site_name("...Cow!!!"), "cow");
    }

    #[test]
    fn registering_wp_cow_clone_reuses_mirror_cache() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-remote-site-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let project = root.join("project");
        let layout = Layout::new(project.join(".forkpress")).unwrap();
        let wp_cow = root.join("wp-cow/clones/example");
        let mirror = wp_cow.join("file-cache/mirror");
        fs::create_dir_all(&mirror).unwrap();
        fs::write(mirror.join("wp-load.php"), b"<?php\n").unwrap();
        fs::write(
            wp_cow.join("manifest.json"),
            r#"{
  "ssh": "example",
  "remote_path": "/srv/www/example",
  "remote_url": "https://example.test",
  "local_url": "http://localhost:18080"
}"#,
        )
        .unwrap();

        let manifest = add_remote_site(
            &layout,
            RemoteSiteAdd {
                name: "Example".to_string(),
                ssh: None,
                remote_path: None,
                remote_url: None,
                local_url: None,
                cache_root: None,
                wp_cow_clone_root: Some(wp_cow.clone()),
                force: false,
            },
        )
        .unwrap();

        assert_eq!(manifest.name, "example");
        assert_eq!(manifest.cache_root, mirror);
        assert_eq!(
            manifest.wp_cow_clone_root.as_deref(),
            Some(wp_cow.as_path())
        );
        assert_eq!(manifest.remote_url, "https://example.test");
        let stats = remote_site_cache_stats(&manifest).unwrap();
        assert!(stats.has_wp_load);
        assert_eq!(stats.files, 1);

        let _ = fs::remove_dir_all(root);
    }
}
