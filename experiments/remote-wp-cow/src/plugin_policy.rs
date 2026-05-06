use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use std::collections::{BTreeMap, BTreeSet};
use std::fs;
use std::path::{Path, PathBuf};

use crate::config::{ClonePaths, Manifest};

pub const POLICY_VERSION: u32 = 1;

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct PluginPolicy {
    pub version: u32,
    pub mode: String,
    #[serde(default)]
    pub active: Vec<String>,
    #[serde(default)]
    pub allow: Vec<String>,
    #[serde(default)]
    pub quarantine: BTreeMap<String, String>,
}

impl PluginPolicy {
    pub fn new(active: &[String]) -> Self {
        Self {
            version: POLICY_VERSION,
            mode: "auto".to_string(),
            active: normalized_plugins(active.iter().cloned()),
            allow: Vec::new(),
            quarantine: BTreeMap::new(),
        }
    }

    pub fn normalize(mut self, active: &[String]) -> Self {
        self.version = POLICY_VERSION;
        if self.mode.trim().is_empty() {
            self.mode = "auto".to_string();
        }
        self.active = normalized_plugins(active.iter().cloned());
        let active_set: BTreeSet<_> = self.active.iter().cloned().collect();
        self.allow = normalized_plugins(
            self.allow
                .into_iter()
                .filter(|plugin| active_set.contains(plugin)),
        );
        self.quarantine
            .retain(|plugin, _| active_set.contains(plugin));
        let quarantined: BTreeSet<_> = self.quarantine.keys().cloned().collect();
        self.allow.retain(|allowed| !quarantined.contains(allowed));
        self
    }

    pub fn allows(&self, plugin: &str) -> bool {
        self.allow.iter().any(|allowed| allowed == plugin)
    }

    pub fn allow_plugin(&mut self, plugin: &str) {
        if !self.allows(plugin) {
            self.allow.push(plugin.to_string());
            self.allow.sort();
        }
        self.quarantine.remove(plugin);
    }

    pub fn quarantine_plugin(&mut self, plugin: &str, reason: impl Into<String>) {
        self.quarantine.insert(plugin.to_string(), reason.into());
        self.allow.retain(|allowed| allowed != plugin);
    }
}

pub fn policy_path(paths: &ClonePaths) -> PathBuf {
    paths.run.join("plugin-policy.json")
}

pub fn candidate_policy_path(paths: &ClonePaths, plugin: &str) -> PathBuf {
    paths.run.join(format!(
        "plugin-policy-candidate-{}.json",
        sanitize_plugin_name(plugin)
    ))
}

pub fn write_initial_policy(paths: &ClonePaths, manifest: &Manifest) -> Result<()> {
    let path = policy_path(paths);
    let active = active_plugins_for_policy(manifest);
    let policy = load_policy_or_new(&path, &active)?;
    write_policy_atomic(&path, &policy)
}

pub fn active_plugins_for_policy(manifest: &Manifest) -> Vec<String> {
    manifest
        .probe
        .active_plugins
        .iter()
        .chain(manifest.probe.active_sitewide_plugins.iter())
        .cloned()
        .collect()
}

pub fn load_policy_or_new(path: &Path, active: &[String]) -> Result<PluginPolicy> {
    if !path.is_file() {
        return Ok(PluginPolicy::new(active));
    }

    let bytes = fs::read(path).with_context(|| format!("read {}", path.display()))?;
    let policy = serde_json::from_slice::<PluginPolicy>(&bytes)
        .with_context(|| format!("parse {}", path.display()))?;
    Ok(policy.normalize(active))
}

pub fn write_policy_atomic(path: &Path, policy: &PluginPolicy) -> Result<()> {
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent)?;
    }
    let tmp = path.with_extension(format!(
        "{}.tmp",
        path.extension()
            .and_then(|extension| extension.to_str())
            .unwrap_or("json")
    ));
    let json = serde_json::to_vec_pretty(policy)?;
    fs::write(&tmp, [json, b"\n".to_vec()].concat())
        .with_context(|| format!("write {}", tmp.display()))?;
    fs::rename(&tmp, path).with_context(|| {
        format!(
            "replace {} with {}",
            path.display(),
            tmp.file_name()
                .map(|name| name.to_string_lossy())
                .unwrap_or_default()
        )
    })?;
    Ok(())
}

pub fn policy_with_candidate(base: &PluginPolicy, plugin: &str) -> PluginPolicy {
    let mut policy = base.clone();
    policy.allow_plugin(plugin);
    policy
}

fn normalized_plugins(plugins: impl IntoIterator<Item = String>) -> Vec<String> {
    plugins
        .into_iter()
        .map(|plugin| plugin.trim().trim_start_matches('/').to_string())
        .filter(|plugin| {
            !plugin.is_empty()
                && !plugin.contains("..")
                && !plugin.starts_with('/')
                && plugin.ends_with(".php")
        })
        .collect::<BTreeSet<_>>()
        .into_iter()
        .collect()
}

fn sanitize_plugin_name(plugin: &str) -> String {
    let mut out = String::new();
    for ch in plugin.chars() {
        if ch.is_ascii_alphanumeric() {
            out.push(ch.to_ascii_lowercase());
        } else if matches!(ch, '/' | '-' | '_' | '.') {
            out.push('-');
        }
    }
    let out = out.trim_matches('-').to_string();
    if out.is_empty() {
        "plugin".to_string()
    } else {
        out
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn policy_starts_auto_with_no_allowed_plugins() {
        let active = vec![
            "woocommerce/woocommerce.php".to_string(),
            "/bad-prefix/plugin.php".to_string(),
            "../escape.php".to_string(),
        ];

        let policy = PluginPolicy::new(&active);

        assert_eq!(policy.mode, "auto");
        assert_eq!(
            policy.active,
            vec!["bad-prefix/plugin.php", "woocommerce/woocommerce.php"]
        );
        assert!(policy.allow.is_empty());
    }

    #[test]
    fn existing_policy_preserves_allowed_active_plugins_only() {
        let temp = tempfile::tempdir().unwrap();
        let path = temp.path().join("plugin-policy.json");
        let mut policy = PluginPolicy::new(&[
            "akismet/akismet.php".to_string(),
            "woocommerce/woocommerce.php".to_string(),
        ]);
        policy.allow_plugin("woocommerce/woocommerce.php");
        policy.allow_plugin("missing/missing.php");
        policy.quarantine_plugin("akismet/akismet.php", "timeout");
        write_policy_atomic(&path, &policy).unwrap();

        let loaded = load_policy_or_new(
            &path,
            &[
                "akismet/akismet.php".to_string(),
                "hello/hello.php".to_string(),
            ],
        )
        .unwrap();

        assert_eq!(
            loaded.active,
            vec!["akismet/akismet.php", "hello/hello.php"]
        );
        assert!(loaded.allow.is_empty());
        assert_eq!(
            loaded
                .quarantine
                .get("akismet/akismet.php")
                .map(String::as_str),
            Some("timeout")
        );
    }

    #[test]
    fn existing_policy_never_allows_quarantined_plugins() {
        let temp = tempfile::tempdir().unwrap();
        let path = temp.path().join("plugin-policy.json");
        let mut policy =
            PluginPolicy::new(&["seo/seo.php".to_string(), "visual/visual.php".to_string()]);
        policy.allow_plugin("seo/seo.php");
        policy.allow_plugin("visual/visual.php");
        policy
            .quarantine
            .insert("seo/seo.php".to_string(), "timed out".to_string());
        write_policy_atomic(&path, &policy).unwrap();

        let loaded = load_policy_or_new(
            &path,
            &["seo/seo.php".to_string(), "visual/visual.php".to_string()],
        )
        .unwrap();

        assert_eq!(loaded.allow, vec!["visual/visual.php"]);
        assert_eq!(
            loaded.quarantine.get("seo/seo.php").map(String::as_str),
            Some("timed out")
        );
    }

    #[test]
    fn candidate_policy_allows_one_extra_plugin() {
        let base = PluginPolicy::new(&["woocommerce/woocommerce.php".to_string()]);
        let candidate = policy_with_candidate(&base, "woocommerce/woocommerce.php");

        assert_eq!(candidate.allow, vec!["woocommerce/woocommerce.php"]);
    }
}
