use forkpress_cas_store as store;
use libc::{c_char, c_int, size_t};
use std::ffi::CStr;
use std::path::PathBuf;
use std::ptr;

pub struct FpCasHandle {
    store_path: PathBuf,
}

fn cstr_to_str<'a>(value: *const c_char) -> Option<&'a str> {
    if value.is_null() {
        return None;
    }
    unsafe { CStr::from_ptr(value) }.to_str().ok()
}

fn with_handle<T>(handle: *mut FpCasHandle, f: impl FnOnce(&FpCasHandle) -> T) -> Option<T> {
    if handle.is_null() {
        return None;
    }
    Some(f(unsafe { &*handle }))
}

fn result_code(result: anyhow::Result<impl Sized>) -> c_int {
    match result {
        Ok(_) => 0,
        Err(_) => -1,
    }
}

#[unsafe(no_mangle)]
pub extern "C" fn fp_cas_open(path: *const c_char) -> *mut FpCasHandle {
    let Some(path) = cstr_to_str(path) else {
        return ptr::null_mut();
    };
    Box::into_raw(Box::new(FpCasHandle {
        store_path: PathBuf::from(path),
    }))
}

#[unsafe(no_mangle)]
pub extern "C" fn fp_cas_close(handle: *mut FpCasHandle) {
    if !handle.is_null() {
        unsafe {
            drop(Box::from_raw(handle));
        }
    }
}

#[unsafe(no_mangle)]
pub extern "C" fn fp_cas_free(ptr: *mut u8, len: size_t) {
    if !ptr.is_null() {
        unsafe {
            drop(Vec::from_raw_parts(ptr, len, len));
        }
    }
}

#[unsafe(no_mangle)]
pub extern "C" fn fp_cas_branch_exists(handle: *mut FpCasHandle, branch: *const c_char) -> c_int {
    let Some(branch) = cstr_to_str(branch) else {
        return 0;
    };
    with_handle(handle, |handle| {
        store::branch_exists(&handle.store_path, branch).unwrap_or(false) as c_int
    })
    .unwrap_or(0)
}

#[unsafe(no_mangle)]
pub extern "C" fn fp_cas_create_branch(
    handle: *mut FpCasHandle,
    branch: *const c_char,
    parent: *const c_char,
) -> c_int {
    let Some(branch) = cstr_to_str(branch) else {
        return -1;
    };
    with_handle(handle, |handle| {
        if store::branch_exists(&handle.store_path, branch).unwrap_or(false) {
            return 0;
        }
        if let Some(parent) = cstr_to_str(parent) {
            result_code(store::clone_branch_manifest(
                &handle.store_path,
                parent,
                branch,
            ))
        } else {
            result_code(store::create_empty_branch(&handle.store_path, branch))
        }
    })
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub extern "C" fn fp_cas_read_file(
    handle: *mut FpCasHandle,
    branch: *const c_char,
    path: *const c_char,
    out_data: *mut *mut u8,
    out_len: *mut size_t,
) -> c_int {
    let (Some(branch), Some(path)) = (cstr_to_str(branch), cstr_to_str(path)) else {
        return -1;
    };
    if out_data.is_null() || out_len.is_null() {
        return -1;
    }
    with_handle(handle, |handle| {
        let Ok(data) = store::read_file_from_branch(&handle.store_path, branch, path) else {
            return -1;
        };
        let len = data.len();
        let mut data = data.into_boxed_slice();
        let ptr = data.as_mut_ptr();
        std::mem::forget(data);
        unsafe {
            *out_data = ptr;
            *out_len = len;
        }
        0
    })
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub extern "C" fn fp_cas_write_file(
    handle: *mut FpCasHandle,
    branch: *const c_char,
    path: *const c_char,
    data: *const u8,
    len: size_t,
) -> c_int {
    let (Some(branch), Some(path)) = (cstr_to_str(branch), cstr_to_str(path)) else {
        return -1;
    };
    if data.is_null() && len > 0 {
        return -1;
    }
    let data = if len == 0 {
        &[]
    } else {
        unsafe { std::slice::from_raw_parts(data, len) }
    };
    with_handle(handle, |handle| {
        result_code(store::write_file_to_branch(
            &handle.store_path,
            branch,
            path,
            data,
        ))
    })
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub extern "C" fn fp_cas_mkdir(
    handle: *mut FpCasHandle,
    branch: *const c_char,
    path: *const c_char,
) -> c_int {
    let (Some(branch), Some(path)) = (cstr_to_str(branch), cstr_to_str(path)) else {
        return -1;
    };
    with_handle(handle, |handle| {
        result_code(store::mkdir_in_branch(&handle.store_path, branch, path))
    })
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub extern "C" fn fp_cas_unlink(
    handle: *mut FpCasHandle,
    branch: *const c_char,
    path: *const c_char,
) -> c_int {
    let (Some(branch), Some(path)) = (cstr_to_str(branch), cstr_to_str(path)) else {
        return -1;
    };
    with_handle(handle, |handle| {
        result_code(store::unlink_path(&handle.store_path, branch, path))
    })
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub extern "C" fn fp_cas_rename(
    handle: *mut FpCasHandle,
    branch: *const c_char,
    from: *const c_char,
    to: *const c_char,
) -> c_int {
    let (Some(branch), Some(from), Some(to)) =
        (cstr_to_str(branch), cstr_to_str(from), cstr_to_str(to))
    else {
        return -1;
    };
    with_handle(handle, |handle| {
        result_code(store::rename_path(&handle.store_path, branch, from, to))
    })
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub extern "C" fn fp_cas_stat(
    handle: *mut FpCasHandle,
    branch: *const c_char,
    path: *const c_char,
    out_is_dir: *mut c_int,
    out_len: *mut size_t,
) -> c_int {
    let (Some(branch), Some(path)) = (cstr_to_str(branch), cstr_to_str(path)) else {
        return -1;
    };
    if out_is_dir.is_null() || out_len.is_null() {
        return -1;
    }
    with_handle(handle, |handle| {
        let Ok(stat) = store::stat_path(&handle.store_path, branch, path) else {
            return -1;
        };
        unsafe {
            *out_is_dir = stat.is_dir as c_int;
            *out_len = stat.len as size_t;
        }
        0
    })
    .unwrap_or(-1)
}

#[unsafe(no_mangle)]
pub extern "C" fn fp_cas_list_dir(
    handle: *mut FpCasHandle,
    branch: *const c_char,
    path: *const c_char,
    out_data: *mut *mut u8,
    out_len: *mut size_t,
) -> c_int {
    let (Some(branch), Some(path)) = (cstr_to_str(branch), cstr_to_str(path)) else {
        return -1;
    };
    if out_data.is_null() || out_len.is_null() {
        return -1;
    }
    with_handle(handle, |handle| {
        let Ok(entries) = store::list_dir(&handle.store_path, branch, path) else {
            return -1;
        };
        let mut text = String::new();
        for entry in entries {
            text.push_str(&entry.name);
            text.push('\n');
        }
        let len = text.len();
        let mut data = text.into_bytes().into_boxed_slice();
        let ptr = data.as_mut_ptr();
        std::mem::forget(data);
        unsafe {
            *out_data = ptr;
            *out_len = len;
        }
        0
    })
    .unwrap_or(-1)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::ffi::CString;

    fn temp_store() -> PathBuf {
        std::env::temp_dir().join(format!(
            "forkpress-cas-ffi-test-{}-{}.redb",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ))
    }

    #[test]
    fn c_abi_reads_and_writes_branch_files() {
        let store = temp_store();
        let store_c = CString::new(store.to_string_lossy().as_bytes()).unwrap();
        let branch = CString::new("main").unwrap();
        let parent = ptr::null();
        let path = CString::new("wp-content/example.txt").unwrap();
        let dir = CString::new("wp-content").unwrap();
        let data = b"hello from cas ffi";

        let handle = fp_cas_open(store_c.as_ptr());
        assert!(!handle.is_null());
        assert_eq!(fp_cas_create_branch(handle, branch.as_ptr(), parent), 0);
        assert_eq!(
            fp_cas_write_file(
                handle,
                branch.as_ptr(),
                path.as_ptr(),
                data.as_ptr(),
                data.len()
            ),
            0
        );

        let mut out_ptr: *mut u8 = ptr::null_mut();
        let mut out_len: size_t = 0;
        assert_eq!(
            fp_cas_read_file(
                handle,
                branch.as_ptr(),
                path.as_ptr(),
                &mut out_ptr,
                &mut out_len
            ),
            0
        );
        assert_eq!(out_len, data.len());
        let out = unsafe { std::slice::from_raw_parts(out_ptr, out_len) };
        assert_eq!(out, data);
        fp_cas_free(out_ptr, out_len);

        let mut is_dir: c_int = 0;
        let mut len: size_t = 0;
        assert_eq!(
            fp_cas_stat(handle, branch.as_ptr(), dir.as_ptr(), &mut is_dir, &mut len),
            0
        );
        assert_eq!(is_dir, 1);

        fp_cas_close(handle);
        let _ = std::fs::remove_file(store);
    }
}
