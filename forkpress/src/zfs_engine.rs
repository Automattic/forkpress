use anyhow::{Result, bail};
use std::path::Path;

#[cfg(forkpress_embedded_zfs)]
use anyhow::{Context, anyhow};
#[cfg(forkpress_embedded_zfs)]
use std::ffi::CString;
#[cfg(forkpress_embedded_zfs)]
use std::fs::{self, OpenOptions};
#[cfg(forkpress_embedded_zfs)]
use std::io;
#[cfg(forkpress_embedded_zfs)]
use std::os::raw::{c_char, c_int, c_void};

#[derive(Debug, Clone)]
pub struct SmokeReport {
    pub read_main: String,
    pub read_clone: String,
}

#[cfg(forkpress_embedded_zfs)]
mod ffi {
    use super::*;

    unsafe extern "C" {
        pub fn forkpress_zfs_init() -> c_int;
        pub fn forkpress_zfs_fini() -> c_int;
        pub fn forkpress_zfs_pool_create(pool: *const c_char, backing: *const c_char) -> c_int;
        pub fn forkpress_zfs_pool_import(pool: *const c_char, backing: *const c_char) -> c_int;
        pub fn forkpress_zfs_pool_export(pool: *const c_char) -> c_int;
        pub fn forkpress_zfs_ds_create(fullname: *const c_char) -> c_int;
        pub fn forkpress_zfs_snap(ds: *const c_char, snapname: *const c_char) -> c_int;
        pub fn forkpress_zfs_clone(snap: *const c_char, newds: *const c_char) -> c_int;
        pub fn forkpress_zfs_file_write(
            ds: *const c_char,
            path: *const c_char,
            buf: *const c_void,
            len: usize,
        ) -> c_int;
        pub fn forkpress_zfs_file_read(
            ds: *const c_char,
            path: *const c_char,
            buf: *mut c_void,
            cap: usize,
            out_len: *mut usize,
        ) -> c_int;
    }
}

pub fn available() -> bool {
    cfg!(forkpress_embedded_zfs)
}

pub fn smoke(pool_img: &Path, pool_size: u64) -> Result<SmokeReport> {
    smoke_impl(pool_img, pool_size)
}

#[cfg(forkpress_embedded_zfs)]
fn smoke_impl(pool_img: &Path, pool_size: u64) -> Result<SmokeReport> {
    if pool_size < 64 * 1024 * 1024 {
        bail!("embedded ZFS smoke needs a pool image of at least 64 MiB");
    }
    if let Some(parent) = pool_img.parent() {
        fs::create_dir_all(parent)
            .with_context(|| format!("failed to create {}", parent.display()))?;
    }
    if pool_img.exists() {
        fs::remove_file(pool_img)
            .with_context(|| format!("failed to remove {}", pool_img.display()))?;
    }
    OpenOptions::new()
        .create_new(true)
        .write(true)
        .open(pool_img)
        .with_context(|| format!("failed to create {}", pool_img.display()))?
        .set_len(pool_size)
        .with_context(|| format!("failed to size {}", pool_img.display()))?;

    let engine = Engine::new()?;
    let pool = cstr("forkpress")?;
    let backing = cstr_path(pool_img)?;
    let main_ds = cstr("forkpress/main")?;
    let branch_ds = cstr("forkpress/branch")?;
    let snap_name = cstr("smoke")?;
    let snap_full = cstr("forkpress/main@smoke")?;
    let file_path = cstr("/hello.txt")?;
    let payload = b"hello embedded zfs";

    check("pool_create", unsafe {
        ffi::forkpress_zfs_pool_create(pool.as_ptr(), backing.as_ptr())
    })?;
    check("ds_create", unsafe {
        ffi::forkpress_zfs_ds_create(main_ds.as_ptr())
    })?;
    check("file_write", unsafe {
        ffi::forkpress_zfs_file_write(
            main_ds.as_ptr(),
            file_path.as_ptr(),
            payload.as_ptr().cast(),
            payload.len(),
        )
    })?;
    let read_main = read_file(&main_ds, &file_path)?;
    check("snap", unsafe {
        ffi::forkpress_zfs_snap(main_ds.as_ptr(), snap_name.as_ptr())
    })?;
    check("clone", unsafe {
        ffi::forkpress_zfs_clone(snap_full.as_ptr(), branch_ds.as_ptr())
    })?;
    check("pool_export", unsafe {
        ffi::forkpress_zfs_pool_export(pool.as_ptr())
    })?;
    check("pool_import", unsafe {
        ffi::forkpress_zfs_pool_import(pool.as_ptr(), backing.as_ptr())
    })?;
    let read_clone = read_file(&branch_ds, &file_path)?;

    drop(engine);

    Ok(SmokeReport {
        read_main,
        read_clone,
    })
}

#[cfg(not(forkpress_embedded_zfs))]
fn smoke_impl(_pool_img: &Path, _pool_size: u64) -> Result<SmokeReport> {
    bail!("embedded ZFS engine was not built for this forkpress target")
}

#[cfg(forkpress_embedded_zfs)]
struct Engine;

#[cfg(forkpress_embedded_zfs)]
impl Engine {
    fn new() -> Result<Self> {
        check("init", unsafe { ffi::forkpress_zfs_init() })?;
        Ok(Self)
    }
}

#[cfg(forkpress_embedded_zfs)]
impl Drop for Engine {
    fn drop(&mut self) {
        let _ = unsafe { ffi::forkpress_zfs_fini() };
    }
}

#[cfg(forkpress_embedded_zfs)]
fn read_file(ds: &CString, path: &CString) -> Result<String> {
    let mut buf = vec![0u8; 4096];
    let mut out_len = 0usize;
    check("file_read", unsafe {
        ffi::forkpress_zfs_file_read(
            ds.as_ptr(),
            path.as_ptr(),
            buf.as_mut_ptr().cast(),
            buf.len(),
            &mut out_len,
        )
    })?;
    buf.truncate(out_len);
    String::from_utf8(buf).context("embedded ZFS smoke read non-UTF-8 bytes")
}

#[cfg(forkpress_embedded_zfs)]
fn cstr(value: &str) -> Result<CString> {
    CString::new(value).map_err(|_| anyhow!("embedded ZFS string contains a NUL byte"))
}

#[cfg(forkpress_embedded_zfs)]
fn cstr_path(path: &Path) -> Result<CString> {
    cstr(&path.to_string_lossy())
}

#[cfg(forkpress_embedded_zfs)]
fn check(op: &str, rc: c_int) -> Result<()> {
    if rc == 0 {
        return Ok(());
    }
    let io_err = io::Error::from_raw_os_error(rc);
    Err(anyhow!(
        "embedded ZFS {op} failed with errno {rc}: {io_err}"
    ))
}

#[cfg(all(test, forkpress_embedded_zfs))]
mod tests {
    use super::*;

    #[test]
    fn smoke_exercises_embedded_engine() {
        let dir =
            std::env::temp_dir().join(format!("forkpress-zfs-engine-test-{}", std::process::id()));
        let _ = fs::remove_dir_all(&dir);
        fs::create_dir_all(&dir).unwrap();

        let report = smoke(&dir.join("pool.img"), 128 * 1024 * 1024).unwrap();
        assert_eq!(report.read_main, "hello embedded zfs");
        assert_eq!(report.read_clone, "hello embedded zfs");

        let _ = fs::remove_dir_all(&dir);
    }
}
