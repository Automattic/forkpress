# Development

Local development needs a Rust toolchain, Make, PHP, PHP development headers,
and SQLite development libraries.

## Build

```bash
make dist          # build the production static PHP runtime
make forkpress     # embed the runtime and build the production binary
```

The dist target compiles a static PHP from source. The first build takes
about 3–5 minutes on Apple silicon; subsequent runs reuse the cached PHP.

## Test

Rust tests:

```bash
cargo test --workspace --exclude forkpress-cli
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli
```

PHP tests:

```bash
make test-all
```

## Documentation site

The documentation site is an Astro Starlight project at the repository root.

```bash
npm install
npm run dev        # local dev server
npm run validate   # check, tests, and a static build
```

`npm run validate` runs Astro checks, documentation tests, and the static
site build. See [Documentation site](documentation-site.md) for content
sources and routing details.
