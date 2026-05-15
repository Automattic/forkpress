# Documentation site

The documentation site is an Astro Starlight project at the repository root.
It loads Markdown and MDX files from their existing locations instead of
requiring a separate docs source tree.

## Content sources

The `docs` content collection loads:

- `README.md` (becomes the site index);
- `docs/**/*.{md,mdx}`;
- nested `README.md` files under existing project directories (their parent
  directory becomes the route path).

Route IDs are generated from repository-relative source paths. A first H1
heading in a Markdown file is removed at build time and reused as the page
title.

## Commands

```bash
npm install        # install dependencies
npm run dev        # local development server
npm run build      # static site, Pagefind index, llms.txt
npm run validate   # checks, tests, and the static build (CI)
npm run preview    # preview the built site locally
```

The build publishes `docs-dist/`, including `llms.txt`, `llms-full.txt`, and
the Pagefind search index.
