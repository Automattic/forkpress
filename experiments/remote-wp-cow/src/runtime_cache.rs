use anyhow::{Context, Result};
use std::collections::BTreeSet;
use std::path::{Path, PathBuf};

use crate::config::{ClonePaths, Manifest};
use crate::overlay::OverlayStore;
use crate::remote::{RemoteClient, RuntimeCodePackLimits, RuntimeCodePackSummary};

const ROOT_RUNTIME_FILES: &[&str] = &[
    "index.php",
    "wp-activate.php",
    "wp-blog-header.php",
    "wp-comments-post.php",
    "wp-cron.php",
    "wp-links-opml.php",
    "wp-load.php",
    "wp-login.php",
    "wp-mail.php",
    "wp-settings.php",
    "wp-signup.php",
    "wp-trackback.php",
    "xmlrpc.php",
];

pub fn warm_runtime_code_cache(
    remote: &RemoteClient,
    manifest: &Manifest,
    paths: &ClonePaths,
) -> Result<RuntimeCodePackSummary> {
    warm_runtime_code_cache_inner(remote, manifest, paths, runtime_code_pack_include_admin())
}

pub fn warm_runtime_code_cache_with_admin(
    remote: &RemoteClient,
    manifest: &Manifest,
    paths: &ClonePaths,
) -> Result<RuntimeCodePackSummary> {
    warm_runtime_code_cache_inner(remote, manifest, paths, true)
}

fn warm_runtime_code_cache_inner(
    remote: &RemoteClient,
    manifest: &Manifest,
    paths: &ClonePaths,
    include_admin: bool,
) -> Result<RuntimeCodePackSummary> {
    if !runtime_code_pack_enabled() {
        return Ok(RuntimeCodePackSummary::default());
    }

    let roots = runtime_code_pack_roots_with_admin(manifest, include_admin);
    if roots.is_empty() {
        return Ok(RuntimeCodePackSummary::default());
    }

    let overlay = OverlayStore::new(paths);
    let limits = RuntimeCodePackLimits {
        max_file_bytes: runtime_code_pack_max_file_bytes().min(manifest.cache_max_file_bytes),
        max_total_bytes: runtime_code_pack_max_bytes(),
        max_files: runtime_code_pack_max_files(),
    };

    let summary = remote
        .runtime_code_pack(&roots, limits, |file| {
            overlay
                .put_cached_file_bytes_without_progress(&file.rel, &file.entry, &file.bytes)
                .with_context(|| {
                    format!(
                        "cache remote runtime code file {}",
                        OverlayStore::rel_string(&file.rel)
                    )
                })?;
            let _ = overlay.note_cache_fetch(
                &file.rel,
                "runtime-code-pack",
                file.entry.size,
                file.entry.size,
            );
            Ok(())
        })
        .context("cache remote runtime code pack")?;
    let _ = overlay.note_cache_fetch(
        Path::new(".wp-cow-runtime-code-pack"),
        "runtime-code-pack-done",
        summary.bytes,
        summary.bytes,
    );
    Ok(summary)
}

pub fn mark_runtime_code_cache_starting(paths: &ClonePaths) {
    let overlay = OverlayStore::new(paths);
    let _ = overlay.note_cache_fetch(
        Path::new(".wp-cow-runtime-code-pack"),
        "runtime-code-pack-starting",
        0,
        0,
    );
}

pub fn mark_runtime_code_cache_failed(paths: &ClonePaths) {
    let overlay = OverlayStore::new(paths);
    let _ = overlay.note_cache_fetch(
        Path::new(".wp-cow-runtime-code-pack"),
        "runtime-code-pack-error",
        0,
        0,
    );
}

#[cfg(test)]
pub fn runtime_code_pack_roots(manifest: &Manifest) -> Vec<PathBuf> {
    runtime_code_pack_roots_with_admin(manifest, runtime_code_pack_include_admin())
}

fn runtime_code_pack_roots_with_admin(manifest: &Manifest, include_admin: bool) -> Vec<PathBuf> {
    let mut roots = BTreeSet::new();
    for file in ROOT_RUNTIME_FILES {
        roots.insert(PathBuf::from(file));
    }

    roots.insert(PathBuf::from("wp-includes"));
    if include_admin {
        roots.insert(PathBuf::from("wp-admin"));
    }
    roots.insert(PathBuf::from("wp-content/mu-plugins"));
    roots.insert(PathBuf::from("wp-content/languages"));

    for theme in [&manifest.probe.template, &manifest.probe.stylesheet] {
        if let Some(root) = theme_runtime_root(theme) {
            roots.insert(root);
        }
    }

    if plugins_enabled_for_runtime() {
        for plugin in manifest
            .probe
            .active_plugins
            .iter()
            .chain(manifest.probe.active_sitewide_plugins.iter())
        {
            if let Some(root) = plugin_runtime_root(plugin) {
                roots.insert(root);
            }
        }
    }

    roots
        .into_iter()
        .filter(|root| !is_upload_path(root) && root != Path::new("wp-config.php"))
        .collect()
}

fn theme_runtime_root(theme: &str) -> Option<PathBuf> {
    let theme = clean_segment(theme)?;
    Some(PathBuf::from("wp-content/themes").join(theme))
}

fn plugin_runtime_root(plugin: &str) -> Option<PathBuf> {
    let clean = clean_rel(plugin)?;
    if clean.as_os_str().is_empty() || is_upload_path(&clean) {
        return None;
    }

    let mut components = clean.components();
    let first = components.next()?;
    if components.next().is_some() {
        Some(PathBuf::from("wp-content/plugins").join(first.as_os_str()))
    } else {
        Some(PathBuf::from("wp-content/plugins").join(clean))
    }
}

fn clean_segment(value: &str) -> Option<String> {
    if value.is_empty()
        || value.contains('/')
        || value.contains('\\')
        || value == "."
        || value == ".."
    {
        return None;
    }
    Some(value.to_string())
}

fn clean_rel(value: &str) -> Option<PathBuf> {
    OverlayStore::clean_rel(value).ok()
}

fn is_upload_path(path: &Path) -> bool {
    path.starts_with(Path::new("wp-content/uploads"))
}

pub fn runtime_code_pack_enabled() -> bool {
    env_bool("WPCOW_RUNTIME_CODE_PACK", true)
}

fn runtime_code_pack_include_admin() -> bool {
    env_bool("WPCOW_RUNTIME_CODE_PACK_INCLUDE_ADMIN", false)
}

fn plugins_enabled_for_runtime() -> bool {
    env_bool("WPCOW_ENABLE_PLUGINS", true)
}

fn runtime_code_pack_max_bytes() -> u64 {
    env_u64("WPCOW_RUNTIME_CODE_PACK_MAX_MB", 256).saturating_mul(1024 * 1024)
}

fn runtime_code_pack_max_file_bytes() -> u64 {
    env_u64("WPCOW_RUNTIME_CODE_PACK_MAX_FILE_MB", 8).saturating_mul(1024 * 1024)
}

fn runtime_code_pack_max_files() -> u64 {
    env_u64("WPCOW_RUNTIME_CODE_PACK_MAX_FILES", 20_000)
}

fn env_u64(name: &str, default: u64) -> u64 {
    std::env::var(name)
        .ok()
        .and_then(|raw| raw.parse::<u64>().ok())
        .unwrap_or(default)
}

fn env_bool(name: &str, default: bool) -> bool {
    std::env::var(name)
        .ok()
        .map(|raw| match raw.to_ascii_lowercase().as_str() {
            "1" | "true" | "yes" | "on" => true,
            "0" | "false" | "no" | "off" => false,
            _ => default,
        })
        .unwrap_or(default)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::Probe;
    use std::sync::{Mutex, OnceLock};

    static ENV_LOCK: OnceLock<Mutex<()>> = OnceLock::new();

    #[test]
    fn runtime_code_roots_are_bounded_to_core_theme_and_active_plugins() {
        let _guard = ENV_LOCK.get_or_init(|| Mutex::new(())).lock().unwrap();
        let old_plugins = std::env::var_os("WPCOW_ENABLE_PLUGINS");
        let old_admin = std::env::var_os("WPCOW_RUNTIME_CODE_PACK_INCLUDE_ADMIN");
        std::env::set_var("WPCOW_ENABLE_PLUGINS", "1");
        std::env::set_var("WPCOW_RUNTIME_CODE_PACK_INCLUDE_ADMIN", "1");

        let manifest = Manifest::new(
            "example".to_string(),
            "example".to_string(),
            "/remote".to_string(),
            "https://example.com".to_string(),
            "http://example.test".to_string(),
            Probe {
                table_prefix: "wp_".to_string(),
                template: "parent".to_string(),
                stylesheet: "child".to_string(),
                active_plugins: vec![
                    "woocommerce/woocommerce.php".to_string(),
                    "hello.php".to_string(),
                    "../escape/escape.php".to_string(),
                ],
                active_sitewide_plugins: vec!["network/network.php".to_string()],
                ..Probe::default()
            },
        );

        let roots = runtime_code_pack_roots(&manifest);
        assert!(roots.contains(&PathBuf::from("wp-includes")));
        assert!(roots.contains(&PathBuf::from("wp-admin")));
        assert!(roots.contains(&PathBuf::from("wp-content/themes/parent")));
        assert!(roots.contains(&PathBuf::from("wp-content/themes/child")));
        assert!(roots.contains(&PathBuf::from("wp-content/plugins/woocommerce")));
        assert!(roots.contains(&PathBuf::from("wp-content/plugins/hello.php")));
        assert!(roots.contains(&PathBuf::from("wp-content/plugins/network")));
        assert!(!roots
            .iter()
            .any(|root| root.starts_with("wp-content/uploads")));
        assert!(!roots
            .iter()
            .any(|root| root.to_string_lossy().contains("..")));
        assert!(!roots.contains(&PathBuf::from("wp-config.php")));

        match old_plugins {
            Some(value) => std::env::set_var("WPCOW_ENABLE_PLUGINS", value),
            None => std::env::remove_var("WPCOW_ENABLE_PLUGINS"),
        }
        match old_admin {
            Some(value) => std::env::set_var("WPCOW_RUNTIME_CODE_PACK_INCLUDE_ADMIN", value),
            None => std::env::remove_var("WPCOW_RUNTIME_CODE_PACK_INCLUDE_ADMIN"),
        }
    }

    #[test]
    fn runtime_code_roots_respect_disabled_plugins() {
        let _guard = ENV_LOCK.get_or_init(|| Mutex::new(())).lock().unwrap();
        let old_plugins = std::env::var_os("WPCOW_ENABLE_PLUGINS");
        std::env::set_var("WPCOW_ENABLE_PLUGINS", "0");

        let manifest = Manifest::new(
            "example".to_string(),
            "example".to_string(),
            "/remote".to_string(),
            "https://example.com".to_string(),
            "http://example.test".to_string(),
            Probe {
                table_prefix: "wp_".to_string(),
                active_plugins: vec!["woocommerce/woocommerce.php".to_string()],
                ..Probe::default()
            },
        );

        let roots = runtime_code_pack_roots(&manifest);
        assert!(!roots.contains(&PathBuf::from("wp-content/plugins/woocommerce")));
        assert!(roots.contains(&PathBuf::from("wp-content/mu-plugins")));

        match old_plugins {
            Some(value) => std::env::set_var("WPCOW_ENABLE_PLUGINS", value),
            None => std::env::remove_var("WPCOW_ENABLE_PLUGINS"),
        }
    }
}
