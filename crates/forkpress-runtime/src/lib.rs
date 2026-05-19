use anyhow::{Context, Result, anyhow, bail};
use flate2::read::GzDecoder;
use forkpress_core::{Layout, SharedPaths};
use std::ffi::OsStr;
use std::fs::{self, File};
use std::io::{Cursor, Write};
use std::path::PathBuf;
use std::process::Command;
use zip::ZipArchive;

const STARTUP_WARNING_FILTER: &str = "Missing arginfo";
const FORKPRESS_PHP_MEMORY_LIMIT: &str = "512M";

#[derive(Debug, Clone)]
pub struct PortableRuntime {
    php: PathBuf,
}

impl PortableRuntime {
    pub fn from_layout(layout: &Layout) -> Self {
        let root = layout.runtime_dir.join("portable-runtime");
        Self {
            php: root.join("bin").join(bundled_php_name()),
        }
    }
}

fn bundled_php_name() -> &'static str {
    if cfg!(target_os = "windows") {
        "php.exe"
    } else {
        "php"
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn bundled_php_name_matches_target_platform() {
        if cfg!(target_os = "windows") {
            assert_eq!(bundled_php_name(), "php.exe");
        } else {
            assert_eq!(bundled_php_name(), "php");
        }
    }

    #[test]
    fn php_base_command_uses_forkpress_memory_limit() {
        let root =
            std::env::temp_dir().join(format!("forkpress-runtime-test-{}", std::process::id()));
        let layout = Layout::new(root.join(".forkpress")).unwrap();
        let runtime = PortableRuntime {
            php: PathBuf::from("/tmp/forkpress-php"),
        };
        let shared = SharedPaths {
            work_dir: layout.work_dir.clone(),
            php_bin: None,
        };

        let command = php_base_command(&layout, &runtime, &shared);
        let args: Vec<String> = command
            .get_args()
            .map(|arg| arg.to_string_lossy().into_owned())
            .collect();

        assert!(args.contains(&"memory_limit=512M".to_string()));
    }
}

pub fn prepare_runtime(layout: &Layout, bundle: &[u8], bundle_id: &str) -> Result<()> {
    fs::create_dir_all(&layout.work_dir)?;
    fs::create_dir_all(&layout.logs_dir)?;
    fs::create_dir_all(&layout.wp_root)?;

    let expected_marker = format!("forkpress-runtime {bundle_id}\n");
    let runtime_current = fs::read_to_string(&layout.runtime_ready_marker)
        .map(|contents| contents == expected_marker)
        .unwrap_or(false);

    if !runtime_current {
        if layout.runtime_dir.exists() {
            fs::remove_dir_all(&layout.runtime_dir)
                .with_context(|| format!("failed to clear {}", layout.runtime_dir.display()))?;
        }
        fs::create_dir_all(&layout.runtime_dir)?;

        let decoder = GzDecoder::new(Cursor::new(bundle));
        let mut archive = tar::Archive::new(decoder);
        archive.unpack(&layout.runtime_dir).with_context(|| {
            format!(
                "failed to unpack runtime into {}",
                layout.runtime_dir.display()
            )
        })?;

        ensure_wp_source_unzipped(layout)?;
        fs::write(&layout.runtime_ready_marker, expected_marker)?;
    }

    Ok(())
}

fn ensure_wp_source_unzipped(layout: &Layout) -> Result<()> {
    let wp_src_dir = layout.runtime_dir.join("runtime/wp-src");
    if wp_src_dir.join("wp-load.php").exists() {
        return Ok(());
    }

    fs::create_dir_all(&wp_src_dir)?;
    let zip_file = File::open(layout.runtime_dir.join("runtime/wp.zip"))
        .context("failed to open embedded WordPress archive")?;
    let mut zip = ZipArchive::new(zip_file).context("failed to read embedded WordPress zip")?;

    for i in 0..zip.len() {
        let mut entry = zip.by_index(i)?;
        let enclosed = entry
            .enclosed_name()
            .ok_or_else(|| anyhow!("zip entry had invalid path"))?;
        let stripped = enclosed
            .strip_prefix("wordpress")
            .unwrap_or(enclosed.as_path());

        if stripped.as_os_str().is_empty() {
            continue;
        }

        let out_path = wp_src_dir.join(stripped);

        if entry.is_dir() {
            fs::create_dir_all(&out_path)?;
            continue;
        }

        if let Some(parent) = out_path.parent() {
            fs::create_dir_all(parent)?;
        }

        let mut out = File::create(&out_path)?;
        std::io::copy(&mut entry, &mut out)?;
    }

    Ok(())
}

pub fn php_base_command(
    _layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
) -> Command {
    let mut command = php_command(runtime, shared);
    command
        .arg("-d")
        .arg(format!("memory_limit={FORKPRESS_PHP_MEMORY_LIMIT}"))
        .arg("-d")
        .arg("display_errors=Off")
        .arg("-d")
        .arg("display_startup_errors=Off");
    command
}

pub fn run_php_script<I, S>(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    script_rel: &str,
    args: I,
) -> Result<()>
where
    I: IntoIterator<Item = S>,
    S: AsRef<OsStr>,
{
    let mut command = php_base_command(layout, runtime, shared);
    command.arg(layout.runtime_dir.join(script_rel));
    for arg in args {
        command.arg(arg);
    }
    command.env("BRANCHFS_SQLITE_WP_DB", &layout.site_fp);

    let output = command
        .output()
        .with_context(|| format!("failed to run bundled php script {}", script_rel))?;

    write_filtered_output(&output.stdout, &output.stderr)?;

    if !output.status.success() {
        let stdout = output_excerpt(&String::from_utf8_lossy(&output.stdout));
        let stderr = output_excerpt(&filtered_stderr_text(&output.stderr));
        let mut message = format!("{script_rel} exited with status {}", output.status);
        if !stdout.is_empty() {
            message.push_str("\nstdout:\n");
            message.push_str(&stdout);
        }
        if !stderr.is_empty() {
            message.push_str("\nstderr:\n");
            message.push_str(&stderr);
        }
        bail!("{message}");
    }

    Ok(())
}

pub fn php_command(runtime: &PortableRuntime, shared: &SharedPaths) -> Command {
    if let Some(php_bin) = &shared.php_bin {
        return Command::new(php_bin);
    }
    Command::new(&runtime.php)
}

pub fn write_filtered_output(stdout: &[u8], stderr: &[u8]) -> Result<()> {
    let mut out = std::io::stdout().lock();
    out.write_all(stdout)?;

    let stderr_text = filtered_stderr_text(stderr);
    if !stderr_text.is_empty() {
        writeln!(std::io::stderr().lock(), "{stderr_text}")?;
    }
    Ok(())
}

fn filtered_stderr_text(stderr: &[u8]) -> String {
    String::from_utf8_lossy(stderr)
        .lines()
        .filter(|line| !line.contains(STARTUP_WARNING_FILTER))
        .collect::<Vec<_>>()
        .join("\n")
}

fn output_excerpt(text: &str) -> String {
    const LIMIT: usize = 4000;
    let trimmed = text.trim();
    if trimmed.len() <= LIMIT {
        return trimmed.to_string();
    }
    let mut end = LIMIT;
    while !trimmed.is_char_boundary(end) {
        end -= 1;
    }
    format!("{}...\n[truncated]", &trimmed[..end])
}
