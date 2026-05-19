# Windows build chunk of `windows-cow-check`:
#   - cargo build --release forkpress.exe (with empty runtime bundle)
#
# GHA stops at the test chunk; the Windows release binary is only built
# inside release-publish. We surface it here on every CI run too, as a
# downloadable smoke artifact (no embedded PHP runtime, no signing) —
# enough to confirm the Windows build path is still healthy without
# waiting for a release cut.
#
# Gated on `windows-tests` passing. The binary is uploaded by
# `artifact_paths` in pipeline.yml.

$ErrorActionPreference = 'Stop'

$TARGET = 'x86_64-pc-windows-msvc'

Write-Output "--- :information_source: Host"
$PSVersionTable.PSVersion
[System.Environment]::OSVersion

Write-Output "--- :crab: Installing Rust via rustup"
. "$PSScriptRoot\_lib\install-rust.ps1" -Target $TARGET

Write-Output "--- :crab: cargo build --release forkpress.exe ($TARGET)"
# Empty runtime bundle: build.rs of forkpress-cli sees FORKPRESS_RUNTIME_BUNDLE
# pointing at an empty/missing file and skips the static-PHP embed,
# stamping the binary with an "external" runtime ID. Windows runners
# can't build static PHP from spc; release-publish embeds the prebuilt
# bundle separately. This artifact is build-path coverage only.
$bundle = Join-Path $pwd 'empty-runtime.tar.gz'
Set-Content -Path $bundle -Value '' -NoNewline
$env:FORKPRESS_RUNTIME_BUNDLE = $bundle
cargo build --release --target $TARGET -p forkpress-cli --bin forkpress --locked
if ($LASTEXITCODE -ne 0) { throw "cargo build --release failed: $LASTEXITCODE" }
Remove-Item -LiteralPath $bundle -Force -ErrorAction SilentlyContinue

Get-Item "target/$TARGET/release/forkpress.exe" | Format-List Name, Length, LastWriteTime
