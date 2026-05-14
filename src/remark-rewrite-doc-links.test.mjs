import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import rewriteDocLinks, { rewriteDocLink, routeIdForEntry } from './remark-rewrite-doc-links.mjs';

test('rewrites sibling Markdown links to generated Starlight routes', (t) => {
	const projectRoot = createProject(t, [
		'docs/storage-drivers.md',
		'docs/lazy-overlay-cow.md',
		'docs/windows-cow.mdx',
	]);

	assert.equal(
		rewriteDocLink('lazy-overlay-cow.md', 'docs/storage-drivers.md', projectRoot),
		'../lazy-overlay-cow/',
	);
	assert.equal(
		rewriteDocLink('windows-cow.mdx#setup', 'docs/storage-drivers.md', projectRoot),
		'../windows-cow/#setup',
	);
});

test('rewrites README links through collection route ids', (t) => {
	const projectRoot = createProject(t, ['README.md', 'crates/forkpress-core/README.mdx']);

	assert.equal(rewriteDocLink('../../README.md', 'crates/forkpress-core/README.mdx', projectRoot), '../../');
	assert.equal(routeIdForEntry('crates/forkpress-core/README.mdx'), 'crates/forkpress-core');
});

test('leaves non-doc, missing, absolute, and external links untouched', (t) => {
	const projectRoot = createProject(t, ['docs/storage-drivers.md']);

	for (const url of [
		'image.png',
		'missing.md',
		'#heading',
		'/forkpress/docs/windows-cow/',
		'https://example.com/page.md',
	]) {
		assert.equal(rewriteDocLink(url, 'docs/storage-drivers.md', projectRoot), url);
	}
});

test('rewrites link and definition nodes in a Markdown tree', (t) => {
	const projectRoot = createProject(t, ['docs/storage-drivers.md', 'docs/lazy-overlay-cow.md']);
	const tree = {
		type: 'root',
		children: [
			{ type: 'paragraph', children: [{ type: 'link', url: 'lazy-overlay-cow.md', children: [] }] },
			{ type: 'definition', identifier: 'lazy', url: 'lazy-overlay-cow.md' },
		],
	};

	rewriteDocLinks({ projectRoot })(tree, {
		path: path.join(projectRoot, 'docs/storage-drivers.md'),
	});

	assert.equal(tree.children[0].children[0].url, '../lazy-overlay-cow/');
	assert.equal(tree.children[1].url, '../lazy-overlay-cow/');
});

function createProject(t, files) {
	const projectRoot = mkdtempSync(path.join(tmpdir(), 'forkpress-docs-'));
	t.after(() => rmSync(projectRoot, { recursive: true, force: true }));

	for (const file of files) {
		const filePath = path.join(projectRoot, file);
		mkdirSync(path.dirname(filePath), { recursive: true });
		writeFileSync(filePath, '# Test\n');
	}

	return projectRoot;
}
