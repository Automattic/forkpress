use crate::smb_proto::*;
use crate::store::{FileEntry, Store};
use anyhow::Result;
use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use tokio::io::AsyncWriteExt;
use tokio::net::TcpListener;

struct FileHandle {
    branch: String,
    path: String,
    is_dir: bool,
    write_buf: Vec<u8>,
    did_write: bool,
    dir_listed: bool,
}

struct Session {
    tree_connected: HashMap<u32, String>, // tree_id -> branch_name
    file_handles: HashMap<u64, FileHandle>, // fid_persistent -> handle
    next_fid: u64,
    next_tree_id: u32,
}

impl Session {
    fn new() -> Self {
        Self {
            tree_connected: HashMap::new(),
            file_handles: HashMap::new(),
            next_fid: 1,
            next_tree_id: 1,
        }
    }
}

pub async fn run_smb_server(addr: &str, store: Arc<Store>) -> Result<()> {
    let listener = TcpListener::bind(addr).await?;
    log::info!("SMB2 server listening on {}", addr);
    run_smb_server_on(listener, store).await
}

pub async fn run_smb_server_on(listener: TcpListener, store: Arc<Store>) -> Result<()> {
    loop {
        let (socket, peer) = listener.accept().await?;
        log::info!("SMB2 connection from {}", peer);
        let store = Arc::clone(&store);
        tokio::spawn(async move {
            if let Err(e) = handle_connection(socket, store).await {
                log::warn!("SMB2 connection error: {}", e);
            }
        });
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::smb_proto::SMB2_MAGIC;
    use tempfile::NamedTempFile;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::TcpListener;

    fn make_test_store() -> Arc<Store> {
        let f = NamedTempFile::new().unwrap();
        let path = f.path().to_path_buf();
        std::mem::forget(f);
        // Legacy SMB test was written before the auth gate; pre-seed
        // auth_enabled='0' so the negotiate flow still reaches the
        // original open-access dispatch path.
        let pre = rusqlite::Connection::open(&path).unwrap();
        pre.execute_batch(
            "CREATE TABLE IF NOT EXISTS site_config (key TEXT PRIMARY KEY, value TEXT);
             INSERT OR REPLACE INTO site_config(key,value) VALUES('auth_enabled','0');",
        ).unwrap();
        drop(pre);
        Arc::new(Store::open_and_init(&path).unwrap())
    }

    #[tokio::test]
    async fn test_smb_negotiate() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        let store = make_test_store();

        tokio::spawn(async move {
            let (socket, _) = listener.accept().await.unwrap();
            let _ = handle_connection(socket, store).await;
        });

        let mut stream = tokio::net::TcpStream::connect(addr).await.unwrap();

        // Build SMB2 Negotiate request: 64-byte header + 38-byte body
        let mut msg = [0u8; 102];
        // Header
        msg[0..4].copy_from_slice(SMB2_MAGIC);
        msg[4..6].copy_from_slice(&64u16.to_le_bytes()); // StructureSize
        // command = 0 (NEGOTIATE), flags = 0, message_id = 0 — all zeros by default
        // Body at offset 64
        msg[64..66].copy_from_slice(&36u16.to_le_bytes()); // StructureSize
        msg[66..68].copy_from_slice(&1u16.to_le_bytes()); // DialectCount
        msg[68..70].copy_from_slice(&1u16.to_le_bytes()); // SecurityMode
        // ClientGuid (16 bytes at 76..92): zeros
        // ClientStartTime (8 bytes at 92..100): zeros
        msg[100..102].copy_from_slice(&0x0210u16.to_le_bytes()); // SMB 2.1 dialect

        let len = (msg.len() as u32).to_be_bytes();
        stream.write_all(&len).await.unwrap();
        stream.write_all(&msg).await.unwrap();
        stream.flush().await.unwrap();

        // Read NetBIOS-framed response
        let mut len_buf = [0u8; 4];
        stream.read_exact(&mut len_buf).await.unwrap();
        let resp_len = u32::from_be_bytes(len_buf) as usize;
        let mut resp = vec![0u8; resp_len];
        stream.read_exact(&mut resp).await.unwrap();

        assert_eq!(&resp[0..4], SMB2_MAGIC.as_slice(), "response must start with SMB2 magic");
        assert_eq!(&resp[8..12], &[0x00, 0x00, 0x00, 0x00], "status must be STATUS_SUCCESS");
    }
}

async fn handle_connection(
    socket: tokio::net::TcpStream,
    store: Arc<Store>,
) -> Result<()> {
    let (mut reader, mut writer) = socket.into_split();
    let session = Arc::new(Mutex::new(Session::new()));

    loop {
        let msg = match read_smb_message(&mut reader).await {
            Ok(m) => m,
            Err(e) if e.kind() == std::io::ErrorKind::UnexpectedEof => break,
            Err(e) => return Err(e.into()),
        };

        if msg.len() < SMB2_HEADER_SIZE {
            break;
        }

        let hdr = match Smb2Header::parse(&msg) {
            Some(h) => h,
            None => break,
        };

        let body = &msg[SMB2_HEADER_SIZE..];
        let response = dispatch(&hdr, body, &store, &session);
        write_smb_message(&mut writer, &response).await?;
    }
    Ok(())
}

fn dispatch(
    hdr: &Smb2Header,
    body: &[u8],
    store: &Arc<Store>,
    session: &Arc<Mutex<Session>>,
) -> Vec<u8> {
    // Hard gate: when the site has auth_enabled, we refuse every SMB op
    // except NEGOTIATE. Our SMB server speaks a minimal dialect and does
    // not implement NTLMSSP, so we cannot verify per-user credentials on
    // the wire. Refusing SESSION_SETUP (rather than silently accepting)
    // makes the surface match the acceptance criterion that "all four
    // write surfaces reject requests when auth_enabled=true". Admins
    // who need SMB can use the SFTP / MySQL surfaces, or disable auth
    // (`user_admin.php auth-enabled 0`) for the SMB-only use case.
    // See PRD F4.
    if store.auth_enabled() && hdr.command != CMD_NEGOTIATE {
        return error_response(hdr, STATUS_ACCESS_DENIED);
    }
    match hdr.command {
        CMD_NEGOTIATE => handle_negotiate(hdr, body),
        CMD_SESSION_SETUP => handle_session_setup(hdr, body),
        CMD_TREE_CONNECT => handle_tree_connect(hdr, body, store, session),
        CMD_TREE_DISCONNECT => handle_tree_disconnect(hdr, body, session),
        CMD_CREATE => handle_create(hdr, body, store, session),
        CMD_CLOSE => handle_close(hdr, body, store, session),
        CMD_FLUSH => handle_flush(hdr),
        CMD_READ => handle_read(hdr, body, store, session),
        CMD_WRITE => handle_write(hdr, body, session),
        CMD_IOCTL => handle_ioctl(hdr, body),
        CMD_QUERY_DIRECTORY => handle_query_directory(hdr, body, store, session),
        CMD_QUERY_INFO => handle_query_info(hdr, body, store, session),
        CMD_SET_INFO => handle_set_info(hdr, body),
        _ => error_response(hdr, STATUS_NOT_SUPPORTED),
    }
}

fn error_response(hdr: &Smb2Header, status: u32) -> Vec<u8> {
    let mut buf = Vec::new();
    hdr.write_response(&mut buf, status);
    // Error response body: StructureSize=9, ErrorContextCount=0, Reserved=0, ByteCount=0
    buf.extend_from_slice(&9u16.to_le_bytes());
    buf.push(0);
    buf.push(0);
    buf.extend_from_slice(&0u32.to_le_bytes());
    buf
}

fn handle_negotiate(hdr: &Smb2Header, body: &[u8]) -> Vec<u8> {
    let mut resp = Vec::new();
    hdr.write_response(&mut resp, STATUS_SUCCESS);

    let now = now_filetime();
    // StructureSize=65, SecurityMode=1 (signing enabled but not required)
    resp.extend_from_slice(&65u16.to_le_bytes());
    resp.extend_from_slice(&1u16.to_le_bytes()); // SecurityMode
    resp.extend_from_slice(&DIALECT_SMB2_1.to_le_bytes());
    resp.extend_from_slice(&0u16.to_le_bytes()); // NegotiateContextCount
    // ServerGuid (16 bytes)
    resp.extend_from_slice(&[0x42u8; 16]);
    resp.extend_from_slice(&0u32.to_le_bytes()); // Capabilities
    resp.extend_from_slice(&(65536u32).to_le_bytes()); // MaxTransactSize
    resp.extend_from_slice(&(65536u32).to_le_bytes()); // MaxReadSize
    resp.extend_from_slice(&(65536u32).to_le_bytes()); // MaxWriteSize
    resp.extend_from_slice(&now.to_le_bytes()); // SystemTime
    resp.extend_from_slice(&now.to_le_bytes()); // ServerStartTime
    // SecurityBufferOffset (relative to SMB2 header start)
    let offset = (SMB2_HEADER_SIZE + 65) as u16;
    resp.extend_from_slice(&offset.to_le_bytes());
    resp.extend_from_slice(&0u16.to_le_bytes()); // SecurityBufferLength=0
    resp.extend_from_slice(&0u32.to_le_bytes()); // NegotiateContextOffset
    resp
}

fn handle_session_setup(hdr: &Smb2Header, _body: &[u8]) -> Vec<u8> {
    let mut resp = Vec::new();
    hdr.write_response(&mut resp, STATUS_SUCCESS);
    // StructureSize=9, SessionFlags=0 (guest), SecurityBufferOffset, SecurityBufferLength
    resp.extend_from_slice(&9u16.to_le_bytes());
    resp.extend_from_slice(&0u16.to_le_bytes()); // SessionFlags
    let offset = (SMB2_HEADER_SIZE + 9) as u16;
    resp.extend_from_slice(&offset.to_le_bytes());
    resp.extend_from_slice(&0u16.to_le_bytes()); // SecurityBufferLength=0
    resp
}

fn handle_tree_connect(
    hdr: &Smb2Header,
    body: &[u8],
    store: &Arc<Store>,
    session: &Arc<Mutex<Session>>,
) -> Vec<u8> {
    // Parse share name from body: offset(2)+reserved(2)+PathOffset(2)+PathLength(2)+Path
    if body.len() < 8 {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    }
    let path_offset = u16::from_le_bytes([body[4], body[5]]) as usize;
    let path_len = u16::from_le_bytes([body[6], body[7]]) as usize;

    // path_offset is relative to SMB2 header start
    let abs_start = if path_offset >= SMB2_HEADER_SIZE {
        path_offset - SMB2_HEADER_SIZE
    } else {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    };

    let share_path = if abs_start + path_len <= body.len() {
        decode_utf16le(&body[abs_start..abs_start + path_len])
    } else {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    };

    // Extract branch name: \\server\branch  -> branch
    let branch = share_path.trim_start_matches('\\')
        .splitn(2, '\\')
        .nth(1)
        .unwrap_or(&share_path)
        .trim_matches('\\')
        .to_string();

    // Verify branch exists
    if store.branch_id(&branch).is_err() {
        return error_response(hdr, STATUS_OBJECT_NAME_NOT_FOUND);
    }

    let tree_id = {
        let mut sess = session.lock().unwrap();
        let tid = sess.next_tree_id;
        sess.next_tree_id += 1;
        sess.tree_connected.insert(tid, branch);
        tid
    };

    // We need tree_id in the response header — build a custom header
    let mut resp = Vec::new();
    let mut hdr2 = hdr.clone();
    hdr2.tree_id = tree_id;
    hdr2.write_response(&mut resp, STATUS_SUCCESS);

    // StructureSize=16, ShareType=DISK(1), Reserved=0, ShareFlags=0, Capabilities=0, MaximalAccess
    resp.extend_from_slice(&16u16.to_le_bytes());
    resp.push(1); // ShareType: DISK
    resp.push(0); // reserved
    resp.extend_from_slice(&0u32.to_le_bytes()); // ShareFlags
    resp.extend_from_slice(&0u32.to_le_bytes()); // Capabilities
    resp.extend_from_slice(&0x001f01ffu32.to_le_bytes()); // MaximalAccess: full
    resp
}

fn handle_tree_disconnect(
    hdr: &Smb2Header,
    _body: &[u8],
    session: &Arc<Mutex<Session>>,
) -> Vec<u8> {
    session.lock().unwrap().tree_connected.remove(&hdr.tree_id);
    let mut resp = Vec::new();
    hdr.write_response(&mut resp, STATUS_SUCCESS);
    resp.extend_from_slice(&4u16.to_le_bytes()); // StructureSize
    resp.extend_from_slice(&0u16.to_le_bytes()); // reserved
    resp
}

fn handle_create(
    hdr: &Smb2Header,
    body: &[u8],
    store: &Arc<Store>,
    session: &Arc<Mutex<Session>>,
) -> Vec<u8> {
    if body.len() < 56 {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    }
    let desired_access = u32::from_le_bytes(body[2..6].try_into().unwrap_or_default());
    let file_attrs = u32::from_le_bytes(body[6..10].try_into().unwrap_or_default());
    let create_disposition = u32::from_le_bytes(body[26..30].try_into().unwrap_or_default());
    let create_options = u32::from_le_bytes(body[30..34].try_into().unwrap_or_default());
    let name_offset = u16::from_le_bytes([body[44], body[45]]) as usize;
    let name_len = u16::from_le_bytes([body[46], body[47]]) as usize;

    let abs_start = if name_offset >= SMB2_HEADER_SIZE {
        name_offset - SMB2_HEADER_SIZE
    } else {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    };

    let file_path = if name_len == 0 {
        String::new()
    } else if abs_start + name_len <= body.len() {
        decode_utf16le(&body[abs_start..abs_start + name_len])
            .replace('\\', "/")
    } else {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    };

    let branch = {
        let sess = session.lock().unwrap();
        match sess.tree_connected.get(&hdr.tree_id) {
            Some(b) => b.clone(),
            None => return error_response(hdr, STATUS_ACCESS_DENIED),
        }
    };

    let is_dir_open = (create_options & FILE_DIRECTORY_FILE) != 0 || file_path.is_empty();

    // Determine if path exists
    let (exists, is_dir_on_disk) = if file_path.is_empty() {
        (true, true) // root
    } else {
        match store.list_files(&branch) {
            Ok(files) => {
                let fp = file_path.trim_matches('/');
                let found = files.iter().find(|f| f.path.trim_matches('/') == fp);
                match found {
                    Some(e) => (true, e.is_dir),
                    None => (false, false),
                }
            }
            Err(_) => (false, false),
        }
    };

    // Handle create_disposition
    match create_disposition {
        FILE_CREATE if exists => return error_response(hdr, 0xC0000035), // OBJECT_NAME_COLLISION
        FILE_OPEN if !exists => return error_response(hdr, STATUS_OBJECT_NAME_NOT_FOUND),
        _ => {}
    }

    if is_dir_open && !exists && !file_path.is_empty() {
        if let Err(_) = store.create_dir(&branch, file_path.trim_matches('/')) {
            return error_response(hdr, STATUS_ACCESS_DENIED);
        }
    }

    let fid = {
        let mut sess = session.lock().unwrap();
        let fid = sess.next_fid;
        sess.next_fid += 1;
        sess.file_handles.insert(fid, FileHandle {
            branch: branch.clone(),
            path: file_path.trim_matches('/').to_string(),
            is_dir: is_dir_open || is_dir_on_disk,
            write_buf: Vec::new(),
            did_write: false,
            dir_listed: false,
        });
        fid
    };

    let now = now_filetime();
    let mut resp = Vec::new();
    hdr.write_response(&mut resp, STATUS_SUCCESS);

    // StructureSize=89
    resp.extend_from_slice(&89u16.to_le_bytes());
    resp.push(0); // OplocksLevel=None
    resp.push(0); // Flags
    resp.extend_from_slice(&0u32.to_le_bytes()); // CreateAction=FILE_OPENED (0) placeholder
    // Timestamps x4
    for _ in 0..4 {
        resp.extend_from_slice(&now.to_le_bytes());
    }
    let attrs = if is_dir_open || is_dir_on_disk { FILE_ATTRIBUTE_DIRECTORY } else { FILE_ATTRIBUTE_NORMAL };
    resp.extend_from_slice(&attrs.to_le_bytes());
    resp.extend_from_slice(&0u32.to_le_bytes()); // reserved2
    resp.extend_from_slice(&0u64.to_le_bytes()); // AllocationSize
    resp.extend_from_slice(&0u64.to_le_bytes()); // EndofFile
    // FileId: persistent + volatile
    resp.extend_from_slice(&fid.to_le_bytes());
    resp.extend_from_slice(&fid.to_le_bytes());
    resp.extend_from_slice(&0u32.to_le_bytes()); // CreateContextsOffset
    resp.extend_from_slice(&0u32.to_le_bytes()); // CreateContextsLength
    resp
}

fn handle_close(
    hdr: &Smb2Header,
    body: &[u8],
    store: &Arc<Store>,
    session: &Arc<Mutex<Session>>,
) -> Vec<u8> {
    if body.len() < 24 {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    }
    let fid = u64::from_le_bytes(body[8..16].try_into().unwrap_or_default());

    let handle = session.lock().unwrap().file_handles.remove(&fid);
    if let Some(h) = handle {
        if h.did_write && !h.is_dir && !h.path.is_empty() {
            let _ = store.write_file(&h.branch, &h.path, &h.write_buf, "smb2");
        }
    }

    let now = now_filetime();
    let mut resp = Vec::new();
    hdr.write_response(&mut resp, STATUS_SUCCESS);
    resp.extend_from_slice(&60u16.to_le_bytes()); // StructureSize
    resp.extend_from_slice(&0u16.to_le_bytes()); // Flags
    resp.extend_from_slice(&0u32.to_le_bytes()); // reserved
    for _ in 0..4 {
        resp.extend_from_slice(&now.to_le_bytes()); // timestamps
    }
    resp.extend_from_slice(&0u32.to_le_bytes()); // AllocationSize (high)
    resp.extend_from_slice(&0u32.to_le_bytes()); // AllocationSize (low)
    resp.extend_from_slice(&0u32.to_le_bytes()); // EndofFile (high)
    resp.extend_from_slice(&0u32.to_le_bytes()); // EndofFile (low)
    resp.extend_from_slice(&0u32.to_le_bytes()); // FileAttributes
    resp
}

fn handle_flush(hdr: &Smb2Header) -> Vec<u8> {
    let mut resp = Vec::new();
    hdr.write_response(&mut resp, STATUS_SUCCESS);
    resp.extend_from_slice(&4u16.to_le_bytes());
    resp.extend_from_slice(&0u16.to_le_bytes());
    resp
}

fn handle_read(
    hdr: &Smb2Header,
    body: &[u8],
    store: &Arc<Store>,
    session: &Arc<Mutex<Session>>,
) -> Vec<u8> {
    if body.len() < 48 {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    }
    let read_length = u32::from_le_bytes(body[4..8].try_into().unwrap_or_default()) as usize;
    let read_offset = u64::from_le_bytes(body[8..16].try_into().unwrap_or_default()) as usize;
    let fid = u64::from_le_bytes(body[16..24].try_into().unwrap_or_default());

    let (branch, path) = {
        let sess = session.lock().unwrap();
        match sess.file_handles.get(&fid) {
            Some(h) => (h.branch.clone(), h.path.clone()),
            None => return error_response(hdr, STATUS_INVALID_PARAMETER),
        }
    };

    let data = match store.read_file(&branch, &path) {
        Ok(d) => d,
        Err(_) => return error_response(hdr, STATUS_OBJECT_NAME_NOT_FOUND),
    };

    if read_offset >= data.len() {
        return error_response(hdr, STATUS_END_OF_FILE);
    }

    let slice = &data[read_offset..std::cmp::min(read_offset + read_length, data.len())];

    let mut resp = Vec::new();
    hdr.write_response(&mut resp, STATUS_SUCCESS);
    resp.extend_from_slice(&17u16.to_le_bytes()); // StructureSize
    let data_offset = (SMB2_HEADER_SIZE + 16) as u8;
    resp.push(data_offset); // DataOffset
    resp.push(0); // Reserved
    resp.extend_from_slice(&(slice.len() as u32).to_le_bytes()); // DataLength
    resp.extend_from_slice(&0u32.to_le_bytes()); // DataRemaining
    resp.extend_from_slice(&0u32.to_le_bytes()); // Padding/Reserved
    resp.extend_from_slice(slice);
    resp
}

fn handle_write(
    hdr: &Smb2Header,
    body: &[u8],
    session: &Arc<Mutex<Session>>,
) -> Vec<u8> {
    if body.len() < 48 {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    }
    let data_offset = u16::from_le_bytes([body[2], body[3]]) as usize;
    let data_length = u32::from_le_bytes(body[4..8].try_into().unwrap_or_default()) as usize;
    let write_offset = u64::from_le_bytes(body[8..16].try_into().unwrap_or_default()) as usize;
    let fid = u64::from_le_bytes(body[16..24].try_into().unwrap_or_default());

    let abs_data_start = if data_offset >= SMB2_HEADER_SIZE {
        data_offset - SMB2_HEADER_SIZE
    } else {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    };

    let write_data = if abs_data_start + data_length <= body.len() {
        &body[abs_data_start..abs_data_start + data_length]
    } else {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    };

    {
        let mut sess = session.lock().unwrap();
        if let Some(h) = sess.file_handles.get_mut(&fid) {
            let needed = write_offset + write_data.len();
            if h.write_buf.len() < needed {
                h.write_buf.resize(needed, 0);
            }
            h.write_buf[write_offset..write_offset + write_data.len()]
                .copy_from_slice(write_data);
            h.did_write = true;
        } else {
            return error_response(hdr, STATUS_INVALID_PARAMETER);
        }
    }

    let mut resp = Vec::new();
    hdr.write_response(&mut resp, STATUS_SUCCESS);
    resp.extend_from_slice(&17u16.to_le_bytes()); // StructureSize
    resp.extend_from_slice(&0u16.to_le_bytes()); // Reserved
    resp.extend_from_slice(&(data_length as u32).to_le_bytes()); // Count
    resp.extend_from_slice(&0u32.to_le_bytes()); // Remaining
    resp.extend_from_slice(&0u32.to_le_bytes()); // WriteChannelInfoOffset
    resp.extend_from_slice(&0u32.to_le_bytes()); // WriteChannelInfoLength
    resp
}

fn handle_ioctl(hdr: &Smb2Header, _body: &[u8]) -> Vec<u8> {
    // Return NOT_SUPPORTED for all IOCTLs (including DFS referral)
    error_response(hdr, STATUS_NOT_SUPPORTED)
}

fn handle_query_directory(
    hdr: &Smb2Header,
    body: &[u8],
    store: &Arc<Store>,
    session: &Arc<Mutex<Session>>,
) -> Vec<u8> {
    if body.len() < 32 {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    }
    let fid = u64::from_le_bytes(body[8..16].try_into().unwrap_or_default());

    let (branch, dir_path, already_listed) = {
        let sess = session.lock().unwrap();
        match sess.file_handles.get(&fid) {
            Some(h) => (h.branch.clone(), h.path.clone(), h.dir_listed),
            None => return error_response(hdr, STATUS_INVALID_PARAMETER),
        }
    };

    if already_listed {
        return error_response(hdr, STATUS_NO_MORE_FILES);
    }

    // Mark as listed
    {
        let mut sess = session.lock().unwrap();
        if let Some(h) = sess.file_handles.get_mut(&fid) {
            h.dir_listed = true;
        }
    }

    let all_files = match store.list_files(&branch) {
        Ok(f) => f,
        Err(_) => return error_response(hdr, STATUS_ACCESS_DENIED),
    };

    // Filter to direct children of dir_path
    let prefix = if dir_path.is_empty() || dir_path == "/" {
        String::new()
    } else {
        format!("{}/", dir_path.trim_matches('/'))
    };

    let children: Vec<&FileEntry> = all_files
        .iter()
        .filter(|e| {
            let p = e.path.trim_matches('/');
            if prefix.is_empty() {
                !p.contains('/')
            } else {
                p.starts_with(&prefix) && !p[prefix.len()..].contains('/')
            }
        })
        .collect();

    let mut output = Vec::new();

    // Synthesize . and ..
    for name in &[".", ".."] {
        append_file_info_entry(&mut output, name, true, 0, now_filetime());
    }

    for entry in &children {
        let name = entry.path.trim_matches('/').rsplit('/').next().unwrap_or("");
        let ft = unix_to_filetime(entry.mtime);
        append_file_info_entry(&mut output, name, entry.is_dir, entry.size as u64, ft);
    }

    if output.is_empty() {
        return error_response(hdr, STATUS_NO_MORE_FILES);
    }

    let mut resp = Vec::new();
    hdr.write_response(&mut resp, STATUS_SUCCESS);
    let offset = (SMB2_HEADER_SIZE + 8) as u16;
    resp.extend_from_slice(&9u16.to_le_bytes()); // StructureSize
    resp.extend_from_slice(&offset.to_le_bytes()); // OutputBufferOffset
    resp.extend_from_slice(&(output.len() as u32).to_le_bytes()); // OutputBufferLength
    resp.extend_from_slice(&output);
    resp
}

fn append_file_info_entry(buf: &mut Vec<u8>, name: &str, is_dir: bool, size: u64, ft: u64) {
    let name_bytes = encode_utf16le(name);
    let name_len = name_bytes.len() as u32;

    let record_start = buf.len();
    // NextEntryOffset placeholder
    buf.extend_from_slice(&0u32.to_le_bytes());
    buf.extend_from_slice(&0u32.to_le_bytes()); // FileIndex
    buf.extend_from_slice(&ft.to_le_bytes()); // CreationTime
    buf.extend_from_slice(&ft.to_le_bytes()); // LastAccessTime
    buf.extend_from_slice(&ft.to_le_bytes()); // LastWriteTime
    buf.extend_from_slice(&ft.to_le_bytes()); // ChangeTime
    buf.extend_from_slice(&size.to_le_bytes()); // EndOfFile
    buf.extend_from_slice(&size.to_le_bytes()); // AllocationSize
    let attrs: u32 = if is_dir { FILE_ATTRIBUTE_DIRECTORY } else { FILE_ATTRIBUTE_NORMAL };
    buf.extend_from_slice(&attrs.to_le_bytes());
    buf.extend_from_slice(&name_len.to_le_bytes());
    buf.extend_from_slice(&name_bytes);

    // 4-byte align
    let record_size = buf.len() - record_start;
    let pad = (4 - (record_size % 4)) % 4;
    buf.resize(buf.len() + pad, 0);

    // Fix up NextEntryOffset in the previous entry
    let total = buf.len() - record_start;
    let off = (total) as u32;
    let s = record_start;
    buf[s..s + 4].copy_from_slice(&off.to_le_bytes());
}

fn handle_query_info(
    hdr: &Smb2Header,
    body: &[u8],
    store: &Arc<Store>,
    session: &Arc<Mutex<Session>>,
) -> Vec<u8> {
    if body.len() < 40 {
        return error_response(hdr, STATUS_INVALID_PARAMETER);
    }
    let info_type = body[2];
    let file_info_class = body[3];
    let fid = u64::from_le_bytes(body[16..24].try_into().unwrap_or_default());

    let (branch, path, is_dir) = {
        let sess = session.lock().unwrap();
        match sess.file_handles.get(&fid) {
            Some(h) => (h.branch.clone(), h.path.clone(), h.is_dir),
            None => return error_response(hdr, STATUS_INVALID_PARAMETER),
        }
    };

    let (size, mtime) = if path.is_empty() {
        (0u64, now_filetime())
    } else {
        match store.list_files(&branch) {
            Ok(files) => {
                let fp = path.trim_matches('/');
                files.iter().find(|f| f.path.trim_matches('/') == fp)
                    .map(|e| (e.size as u64, unix_to_filetime(e.mtime)))
                    .unwrap_or((0, now_filetime()))
            }
            Err(_) => (0, now_filetime()),
        }
    };

    let attrs: u32 = if is_dir { FILE_ATTRIBUTE_DIRECTORY } else { FILE_ATTRIBUTE_NORMAL };
    let now = now_filetime();

    let info_data: Vec<u8> = match (info_type, file_info_class) {
        (SMB2_0_INFO_FILE, 5) => {
            // FileStandardInformation
            let mut d = Vec::new();
            d.extend_from_slice(&size.to_le_bytes()); // AllocationSize
            d.extend_from_slice(&size.to_le_bytes()); // EndOfFile
            d.extend_from_slice(&1u32.to_le_bytes()); // NumberOfLinks
            d.push(0); // DeletePending
            d.push(if is_dir { 1 } else { 0 }); // Directory
            d.extend_from_slice(&0u16.to_le_bytes()); // Reserved
            d
        }
        (SMB2_0_INFO_FILE, 4) | (SMB2_0_INFO_FILE, _) => {
            // FileBasicInformation / fallback
            let mut d = Vec::new();
            d.extend_from_slice(&mtime.to_le_bytes()); // CreationTime
            d.extend_from_slice(&mtime.to_le_bytes()); // LastAccessTime
            d.extend_from_slice(&mtime.to_le_bytes()); // LastWriteTime
            d.extend_from_slice(&mtime.to_le_bytes()); // ChangeTime
            d.extend_from_slice(&attrs.to_le_bytes());
            d.extend_from_slice(&0u32.to_le_bytes()); // Reserved
            d
        }
        _ => return error_response(hdr, STATUS_NOT_SUPPORTED),
    };

    let mut resp = Vec::new();
    hdr.write_response(&mut resp, STATUS_SUCCESS);
    let offset = (SMB2_HEADER_SIZE + 8) as u16;
    resp.extend_from_slice(&9u16.to_le_bytes()); // StructureSize
    resp.extend_from_slice(&offset.to_le_bytes()); // OutputBufferOffset
    resp.extend_from_slice(&(info_data.len() as u32).to_le_bytes());
    resp.extend_from_slice(&info_data);
    resp
}

fn handle_set_info(hdr: &Smb2Header, _body: &[u8]) -> Vec<u8> {
    // Accept SetInfo (for renames etc.) but don't act on it for prototype
    let mut resp = Vec::new();
    hdr.write_response(&mut resp, STATUS_SUCCESS);
    resp.extend_from_slice(&2u16.to_le_bytes()); // StructureSize
    resp
}
