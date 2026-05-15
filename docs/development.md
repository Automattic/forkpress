# Development

Local development needs a Rust toolchain, Make, PHP, PHP development headers,
and SQLite development libraries.

## Build

Build the production runtime bundle and binary:

```bash
make dist
make forkpress
```

`make dist` builds the production static PHP runtime. `make forkpress` embeds
that runtime and the PHP/WordPress assets into the production Rust binary.

## Test

Run Rust tests:

```bash
cargo test --workspace --exclude forkpress-cli
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli
```

Run PHP tests:

```bash
make test-all
```

## Documentation site

The documentation site is an Astro Starlight project at the repository root.

```bash
npm install
npm run dev
npm run validate
```

`npm run validate` runs Astro checks, documentation tests, and the static site
build.
