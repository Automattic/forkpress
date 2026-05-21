$ErrorActionPreference = 'Stop'

$TARGET = 'x86_64-pc-windows-msvc'

Write-Output "--- :crab: Installing Rust via rustup"
. "$PSScriptRoot\_lib\install-rust.ps1" -Target $TARGET

. "$PSScriptRoot\_lib\empty-runtime.ps1"

Write-Output "--- :crab: cargo test (workspace, excluding forkpress-cli)"
cargo test --target $TARGET --workspace --exclude forkpress-cli --locked
if ($LASTEXITCODE -ne 0) { throw "workspace test failed: $LASTEXITCODE" }

Write-Output "--- :crab: cargo test -p forkpress-cli --bin forkpress (external runtime)"
Use-EmptyRuntimeBundle {
    cargo test --target $TARGET -p forkpress-cli --bin forkpress --locked
    if ($LASTEXITCODE -ne 0) { throw "forkpress-cli test failed: $LASTEXITCODE" }
}

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
