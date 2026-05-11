# Windows COW Setup

Status: 2026-05-11

ForkPress should not ask Windows users to install WSL, Docker, FUSE, WinFsp, or
developer-only storage tools before they can create cheap COW branches. The
Windows path is native ReFS block cloning on a Dev Drive.

## Intended User Flow

For a fresh Windows laptop:

1. Install ForkPress.
2. Run the Windows setup flow once and accept the UAC prompt.
3. If Windows asks for a reboot after enabling optional components, reboot.
4. Open the ForkPress Dev Drive folder.
5. Run `forkpress init`.

The script backing that setup flow is:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\windows\setup-dev-drive.ps1
```

By default it creates:

```text
%LOCALAPPDATA%\ForkPress\Storage\forkpress-dev-drive.vhdx
%USERPROFILE%\ForkPressDevDrive\
```

The VHDX is dynamic, so the file grows with real data rather than immediately
allocating the configured maximum size. The default maximum is 128 GB because
Windows Dev Drive volumes have a 50 GB minimum.

## Storage Cascade

`forkpress init` probes the actual project directory instead of trusting the OS
name:

1. If the current directory supports file clones, use materialized COW branches
   in place.
2. On Windows, the clone primitive is ReFS block cloning through
   `FSCTL_DUPLICATE_EXTENTS_TO_FILE`.
3. If the current Windows directory cannot clone files, guide the user to the
   Dev Drive setup flow before treating full copy as acceptable.
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

- The first Windows implementation supports materialized COW, not lazy
  namespace COW. Branch creation still walks the source tree and creates a full
  directory namespace.
- The Dev Drive setup script is the install-time building block. A release
  installer should call the same flow rather than asking users to paste
  PowerShell.
- ProjFS support is not implemented yet.
- Semantic database merge is separate from the file storage strategy.

## References

- Windows Dev Drive setup: <https://learn.microsoft.com/en-us/windows/dev-drive/>
- ReFS block cloning: <https://learn.microsoft.com/en-us/windows/win32/fileio/block-cloning>
- `FSCTL_DUPLICATE_EXTENTS_TO_FILE`: <https://learn.microsoft.com/en-us/windows/win32/api/winioctl/ni-winioctl-fsctl_duplicate_extents_to_file>
- Enabling ProjFS: <https://learn.microsoft.com/en-us/windows/win32/projfs/enabling-windows-projected-file-system>
