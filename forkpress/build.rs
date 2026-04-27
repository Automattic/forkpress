use anyhow::{Context, Result, bail};
use flate2::Compression;
use flate2::write::GzEncoder;
use std::env;
use std::fs::File;
use std::path::{Path, PathBuf};
use tar::Builder;
use walkdir::WalkDir;

fn main() -> Result<()> {
    // Allow skipping the dist build entirely for `cargo check` runs:
    //   FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo check -p forkpress
    if env::var_os("FORKPRESS_RUNTIME_BUNDLE").is_some() {
        return Ok(());
    }

    let manifest_dir = PathBuf::from(env::var("CARGO_MANIFEST_DIR")?);
    let repo_root = manifest_dir
        .parent()
        .context("forkpress crate should live directly under the repo root")?;
    let target = env::var("TARGET").context("TARGET env var missing (set by cargo)")?;

    let dist_dir = repo_root.join("dist").join(&target);
    if !dist_dir.is_dir() {
        bail!(
            "dist/{target}/ not found at {}.\n\n\
             Build the bundled runtime first:\n\n    scripts/build-dist.sh\n\n\
             This produces a per-target directory containing php and branchfs.so.",
            dist_dir.display()
        );
    }

    for required in ["bin/php"] {
        let path = dist_dir.join(required);
        if !path.is_file() {
            bail!("missing {} (required for runtime bundle)", path.display());
        }
    }

    println!("cargo:rerun-if-changed={}", dist_dir.display());
    for rel in [
        "scripts",
        "sql",
        "vendor",
        "wp-plugin",
        "e2e/router.php",
        "e2e/bootstrap_wp.php",
        "e2e/wp.zip",
    ] {
        println!(
            "cargo:rerun-if-changed={}",
            repo_root.join(rel).display()
        );
    }

    let out_dir = PathBuf::from(env::var("OUT_DIR")?);
    let bundle_path = out_dir.join("forkpress-runtime.tar.gz");
    build_bundle(repo_root, &dist_dir, &bundle_path)?;

    println!(
        "cargo:rustc-env=FORKPRESS_RUNTIME_BUNDLE={}",
        bundle_path.display()
    );

    Ok(())
}

fn build_bundle(repo_root: &Path, dist_dir: &Path, out: &Path) -> Result<()> {
    let file = File::create(out)
        .with_context(|| format!("failed to create runtime bundle at {}", out.display()))?;
    let encoder = GzEncoder::new(file, Compression::default());
    let mut tar = Builder::new(encoder);

    add_tree(&mut tar, repo_root, "scripts")?;
    add_tree(&mut tar, repo_root, "sql")?;
    add_tree(&mut tar, repo_root, "vendor")?;
    add_tree(&mut tar, repo_root, "wp-plugin")?;
    add_file(&mut tar, repo_root, "e2e/router.php")?;
    add_file(&mut tar, repo_root, "e2e/bootstrap_wp.php")?;
    add_file(&mut tar, repo_root, "e2e/wp.zip")?;

    add_file_as(&mut tar, &dist_dir.join("bin/php"), "portable-runtime/bin/php")?;

    tar.finish()?;
    let encoder = tar.into_inner()?;
    encoder.finish()?;
    Ok(())
}

fn add_tree(tar: &mut Builder<GzEncoder<File>>, repo_root: &Path, rel: &str) -> Result<()> {
    let root = repo_root.join(rel);
    for entry in WalkDir::new(&root) {
        let entry = entry?;
        let path = entry.path();
        let rel_path = path
            .strip_prefix(repo_root)
            .with_context(|| format!("{} is not under {}", path.display(), repo_root.display()))?;

        if entry.file_type().is_dir() {
            tar.append_dir(rel_path, path)?;
        } else if entry.file_type().is_file() {
            tar.append_path_with_name(path, rel_path)?;
        }
    }
    Ok(())
}

fn add_file(tar: &mut Builder<GzEncoder<File>>, repo_root: &Path, rel: &str) -> Result<()> {
    let path = repo_root.join(rel);
    tar.append_path_with_name(&path, rel)?;
    Ok(())
}

fn add_file_as(tar: &mut Builder<GzEncoder<File>>, source: &Path, dest: &str) -> Result<()> {
    tar.append_path_with_name(source, dest)?;
    Ok(())
}
