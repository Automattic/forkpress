# Windows COW Setup

Status: 2026-05-11

ForkPress should not ask Windows users to install WSL, Docker, FUSE, WinFsp, or
developer-only storage tools before they can create cheap COW branches. The
Windows path is native ReFS block cloning on a Dev Drive.

## Intended User Flow

For a fresh Windows laptop:

1. Download `ForkPressSetup.exe`.
2. Open it.
3. Accept the Windows permission prompt.
4. Reboot only if Windows asks.
5. Open **Start ForkPress Site** from the desktop or Start Menu.

The installer runs the same storage setup script a developer can run manually
from an elevated PowerShell session:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\windows\setup-dev-drive.ps1
```

By default it creates:

```text
%ProgramData%\ForkPress\Storage\forkpress-dev-drive.vhdx
%USERPROFILE%\ForkPressDevDrive\
```

The VHDX is dynamic, so the file grows with real data rather than immediately
allocating the configured maximum size. The default maximum is 128 GB because
Windows Dev Drive volumes have a 50 GB minimum.

The installer also:

- installs `forkpress.exe` and setup scripts under `%ProgramFiles%\ForkPress`;
- adds that directory to the current user's `PATH`;
- installs the Microsoft Visual C++ Redistributable needed by the official PHP
  for Windows runtime from the redistributable bundled in the ForkPress package;
- registers a scheduled task that reattaches the VHDX after logon;
- creates `%USERPROFILE%\ForkPressDevDrive\Sites\My ForkPress Site`;
- runs `forkpress init` in that site folder;
- creates **Start ForkPress Site**, **ForkPress Shell**, and **ForkPress Dev
  Drive** shortcuts.

The elevated Dev Drive path does not execute PowerShell scripts from
user-writable storage. The installer runs from an elevated Program Files install,
the VHDX backing file lives under admin-writable ProgramData storage, and the
logon auto-mount task stores a fixed command instead of pointing at a mutable
script file.

## Storage Cascade

`forkpress init` probes the actual project directory instead of trusting the OS
name:

1. If the current directory supports file clones, use materialized COW branches
   in place.
2. On Windows, the clone primitive is ReFS block cloning through
   `FSCTL_DUPLICATE_EXTENTS_TO_FILE`.
3. If the current Windows directory cannot clone files, fail closed and tell the
   user to run ForkPress Setup. Windows should not silently initialize a large
   full-copy site on NTFS.
4. ProjFS remains the next Windows-native lazy namespace candidate, but it is
   an optional Windows component and needs a separate provider implementation.
5. Full file copy is the terminal fallback, not the first fallback.

## Why ReFS Dev Drive First

ReFS block cloning gives ForkPress ordinary Win32 paths. Editors, PHP, Git,
WP-CLI, backup tools, and shell commands can read and write branch files without
knowing about ForkPress. Writes to a cloned branch file do not mutate the source
branch because ReFS performs allocate-on-write at the cluster level.

That matches the current materialized COW model on macOS and Linux:

```text
main\wp-load.php       shares ReFS clusters with
marketing\wp-load.php  until one side writes
```

## Current Boundaries

- The Windows implementation supports materialized COW, not lazy
  namespace COW. Branch creation still walks the source tree and creates a full
  directory namespace.
- The installer path is designed for Windows 11 systems with Dev Drive support.
  Older Windows builds fail with a clear update/reboot message instead of
  falling back to a huge copy.
- Release builds can Authenticode-sign `forkpress.exe` and `ForkPressSetup.exe`
  when `WINDOWS_CODESIGN_CERT_BASE64` and `WINDOWS_CODESIGN_PASSWORD` are set in
  GitHub Actions secrets. Without those secrets, PR builds produce unsigned
  smoke-tested artifacts.
- ProjFS support is not implemented yet.
- Semantic database merge is separate from the file storage strategy.

## References

- Windows Dev Drive setup: <https://learn.microsoft.com/en-us/windows/dev-drive/>
- ReFS block cloning: <https://learn.microsoft.com/en-us/windows/win32/fileio/block-cloning>
- `FSCTL_DUPLICATE_EXTENTS_TO_FILE`: <https://learn.microsoft.com/en-us/windows/win32/api/winioctl/ni-winioctl-fsctl_duplicate_extents_to_file>
- Enabling ProjFS: <https://learn.microsoft.com/en-us/windows/win32/projfs/enabling-windows-projected-file-system>
