# Mirrors GHA `windows-cow-check`:
#   - Install Rust + the MSVC target
#   - cargo test --workspace --exclude forkpress-cli
#   - cargo test -p forkpress-cli --bin forkpress (with empty runtime bundle)
#   - PowerShell syntax check of the 5 installer/build scripts
#   - tests/windows/installer-error-surface.ps1
#
# Runs on the BK `windows` queue. No Docker — native PowerShell on the
# Windows VM. Caching is out of scope per the migration plan.

$ErrorActionPreference = 'Stop'

$TARGET = 'x86_64-pc-windows-msvc'

Write-Output "--- :information_source: Host"
$PSVersionTable.PSVersion
[System.Environment]::OSVersion

Write-Output "--- :crab: Installing Rust via rustup"
# The BK Windows agent appears to have a `cargo` on PATH without a matching
# `rustup` (possibly from a Chocolatey install). Run rustup-init
# unconditionally; it's a no-op when an in-place toolchain is already there,
# and the explicit PATH prepend ensures `%USERPROFILE%\.cargo\bin` wins over
# any prior `cargo.exe` on PATH.
$rustupExe = Join-Path $env:TEMP 'rustup-init.exe'
Invoke-WebRequest -Uri 'https://win.rustup.rs/x86_64' -OutFile $rustupExe
& $rustupExe -y --default-toolchain stable --profile minimal --default-host $TARGET
if ($LASTEXITCODE -ne 0) { throw "rustup-init failed: $LASTEXITCODE" }
$env:PATH = "$env:USERPROFILE\.cargo\bin;$env:PATH"
rustup target add $TARGET
rustc --version
cargo --version

Write-Output "--- :crab: cargo test (workspace, excluding forkpress-cli)"
cargo test --target $TARGET --workspace --exclude forkpress-cli --locked
if ($LASTEXITCODE -ne 0) { throw "workspace test failed: $LASTEXITCODE" }

Write-Output "--- :crab: cargo test -p forkpress-cli --bin forkpress (external runtime)"
$bundle = Join-Path $pwd 'empty-runtime.tar.gz'
Set-Content -Path $bundle -Value '' -NoNewline
$env:FORKPRESS_RUNTIME_BUNDLE = $bundle
cargo test --target $TARGET -p forkpress-cli --bin forkpress --locked
if ($LASTEXITCODE -ne 0) { throw "forkpress-cli test failed: $LASTEXITCODE" }
Remove-Item -LiteralPath $bundle -Force -ErrorAction SilentlyContinue

Write-Output "--- :file_folder: Windows script syntax"
foreach ($script in @(
    'scripts/windows/build-dist.ps1',
    'scripts/windows/install.ps1',
    'scripts/windows/setup-dev-drive.ps1',
    'scripts/windows/package.ps1',
    'scripts/windows/sign.ps1'
)) {
    [scriptblock]::Create((Get-Content -Raw $script)) | Out-Null
}

Write-Output "--- :test_tube: Windows installer error-surface checks"
tests/windows/installer-error-surface.ps1
if ($LASTEXITCODE -ne 0) { throw "installer-error-surface failed: $LASTEXITCODE" }
