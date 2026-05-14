import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const docsOutDir = 'docs-dist';

test('builds documentation into a docs-only directory', () => {
	const astroConfig = readFileSync(path.join(projectRoot, 'astro.config.mjs'), 'utf8');

	assert.match(astroConfig, new RegExp(`\\boutDir:\\s*['"]${docsOutDir}['"]`));
	assert.doesNotMatch(astroConfig, /\boutDir:\s*['"]dist['"]/);
});

test('uploads the configured docs output directory to GitHub Pages', () => {
	const workflow = readFileSync(path.join(projectRoot, '.github/workflows/docs.yml'), 'utf8');

	assert.match(workflow, new RegExp(`\\n\\s+path: ${docsOutDir}\\n`));
});
