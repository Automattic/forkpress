# GHA only builds the Windows release binary inside release-publish; we
# surface it on every CI run too as a downloadable smoke artifact (no
# embedded PHP runtime, no signing) so the Windows build path stays
# verified without waiting for a release cut.

$ErrorActionPreference = 'Stop'

$TARGET = 'x86_64-pc-windows-msvc'

Write-Output "--- :crab: Installing Rust via rustup"
. "$PSScriptRoot\_lib\install-rust.ps1" -Target $TARGET

. "$PSScriptRoot\_lib\empty-runtime.ps1"

Write-Output "--- :crab: cargo build --release forkpress.exe ($TARGET)"
Use-EmptyRuntimeBundle {
    cargo build --release --target $TARGET -p forkpress-cli --bin forkpress --locked
    if ($LASTEXITCODE -ne 0) { throw "cargo build --release failed: $LASTEXITCODE" }
}

Get-Item "target/$TARGET/release/forkpress.exe" | Format-List Name, Length, LastWriteTime
