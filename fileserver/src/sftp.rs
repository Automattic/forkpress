use crate::store::Store;
use anyhow::Result;
use russh::server::{Auth, Msg, Server as _, Session};
use russh::{Channel, ChannelId, MethodKind, MethodSet};
use russh_sftp::protocol::{
    Attrs, Data, File, FileAttributes, Handle, Name, OpenFlags, Status, StatusCode, Version,
};
use std::collections::HashMap;
use std::sync::Arc;
use tokio::sync::Mutex;

struct SftpSession {
    store: Arc<Store>,
    handles: HashMap<String, FileHandle>,
    next_handle: u64,
    /// Authenticated role ("admin"/"write"/"read"/"anon"). Writes are
    /// rejected when the role is "read".
    role: String,
}

struct FileHandle {
    branch: String,
    path: String,
    is_dir: bool,
    write_buf: Vec<u8>,
    did_write: bool,
    dir_offset: usize,
    dir_entries: Vec<File>,
}

impl SftpSession {
    fn new(store: Arc<Store>, role: String) -> Self {
        Self { store, handles: HashMap::new(), next_handle: 1, role }
    }

    fn is_read_only(&self) -> bool {
        self.role == "read"
    }

    fn alloc_handle(&mut self) -> String {
        let h = format!("h{}", self.next_handle);
        self.next_handle += 1;
        h
    }
}

fn parse_branch_path(path: &str) -> Option<(String, String)> {
    let p = path.trim_start_matches('/');
    if p.is_empty() {
        return None;
    }
    let mut parts = p.splitn(2, '/');
    let branch = parts.next()?.to_string();
    let rest = parts.next().unwrap_or("").to_string();
    Some((branch, rest))
}

fn make_attrs(size: u64, mtime: u32, is_dir: bool) -> FileAttributes {
    let perm: u32 = if is_dir { 0o40755 } else { 0o100644 };
    FileAttributes {
        size: Some(size),
        uid: Some(1000),
        user: None,
        gid: Some(1000),
        group: None,
        permissions: Some(perm),
        atime: Some(mtime),
        mtime: Some(mtime),
    }
}

impl russh_sftp::server::Handler for SftpSession {
    type Error = StatusCode;

    fn unimplemented(&self) -> Self::Error {
        StatusCode::OpUnsupported
    }

    async fn init(
        &mut self,
        _version: u32,
        _extensions: HashMap<String, String>,
    ) -> Result<Version, Self::Error> {
        Ok(Version::new())
    }

    async fn open(
        &mut self,
        id: u32,
        filename: String,
        pflags: OpenFlags,
        _attrs: FileAttributes,
    ) -> Result<Handle, Self::Error> {
        let (branch, path) = parse_branch_path(&filename).ok_or(StatusCode::NoSuchFile)?;
        let write_buf = if pflags.contains(OpenFlags::WRITE) || pflags.contains(OpenFlags::CREATE) {
            Vec::new()
        } else {
            Vec::new()
        };
        let handle = self.alloc_handle();
        self.handles.insert(
            handle.clone(),
            FileHandle {
                branch,
                path,
                is_dir: false,
                write_buf,
                did_write: false,
                dir_offset: 0,
                dir_entries: Vec::new(),
            },
        );
        Ok(Handle { id, handle })
    }

    async fn close(&mut self, id: u32, handle: String) -> Result<Status, Self::Error> {
        if let Some(h) = self.handles.remove(&handle) {
            if h.did_write && !h.is_dir && !h.path.is_empty() {
                if self.is_read_only() {
                    return Err(StatusCode::PermissionDenied);
                }
                self.store
                    .write_file(&h.branch, &h.path, &h.write_buf, "sftp")
                    .map_err(|_| StatusCode::Failure)?;
            }
        }
        Ok(Status {
            id,
            status_code: StatusCode::Ok,
            error_message: "Ok".into(),
            language_tag: "en".into(),
        })
    }

    async fn read(
        &mut self,
        id: u32,
        handle: String,
        offset: u64,
        len: u32,
    ) -> Result<Data, Self::Error> {
        let h = self.handles.get(&handle).ok_or(StatusCode::NoSuchFile)?;
        let data = if h.did_write {
            h.write_buf.clone()
        } else {
            self.store.read_file(&h.branch, &h.path).map_err(|_| StatusCode::NoSuchFile)?
        };
        let start = offset as usize;
        if start >= data.len() {
            return Err(StatusCode::Eof);
        }
        let end = std::cmp::min(start + len as usize, data.len());
        Ok(Data { id, data: data[start..end].to_vec() })
    }

    async fn write(
        &mut self,
        id: u32,
        handle: String,
        offset: u64,
        data: Vec<u8>,
    ) -> Result<Status, Self::Error> {
        if self.is_read_only() {
            return Err(StatusCode::PermissionDenied);
        }
        let h = self.handles.get_mut(&handle).ok_or(StatusCode::NoSuchFile)?;
        let off = offset as usize;
        let needed = off + data.len();
        if h.write_buf.len() < needed {
            h.write_buf.resize(needed, 0);
        }
        h.write_buf[off..off + data.len()].copy_from_slice(&data);
        h.did_write = true;
        Ok(Status {
            id,
            status_code: StatusCode::Ok,
            error_message: "Ok".into(),
            language_tag: "en".into(),
        })
    }

    async fn opendir(&mut self, id: u32, path: String) -> Result<Handle, Self::Error> {
        let (branch, dir_path) = if path.trim_matches('/').is_empty() {
            (String::new(), String::new())
        } else {
            parse_branch_path(&path).unwrap_or_default()
        };
        let handle = self.alloc_handle();
        self.handles.insert(
            handle.clone(),
            FileHandle {
                branch,
                path: dir_path,
                is_dir: true,
                write_buf: Vec::new(),
                did_write: false,
                dir_offset: 0,
                dir_entries: Vec::new(),
            },
        );
        Ok(Handle { id, handle })
    }

    async fn readdir(&mut self, id: u32, handle: String) -> Result<Name, Self::Error> {
        let h = self.handles.get_mut(&handle).ok_or(StatusCode::NoSuchFile)?;
        if h.dir_offset == 0 && h.dir_entries.is_empty() {
            if h.branch.is_empty() {
                // Root listing: show all branches as directories
                let branches = self.store.list_branches().map_err(|_| StatusCode::Failure)?;
                h.dir_entries = branches.into_iter()
                    .map(|name| File::new(name, make_attrs(0, 0, true)))
                    .collect();
            } else {
                let all = self.store.list_files(&h.branch).map_err(|_| StatusCode::Failure)?;
                let prefix = if h.path.is_empty() {
                    String::new()
                } else {
                    format!("{}/", h.path.trim_matches('/'))
                };
                h.dir_entries = all
                    .iter()
                    .filter(|e| {
                        let p = e.path.trim_matches('/');
                        if prefix.is_empty() {
                            !p.contains('/')
                        } else {
                            p.starts_with(&prefix) && !p[prefix.len()..].contains('/')
                        }
                    })
                    .map(|e| {
                        let name = e
                            .path
                            .trim_matches('/')
                            .rsplit('/')
                            .next()
                            .unwrap_or("")
                            .to_string();
                        File::new(name, make_attrs(e.size as u64, e.mtime as u32, e.is_dir))
                    })
                    .collect();
            }
        }
        if h.dir_offset >= h.dir_entries.len() {
            return Err(StatusCode::Eof);
        }
        let batch = h.dir_entries[h.dir_offset..].to_vec();
        h.dir_offset = h.dir_entries.len();
        Ok(Name { id, files: batch })
    }

    async fn lstat(&mut self, id: u32, path: String) -> Result<Attrs, Self::Error> {
        stat_path(&self.store, id, &path)
    }

    async fn stat(&mut self, id: u32, path: String) -> Result<Attrs, Self::Error> {
        stat_path(&self.store, id, &path)
    }

    async fn fstat(&mut self, id: u32, handle: String) -> Result<Attrs, Self::Error> {
        let h = self.handles.get(&handle).ok_or(StatusCode::NoSuchFile)?;
        if h.path.is_empty() || h.is_dir {
            return Ok(Attrs { id, attrs: make_attrs(0, 0, true) });
        }
        let files = self.store.list_files(&h.branch).map_err(|_| StatusCode::Failure)?;
        let fp = h.path.trim_matches('/').to_string();
        files
            .iter()
            .find(|e| e.path.trim_matches('/') == fp)
            .map(|e| Attrs { id, attrs: make_attrs(e.size as u64, e.mtime as u32, e.is_dir) })
            .ok_or(StatusCode::NoSuchFile)
    }

    async fn mkdir(
        &mut self,
        id: u32,
        path: String,
        _attrs: FileAttributes,
    ) -> Result<Status, Self::Error> {
        if self.is_read_only() {
            return Err(StatusCode::PermissionDenied);
        }
        let (branch, dir_path) = parse_branch_path(&path).ok_or(StatusCode::NoSuchFile)?;
        self.store
            .create_dir(&branch, dir_path.trim_matches('/'))
            .map_err(|_| StatusCode::Failure)?;
        Ok(Status {
            id,
            status_code: StatusCode::Ok,
            error_message: "Ok".into(),
            language_tag: "en".into(),
        })
    }

    async fn remove(&mut self, id: u32, filename: String) -> Result<Status, Self::Error> {
        if self.is_read_only() {
            return Err(StatusCode::PermissionDenied);
        }
        let (branch, path) = parse_branch_path(&filename).ok_or(StatusCode::NoSuchFile)?;
        self.store
            .delete_file(&branch, path.trim_matches('/'))
            .map_err(|_| StatusCode::Failure)?;
        Ok(Status {
            id,
            status_code: StatusCode::Ok,
            error_message: "Ok".into(),
            language_tag: "en".into(),
        })
    }

    async fn realpath(&mut self, id: u32, path: String) -> Result<Name, Self::Error> {
        let canonical = if path.is_empty() || path == "." {
            "/".to_string()
        } else if !path.starts_with('/') {
            format!("/{}", path)
        } else {
            path
        };
        Ok(Name { id, files: vec![File::dummy(canonical)] })
    }
}

fn stat_path(store: &Arc<Store>, id: u32, path: &str) -> Result<Attrs, StatusCode> {
    if path.trim_matches('/').is_empty() {
        return Ok(Attrs { id, attrs: make_attrs(0, 0, true) });
    }
    let (branch, file_path) = parse_branch_path(path).ok_or(StatusCode::NoSuchFile)?;
    if file_path.is_empty() {
        return Ok(Attrs { id, attrs: make_attrs(0, 0, true) });
    }
    let files = store.list_files(&branch).map_err(|_| StatusCode::Failure)?;
    let fp = file_path.trim_matches('/').to_string();
    files
        .iter()
        .find(|e| e.path.trim_matches('/') == fp)
        .map(|e| Attrs { id, attrs: make_attrs(e.size as u64, e.mtime as u32, e.is_dir) })
        .ok_or(StatusCode::NoSuchFile)
}

struct SshServer {
    store: Arc<Store>,
}

struct SshHandler {
    store: Arc<Store>,
    channels: Arc<Mutex<HashMap<ChannelId, Channel<Msg>>>>,
    /// Authenticated role carried forward to the SFTP subsystem.
    role: Arc<Mutex<String>>,
}

impl russh::server::Handler for SshHandler {
    type Error = anyhow::Error;

    async fn auth_none(&mut self, _user: &str) -> Result<Auth, Self::Error> {
        if self.store.auth_enabled() {
            // Force the client to send a password.
            return Ok(Auth::Reject {
                proceed_with_methods: Some(MethodSet::from(&[MethodKind::Password][..])),
                partial_success: false,
            });
        }
        *self.role.lock().await = "admin".to_string();
        Ok(Auth::Accept)
    }

    async fn auth_password(
        &mut self,
        user: &str,
        password: &str,
    ) -> Result<Auth, Self::Error> {
        if !self.store.auth_enabled() {
            *self.role.lock().await = "admin".to_string();
            return Ok(Auth::Accept);
        }
        match self.store.verify_user_password(user, password) {
            Some(role) => {
                *self.role.lock().await = role;
                Ok(Auth::Accept)
            }
            None => Ok(Auth::Reject { proceed_with_methods: None, partial_success: false }),
        }
    }

    async fn channel_open_session(
        &mut self,
        channel: Channel<Msg>,
        _session: &mut Session,
    ) -> Result<bool, Self::Error> {
        self.channels.lock().await.insert(channel.id(), channel);
        Ok(true)
    }

    async fn channel_eof(
        &mut self,
        channel: ChannelId,
        session: &mut Session,
    ) -> Result<(), Self::Error> {
        let _ = session.close(channel);
        Ok(())
    }

    async fn subsystem_request(
        &mut self,
        channel_id: ChannelId,
        name: &str,
        session: &mut Session,
    ) -> Result<(), Self::Error> {
        if name == "sftp" {
            let channel = self.channels.lock().await.remove(&channel_id).unwrap();
            let role = self.role.lock().await.clone();
            let sftp_handler = SftpSession::new(Arc::clone(&self.store), role);
            let _ = session.channel_success(channel_id);
            russh_sftp::server::run(channel.into_stream(), sftp_handler).await;
        } else {
            let _ = session.channel_failure(channel_id);
        }
        Ok(())
    }
}

impl russh::server::Server for SshServer {
    type Handler = SshHandler;

    fn new_client(&mut self, _peer: Option<std::net::SocketAddr>) -> SshHandler {
        SshHandler {
            store: Arc::clone(&self.store),
            channels: Arc::new(Mutex::new(HashMap::new())),
            role: Arc::new(Mutex::new(String::new())),
        }
    }
}

pub async fn run_sftp_server(addr: &str, store: Arc<Store>) -> Result<()> {
    let key = russh::keys::PrivateKey::random(&mut rand::rng(), russh::keys::Algorithm::Ed25519)
        .map_err(|e| anyhow::anyhow!("failed to generate ed25519 key: {}", e))?;

    let config = russh::server::Config {
        auth_rejection_time: std::time::Duration::from_millis(100),
        auth_rejection_time_initial: Some(std::time::Duration::from_millis(0)),
        keys: vec![key],
        ..Default::default()
    };

    let config = Arc::new(config);
    let mut server = SshServer { store };

    log::info!("SFTP server listening on {}", addr);
    server.run_on_address(config, addr).await?;
    Ok(())
}

pub async fn run_sftp_server_on(listener: tokio::net::TcpListener, store: Arc<Store>) -> Result<()> {
    let key = russh::keys::PrivateKey::random(&mut rand::rng(), russh::keys::Algorithm::Ed25519)
        .map_err(|e| anyhow::anyhow!("failed to generate ed25519 key: {}", e))?;

    let config = Arc::new(russh::server::Config {
        auth_rejection_time: std::time::Duration::from_millis(100),
        auth_rejection_time_initial: Some(std::time::Duration::from_millis(0)),
        keys: vec![key],
        ..Default::default()
    });

    let mut server = SshServer { store };
    server.run_on_socket(config, &listener).await?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use russh_sftp::client::SftpSession;
    use std::sync::Arc;
    use tempfile::NamedTempFile;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};

    fn make_test_store() -> Arc<Store> {
        let f = NamedTempFile::new().unwrap();
        let path = f.path().to_path_buf();
        std::mem::forget(f);
        // Disable the auth gate on this fresh DB so the legacy SFTP
        // integration tests (which use authenticate_none) still hit
        // Auth::Accept. The production schema seeds auth_enabled='1'
        // for new sites; these tests predate that feature and exercise
        // the open-access code paths.
        let pre = rusqlite::Connection::open(&path).unwrap();
        pre.execute_batch(
            "CREATE TABLE IF NOT EXISTS site_config (key TEXT PRIMARY KEY, value TEXT);
             INSERT OR REPLACE INTO site_config(key,value) VALUES('auth_enabled','0');",
        ).unwrap();
        drop(pre);
        Arc::new(Store::open_and_init(&path).unwrap())
    }

    struct TestClientHandler;

    impl russh::client::Handler for TestClientHandler {
        type Error = anyhow::Error;

        async fn check_server_key(
            &mut self,
            _server_public_key: &russh::keys::PublicKey,
        ) -> Result<bool, Self::Error> {
            Ok(true)
        }
    }

    async fn connect_sftp(addr: std::net::SocketAddr) -> SftpSession {
        let config = Arc::new(russh::client::Config::default());
        let mut session = russh::client::connect(config, addr, TestClientHandler)
            .await
            .unwrap();
        session.authenticate_none("user").await.unwrap();
        let channel = session.channel_open_session().await.unwrap();
        channel.request_subsystem(true, "sftp").await.unwrap();
        SftpSession::new(channel.into_stream()).await.unwrap()
    }

    #[tokio::test]
    async fn test_sftp_write_and_read() {
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        let store = make_test_store();

        tokio::spawn(async move {
            let _ = run_sftp_server_on(listener, store).await;
        });

        // Give server a moment to start accepting
        tokio::time::sleep(std::time::Duration::from_millis(50)).await;

        let sftp = connect_sftp(addr).await;

        // Write file
        let mut file = sftp.create("/main/hello.txt").await.unwrap();
        file.write_all(b"integration").await.unwrap();
        file.flush().await.unwrap();
        drop(file);

        // Read file back
        let mut file = sftp.open("/main/hello.txt").await.unwrap();
        let mut buf = Vec::new();
        file.read_to_end(&mut buf).await.unwrap();
        assert_eq!(buf, b"integration");
    }

    #[tokio::test]
    async fn test_sftp_list_root() {
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        let store = make_test_store();

        tokio::spawn(async move {
            let _ = run_sftp_server_on(listener, store).await;
        });

        tokio::time::sleep(std::time::Duration::from_millis(50)).await;

        let sftp = connect_sftp(addr).await;
        let entries = sftp.read_dir("/").await.unwrap();
        let names: Vec<String> = entries.into_iter()
            .map(|e| e.file_name().to_string())
            .collect();
        assert!(names.contains(&"main".to_string()), "root listing should contain 'main' branch, got: {:?}", names);
    }
}
