use anyhow::{Context, Result, bail};
use flate2::Compression;
use flate2::write::GzEncoder;
use std::env;
use std::fs::{self, File};
use std::path::{Path, PathBuf};
use std::process::Command;
use tar::Builder;
use walkdir::WalkDir;

fn main() -> Result<()> {
    println!("cargo:rustc-check-cfg=cfg(forkpress_embedded_zfs)");

    let manifest_dir = PathBuf::from(env::var("CARGO_MANIFEST_DIR")?);
    let repo_root = manifest_dir
        .parent()
        .context("forkpress crate should live directly under the repo root")?;
    let target = env::var("TARGET").context("TARGET env var missing (set by cargo)")?;

    if supports_embedded_zfs(&target) && env::var_os("FORKPRESS_DISABLE_EMBEDDED_ZFS").is_none() {
        build_embedded_zfs(repo_root, &target)?;
        println!("cargo:rustc-cfg=forkpress_embedded_zfs");
    } else {
        println!("cargo:warning=embedded ZFS engine is disabled for target {target}");
    }

    // Allow skipping the dist build entirely for `cargo check` runs:
    //   FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo check -p forkpress
    if env::var_os("FORKPRESS_RUNTIME_BUNDLE").is_some() {
        return Ok(());
    }

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
        "runtime/router.php",
        "runtime/router_zfs.php",
        "runtime/router_cas.php",
        "runtime/bootstrap_wp.php",
        "runtime/bootstrap_zfs_wp.php",
        "runtime/bootstrap_cas_wp.php",
        "runtime/managed_wp_files.php",
        "runtime/refresh_wp_files.php",
        "runtime/wp.zip",
    ] {
        println!("cargo:rerun-if-changed={}", repo_root.join(rel).display());
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

fn build_embedded_zfs(repo_root: &Path, target: &str) -> Result<()> {
    let engine_dir = repo_root.join("forkpress/zfs-engine");
    let work_root = repo_root.join(".build/zfs-engine");
    let target_build = work_root.join(target);
    fs::create_dir_all(&target_build)
        .with_context(|| format!("failed to create {}", target_build.display()))?;

    let experiment = ensure_zfs_experiment_source(&work_root)?;
    let zlib_dir = ensure_zlib_built(&work_root, target)?;
    let cc = target_cc(target);
    let ar = target_ar(target);

    println!(
        "cargo:rerun-if-changed={}",
        engine_dir.join("Makefile").display()
    );
    println!(
        "cargo:rerun-if-changed={}",
        engine_dir.join("forkpress_zfs.c").display()
    );
    println!(
        "cargo:rerun-if-changed={}",
        engine_dir.join("include/libintl.h").display()
    );
    println!(
        "cargo:rerun-if-changed={}",
        engine_dir.join("include/sys/endian.h").display()
    );
    println!(
        "cargo:rerun-if-changed={}",
        engine_dir.join("include/sys/simd.h").display()
    );
    println!(
        "cargo:rerun-if-changed={}",
        engine_dir.join("include/sys/sysmacros.h").display()
    );
    println!(
        "cargo:rerun-if-changed={}",
        engine_dir.join("include/sys/stdtypes.h").display()
    );
    println!(
        "cargo:rerun-if-changed={}",
        engine_dir.join("include/sys/types32.h").display()
    );
    println!(
        "cargo:rerun-if-changed={}",
        engine_dir.join("include/sys/uio.h").display()
    );
    println!(
        "cargo:rerun-if-env-changed={}",
        target_env_key("CC", target)
    );
    println!(
        "cargo:rerun-if-env-changed={}",
        target_env_key("AR", target)
    );
    println!("cargo:rerun-if-env-changed=FORKPRESS_DISABLE_EMBEDDED_ZFS");

    let status = Command::new("make")
        .arg("-f")
        .arg(engine_dir.join("Makefile"))
        .arg("libforkpress_zfs")
        .arg(format!("-j{}", build_jobs()))
        .env("BUILD", &target_build)
        .env("EXPERIMENT", &experiment)
        .env("ZFS", experiment.join("upstream/zfs"))
        .env("WRAPPER", engine_dir.join("forkpress_zfs.c"))
        .env("ZLIB_DIR", &zlib_dir)
        .env("CC", &cc)
        .env("AR", &ar)
        .env("MODE", "release")
        .env(
            "ZFS_ENGINE_MUSL",
            if target.contains("musl") { "1" } else { "0" },
        )
        .env(
            "ZFS_ENGINE_DARWIN",
            if target.contains("apple-darwin") {
                "1"
            } else {
                "0"
            },
        )
        .status()
        .context("failed to run make for embedded ZFS engine")?;
    if !status.success() {
        bail!("embedded ZFS engine build failed with {status}");
    }

    println!("cargo:rustc-link-search=native={}", target_build.display());
    println!("cargo:rustc-link-lib=static=forkpress_zfs");
    if target.contains("linux") && !target.contains("musl") {
        println!("cargo:rustc-link-lib=pthread");
    }
    Ok(())
}

fn supports_embedded_zfs(target: &str) -> bool {
    target.contains("linux") || target.contains("apple-darwin")
}

fn ensure_zfs_experiment_source(work_root: &Path) -> Result<PathBuf> {
    let sources = work_root.join("sources");
    let experiments = sources.join("experiments");
    let experiment = experiments.join("zfs-wasm-real");
    let openzfs = experiment.join("upstream/zfs");

    if !experiment.join("src/shim/disabled_features.c").is_file() {
        if experiments.exists() {
            fs::remove_dir_all(&experiments)
                .with_context(|| format!("failed to remove {}", experiments.display()))?;
        }
        fs::create_dir_all(&sources)
            .with_context(|| format!("failed to create {}", sources.display()))?;
        run(
            Command::new("git")
                .arg("clone")
                .arg("--depth")
                .arg("1")
                .arg("--filter=blob:none")
                .arg("--sparse")
                .arg("https://github.com/adamziel/experiments")
                .arg(&experiments),
            "clone adamziel/experiments for embedded ZFS",
        )?;
        run(
            Command::new("git")
                .arg("-C")
                .arg(&experiments)
                .arg("sparse-checkout")
                .arg("set")
                .arg("zfs-wasm-real"),
            "configure sparse checkout for zfs-wasm-real",
        )?;
    }

    if !openzfs.join("module/zfs/spa.c").is_file() {
        run(
            Command::new("bash")
                .arg("scripts/fetch-upstream.sh")
                .current_dir(&experiment),
            "fetch pinned OpenZFS source for embedded ZFS",
        )?;
    }

    Ok(experiment)
}

fn ensure_zlib_built(work_root: &Path, target: &str) -> Result<PathBuf> {
    let sources = work_root.join("sources");
    let zlib_source = sources.join("zlib-1.3.1");
    if !zlib_source.join("zlib.h").is_file() {
        fs::create_dir_all(&sources)
            .with_context(|| format!("failed to create {}", sources.display()))?;
        let tarball = sources.join("zlib-1.3.1.tar.gz");
        run(
            Command::new("curl")
                .arg("-L")
                .arg("--fail")
                .arg("--silent")
                .arg("--show-error")
                .arg("https://github.com/madler/zlib/releases/download/v1.3.1/zlib-1.3.1.tar.gz")
                .arg("-o")
                .arg(&tarball),
            "download zlib for embedded ZFS",
        )?;
        run(
            Command::new("tar")
                .arg("-xzf")
                .arg(&tarball)
                .arg("-C")
                .arg(&sources),
            "extract zlib for embedded ZFS",
        )?;
    }

    let build_dir = work_root.join(target).join("zlib-1.3.1");
    if build_dir.join("libz.a").is_file() {
        return Ok(build_dir);
    }
    if build_dir.exists() {
        fs::remove_dir_all(&build_dir)
            .with_context(|| format!("failed to remove {}", build_dir.display()))?;
    }
    run(
        Command::new("cp")
            .arg("-R")
            .arg(&zlib_source)
            .arg(&build_dir),
        "copy zlib source for target build",
    )?;
    let cc = target_cc(target);
    let ar = target_ar(target);
    run(
        Command::new("./configure")
            .arg("--static")
            .env("CC", &cc)
            .env("AR", &ar)
            .current_dir(&build_dir),
        "configure zlib for embedded ZFS",
    )?;
    run(
        Command::new("make")
            .arg(format!("-j{}", build_jobs()))
            .current_dir(&build_dir),
        "build zlib for embedded ZFS",
    )?;
    Ok(build_dir)
}

fn run(command: &mut Command, description: &str) -> Result<()> {
    let status = command
        .status()
        .with_context(|| format!("failed to {description}"))?;
    if !status.success() {
        bail!("{description} failed with {status}");
    }
    Ok(())
}

fn build_jobs() -> String {
    env::var("NUM_JOBS").unwrap_or_else(|_| "2".to_string())
}

fn target_cc(target: &str) -> String {
    if let Ok(value) = env::var(target_env_key("CC", target)) {
        return value;
    }
    if let Ok(value) = env::var("CC") {
        return value;
    }
    match target {
        "x86_64-unknown-linux-musl" => find_tool(&["x86_64-linux-musl-gcc", "musl-gcc"]),
        "aarch64-unknown-linux-musl" => find_tool(&["aarch64-linux-musl-gcc", "musl-gcc"]),
        _ => "cc".to_string(),
    }
}

fn target_ar(target: &str) -> String {
    if let Ok(value) = env::var(target_env_key("AR", target)) {
        return value;
    }
    if let Ok(value) = env::var("AR") {
        return value;
    }
    "ar".to_string()
}

fn target_env_key(prefix: &str, target: &str) -> String {
    format!("{}_{}", prefix, target.replace('-', "_"))
}

fn find_tool(candidates: &[&str]) -> String {
    for candidate in candidates {
        let found = Command::new("sh")
            .arg("-c")
            .arg(format!("command -v {candidate} >/dev/null 2>&1"))
            .status()
            .map(|status| status.success())
            .unwrap_or(false);
        if found {
            return (*candidate).to_string();
        }
    }
    candidates.last().copied().unwrap_or("cc").to_string()
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
    add_file(&mut tar, repo_root, "runtime/router.php")?;
    add_file(&mut tar, repo_root, "runtime/router_zfs.php")?;
    add_file(&mut tar, repo_root, "runtime/router_cas.php")?;
    add_file(&mut tar, repo_root, "runtime/bootstrap_wp.php")?;
    add_file(&mut tar, repo_root, "runtime/bootstrap_zfs_wp.php")?;
    add_file(&mut tar, repo_root, "runtime/bootstrap_cas_wp.php")?;
    add_file(&mut tar, repo_root, "runtime/managed_wp_files.php")?;
    add_file(&mut tar, repo_root, "runtime/refresh_wp_files.php")?;
    add_file(&mut tar, repo_root, "runtime/wp.zip")?;

    add_file_as(
        &mut tar,
        &dist_dir.join("bin/php"),
        "portable-runtime/bin/php",
    )?;

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
