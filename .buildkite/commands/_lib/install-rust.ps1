# Install a stable Rust toolchain via rustup on a Windows BK runner.
#
# The BK Windows agent appears to have a `cargo` on PATH without a
# matching `rustup` (possibly a Chocolatey artefact). Run rustup-init
# unconditionally — it's a no-op when an in-place toolchain is already
# there, and the explicit PATH prepend ensures
# `%USERPROFILE%\.cargo\bin` wins over any prior `cargo.exe`.
#
# Usage (PowerShell dot-source):
#   . "$PSScriptRoot\_lib\install-rust.ps1" -Target 'x86_64-pc-windows-msvc'

param(
    [Parameter(Mandatory = $true)]
    [string]$Target
)

$rustupExe = Join-Path $env:TEMP 'rustup-init.exe'
Invoke-WebRequest -Uri 'https://win.rustup.rs/x86_64' -OutFile $rustupExe
& $rustupExe -y --default-toolchain stable --profile minimal --default-host $Target
if ($LASTEXITCODE -ne 0) { throw "rustup-init failed: $LASTEXITCODE" }
$env:PATH = "$env:USERPROFILE\.cargo\bin;$env:PATH"
rustup target add $Target
rustc --version
cargo --version
