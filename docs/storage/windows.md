# Windows storage

ForkPress uses native ReFS block cloning on Windows. The supported user path is
the Windows installer, which prepares a clone-capable Dev Drive and creates a
starter site there.

## Installer flow

For a fresh Windows machine:

1. Download `ForkPressSetup.exe`.
2. Open it.
3. Accept the Windows permission prompt.
4. Reboot only if Windows asks.
5. Open **Start ForkPress Site** from the desktop or Start Menu.

The installer:

- installs protected program files under `%ProgramFiles%\ForkPress`;
- creates a ReFS Dev Drive VHDX at
  `%ProgramData%\ForkPress\Storage\forkpress-dev-drive.vhdx`;
- mounts it at `%USERPROFILE%\ForkPressDevDrive`;
- adds `forkpress.exe` to the user `PATH`;
- creates `%USERPROFILE%\ForkPressDevDrive\Sites\My ForkPress Site`;
- runs `forkpress init` in that site folder;
- creates **Start ForkPress Site**, **ForkPress Shell**, and **ForkPress Dev
  Drive** shortcuts.

It does not require WSL, Docker, FUSE, WinFsp, or manual Windows feature setup.

## ReFS block cloning

When a project lives on a ReFS Dev Drive, ForkPress uses
`FSCTL_DUPLICATE_EXTENTS_TO_FILE` to clone file extents. Writes to a cloned
branch file do not mutate the source branch because ReFS allocates new clusters
for changed data.

```text
main\wp-load.php       shares ReFS clusters with
marketing\wp-load.php  until one side writes
```

## Boundaries

- The current Windows model is materialized COW, not lazy namespace COW.
- Branch creation still creates a full directory namespace.
- The installer path is designed for Windows 11 systems with Dev Drive support.
- The current Windows package ships an x64 binary. Windows 11 on Arm64 runs it
  through Windows x64 emulation.
