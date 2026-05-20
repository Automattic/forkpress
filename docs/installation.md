# Installation

ForkPress ships native release artifacts for macOS, Linux, and Windows.

## macOS

```bash
brew install automattic/tap/forkpress
```

Without Homebrew:

```bash
curl -fsSL https://raw.githubusercontent.com/Automattic/forkpress/trunk/scripts/install.sh | sh
```

## Linux

```bash
curl -fsSL https://raw.githubusercontent.com/Automattic/forkpress/trunk/scripts/install.sh | sh
```

This installs `forkpress` into `$HOME/.local/bin`.

If you already use Homebrew on Linux, this works too:

```bash
brew install automattic/tap/forkpress
```

## Windows

Download `ForkPressSetup.exe` from
<https://github.com/Automattic/forkpress/releases> and run it.

The installer:

- installs `forkpress.exe`;
- adds ForkPress to the user `PATH`;
- prepares a clone-capable ReFS Dev Drive;
- creates a starter site;
- installs Start Menu and desktop shortcuts.

The installer does not require WSL, Docker, WinFsp, or manual Windows feature
setup.

## Local launcher

Use the launcher when a project should carry its own ForkPress entry point:

```bash
curl -fsSL https://raw.githubusercontent.com/Automattic/forkpress/trunk/scripts/forkpress -o forkpress
chmod +x forkpress
./forkpress init
```

The launcher downloads and verifies the real binary on first run, then caches it
under `.forkpress-bin/` next to the launcher.

## Pinning

Pin the installer or launcher to a release:

```bash
FORKPRESS_VERSION=vX.Y.Z ./forkpress serve
```

The installer also accepts the variable:

```bash
curl -fsSL https://raw.githubusercontent.com/Automattic/forkpress/trunk/scripts/install.sh | FORKPRESS_VERSION=vX.Y.Z sh
```

Useful overrides:

| Variable | Used by | Purpose |
| --- | --- | --- |
| `FORKPRESS_VERSION` | installer, launcher | Release version, with or without a leading `v`. Defaults to `latest`. |
| `FORKPRESS_INSTALL_DIR` | installer | Destination directory. Defaults to `$HOME/.local/bin`. |
| `FORKPRESS_CACHE_DIR` | launcher | Binary cache directory. Defaults to `.forkpress-bin` beside the launcher. |
| `FORKPRESS_REFRESH` | launcher | Set to `1` to re-download even when a cached binary exists. |
| `FORKPRESS_TARGET` | installer, launcher | Force a release target instead of auto-detecting. |
| `FORKPRESS_REPO` | installer, launcher | Alternate `owner/repo` for testing release artifacts. |
| `FORKPRESS_RELEASE_BASE_URL` | installer, launcher | Alternate release asset base URL for testing. |

## Manual downloads

Release artifacts are available from
<https://github.com/Automattic/forkpress/releases>.

| Platform | Artifact |
| --- | --- |
| macOS Apple silicon | `forkpress-aarch64-apple-darwin.tar.gz` |
| macOS Intel | `forkpress-x86_64-apple-darwin.tar.gz` |
| Linux ARM64 | `forkpress-aarch64-unknown-linux-musl.tar.gz` |
| Linux x86_64 | `forkpress-x86_64-unknown-linux-musl.tar.gz` |
| Windows x86_64 | `ForkPressSetup.exe` and `forkpress-x86_64-pc-windows-msvc.zip` |

For macOS and Linux:

```bash
curl -L -o forkpress.tar.gz \
  "https://github.com/Automattic/forkpress/releases/download/<tag>/forkpress-<target>.tar.gz"
tar -xzf forkpress.tar.gz
chmod +x forkpress
./forkpress --version
```

Replace `<tag>` with a release tag like `vX.Y.Z` and `<target>` with one of
the artifact targets from the table.

Check `SHA256SUMS` from the same release before trusting manually downloaded
artifacts.
