/// SMB2 protocol constants and wire serialization (little-endian).
use std::io::{self, Read, Write};

pub const SMB2_MAGIC: &[u8; 4] = b"\xFESMB";
pub const SMB2_HEADER_SIZE: usize = 64;

// Command codes
pub const CMD_NEGOTIATE: u16 = 0x0000;
pub const CMD_SESSION_SETUP: u16 = 0x0001;
pub const CMD_LOGOFF: u16 = 0x0002;
pub const CMD_TREE_CONNECT: u16 = 0x0003;
pub const CMD_TREE_DISCONNECT: u16 = 0x0004;
pub const CMD_CREATE: u16 = 0x0005;
pub const CMD_CLOSE: u16 = 0x0006;
pub const CMD_FLUSH: u16 = 0x0007;
pub const CMD_READ: u16 = 0x0008;
pub const CMD_WRITE: u16 = 0x0009;
pub const CMD_IOCTL: u16 = 0x000b;
pub const CMD_QUERY_DIRECTORY: u16 = 0x000e;
pub const CMD_QUERY_INFO: u16 = 0x0010;
pub const CMD_SET_INFO: u16 = 0x0011;

// NT Status codes
pub const STATUS_SUCCESS: u32 = 0x00000000;
pub const STATUS_NOT_SUPPORTED: u32 = 0xC00000BB;
pub const STATUS_NO_MORE_FILES: u32 = 0x80000006;
pub const STATUS_OBJECT_NAME_NOT_FOUND: u32 = 0xC0000034;
pub const STATUS_ACCESS_DENIED: u32 = 0xC0000022;
pub const STATUS_INVALID_PARAMETER: u32 = 0xC000000D;
pub const STATUS_END_OF_FILE: u32 = 0xC0000011;
pub const STATUS_MORE_PROCESSING_REQUIRED: u32 = 0xC0000016;

// SMB2 dialect
pub const DIALECT_SMB2_1: u16 = 0x0210;
pub const DIALECT_SMB3_0: u16 = 0x0300;

// Capabilities
pub const CAP_DFS: u32 = 0x00000001;

// File attributes
pub const FILE_ATTRIBUTE_DIRECTORY: u32 = 0x10;
pub const FILE_ATTRIBUTE_NORMAL: u32 = 0x80;

// Create dispositions
pub const FILE_OPEN: u32 = 1;
pub const FILE_CREATE: u32 = 2;
pub const FILE_OPEN_IF: u32 = 3;
pub const FILE_OVERWRITE_IF: u32 = 5;

// Create options
pub const FILE_DIRECTORY_FILE: u32 = 0x00000001;

// Information classes
pub const FILE_ALL_INFORMATION: u8 = 18;
pub const FILE_BASIC_INFORMATION: u8 = 4;
pub const FILE_STANDARD_INFORMATION: u8 = 5;
pub const SMB2_0_INFO_FILE: u8 = 1;

#[derive(Debug, Clone)]
pub struct Smb2Header {
    pub credit_charge: u16,
    pub channel_sequence: u16,
    pub status: u32,
    pub command: u16,
    pub credit_request: u16,
    pub flags: u32,
    pub next_command: u32,
    pub message_id: u64,
    pub async_id: u64, // also used as (reserved, tree_id) pair for sync
    pub session_id: u64,
    pub signature: [u8; 16],
    pub is_response: bool,
    pub tree_id: u32,
}

impl Smb2Header {
    pub fn parse(buf: &[u8]) -> Option<Self> {
        if buf.len() < SMB2_HEADER_SIZE {
            return None;
        }
        if &buf[0..4] != SMB2_MAGIC {
            return None;
        }
        let flags = u32::from_le_bytes(buf[16..20].try_into().ok()?);
        let is_response = (flags & 0x1) != 0;
        let tree_id = u32::from_le_bytes(buf[36..40].try_into().ok()?);
        Some(Self {
            credit_charge: u16::from_le_bytes(buf[6..8].try_into().ok()?),
            channel_sequence: u16::from_le_bytes(buf[8..10].try_into().ok()?),
            status: u32::from_le_bytes(buf[8..12].try_into().ok()?),
            command: u16::from_le_bytes(buf[12..14].try_into().ok()?),
            credit_request: u16::from_le_bytes(buf[14..16].try_into().ok()?),
            flags,
            next_command: u32::from_le_bytes(buf[20..24].try_into().ok()?),
            message_id: u64::from_le_bytes(buf[24..32].try_into().ok()?),
            async_id: u64::from_le_bytes(buf[32..40].try_into().ok()?),
            session_id: u64::from_le_bytes(buf[40..48].try_into().ok()?),
            signature: buf[48..64].try_into().ok()?,
            is_response,
            tree_id,
        })
    }

    pub fn write_response(&self, buf: &mut Vec<u8>, status: u32) {
        buf.extend_from_slice(SMB2_MAGIC);
        // StructureSize = 64
        buf.extend_from_slice(&64u16.to_le_bytes());
        buf.extend_from_slice(&self.credit_charge.to_le_bytes());
        buf.extend_from_slice(&status.to_le_bytes());
        buf.extend_from_slice(&self.command.to_le_bytes());
        // GrantedCredits = 1
        buf.extend_from_slice(&1u16.to_le_bytes());
        // Flags: SMB2_FLAGS_SERVER_TO_REDIR = 0x01
        buf.extend_from_slice(&1u32.to_le_bytes());
        buf.extend_from_slice(&0u32.to_le_bytes()); // NextCommand
        buf.extend_from_slice(&self.message_id.to_le_bytes());
        buf.extend_from_slice(&0u32.to_le_bytes()); // reserved
        buf.extend_from_slice(&self.tree_id.to_le_bytes());
        buf.extend_from_slice(&self.session_id.to_le_bytes());
        buf.extend_from_slice(&[0u8; 16]); // signature
    }
}

pub fn encode_utf16le(s: &str) -> Vec<u8> {
    s.encode_utf16()
        .flat_map(|c| c.to_le_bytes())
        .collect()
}

pub fn decode_utf16le(buf: &[u8]) -> String {
    let u16s: Vec<u16> = buf
        .chunks_exact(2)
        .map(|c| u16::from_le_bytes([c[0], c[1]]))
        .collect();
    String::from_utf16_lossy(&u16s).to_string()
}

/// Windows FILETIME: 100-nanosecond intervals since 1601-01-01
pub fn unix_to_filetime(unix_secs: i64) -> u64 {
    // 116444736000000000 = number of 100ns intervals between 1601 and 1970
    let offset: u64 = 116_444_736_000_000_000;
    offset + (unix_secs as u64) * 10_000_000
}

#[cfg(test)]
mod tests {
    use super::*;

    fn make_valid_header_buf() -> [u8; 64] {
        let mut buf = [0u8; 64];
        buf[0..4].copy_from_slice(SMB2_MAGIC);
        buf[4..6].copy_from_slice(&64u16.to_le_bytes()); // StructureSize
        buf[12..14].copy_from_slice(&CMD_NEGOTIATE.to_le_bytes());
        buf[16..20].copy_from_slice(&0u32.to_le_bytes()); // flags
        buf[24..32].copy_from_slice(&42u64.to_le_bytes()); // message_id
        buf[32..36].copy_from_slice(&0u32.to_le_bytes()); // reserved
        buf[36..40].copy_from_slice(&7u32.to_le_bytes()); // tree_id
        buf[40..48].copy_from_slice(&99u64.to_le_bytes()); // session_id
        buf
    }

    #[test]
    fn test_magic_bytes() {
        assert_eq!(SMB2_MAGIC, b"\xFESMB");
    }

    #[test]
    fn test_header_parse_valid() {
        let buf = make_valid_header_buf();
        let hdr = Smb2Header::parse(&buf).expect("should parse valid header");
        assert_eq!(hdr.command, CMD_NEGOTIATE);
        assert_eq!(hdr.message_id, 42);
        assert_eq!(hdr.session_id, 99);
        assert_eq!(hdr.tree_id, 7);
    }

    #[test]
    fn test_header_parse_too_short() {
        let buf = [0u8; 32];
        assert!(Smb2Header::parse(&buf).is_none());
    }

    #[test]
    fn test_header_parse_bad_magic() {
        let mut buf = [0u8; 64];
        buf[0..4].copy_from_slice(b"BADM");
        assert!(Smb2Header::parse(&buf).is_none());
    }

    #[test]
    fn test_write_response_length() {
        let buf = make_valid_header_buf();
        let hdr = Smb2Header::parse(&buf).unwrap();
        let mut resp = Vec::new();
        hdr.write_response(&mut resp, STATUS_SUCCESS);
        assert_eq!(resp.len(), 64);
    }

    #[test]
    fn test_write_response_magic() {
        let buf = make_valid_header_buf();
        let hdr = Smb2Header::parse(&buf).unwrap();
        let mut resp = Vec::new();
        hdr.write_response(&mut resp, STATUS_SUCCESS);
        assert_eq!(&resp[0..4], SMB2_MAGIC.as_slice());
    }

    #[test]
    fn test_encode_utf16le_roundtrip() {
        let s = "hello";
        let encoded = encode_utf16le(s);
        let decoded = decode_utf16le(&encoded);
        assert_eq!(decoded, s);
    }

    #[test]
    fn test_encode_utf16le_known() {
        let encoded = encode_utf16le("A");
        assert_eq!(encoded, vec![0x41, 0x00]);
    }

    #[test]
    fn test_unix_to_filetime_epoch() {
        assert_eq!(unix_to_filetime(0), 116_444_736_000_000_000u64);
    }

    #[test]
    fn test_unix_to_filetime_known() {
        let expected = 116_444_736_000_000_000u64 + 10_000_000_000_000_000u64;
        assert_eq!(unix_to_filetime(1_000_000_000), expected);
    }
}

pub fn now_filetime() -> u64 {
    let secs = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap()
        .as_secs() as i64;
    unix_to_filetime(secs)
}

/// Framing: NetBIOS session layer (4-byte big-endian length prefix)
pub async fn read_smb_message<R: tokio::io::AsyncReadExt + Unpin>(
    reader: &mut R,
) -> io::Result<Vec<u8>> {
    let mut len_buf = [0u8; 4];
    reader.read_exact(&mut len_buf).await?;
    let len = u32::from_be_bytes(len_buf) as usize;
    if len == 0 || len > 16 * 1024 * 1024 {
        return Err(io::Error::new(io::ErrorKind::InvalidData, "bad SMB frame length"));
    }
    let mut buf = vec![0u8; len];
    reader.read_exact(&mut buf).await?;
    Ok(buf)
}

pub async fn write_smb_message<W: tokio::io::AsyncWriteExt + Unpin>(
    writer: &mut W,
    payload: &[u8],
) -> io::Result<()> {
    let len = payload.len() as u32;
    writer.write_all(&len.to_be_bytes()).await?;
    writer.write_all(payload).await?;
    writer.flush().await?;
    Ok(())
}
