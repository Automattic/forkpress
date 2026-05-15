import assert from 'node:assert/strict';
import test from 'node:test';

import {
	generateHomebrewFormula,
	homebrewAssets,
	parseSha256Sums,
} from './release-homebrew-formula.mjs';

test('parses SHA256SUMS entries used by Homebrew', () => {
	const sums = parseSha256Sums(`
${'a'.repeat(64)}  forkpress-aarch64-apple-darwin.tar.gz
${'b'.repeat(64)} *forkpress-x86_64-apple-darwin.tar.gz
`);

	assert.equal(sums.get('forkpress-aarch64-apple-darwin.tar.gz'), 'a'.repeat(64));
	assert.equal(sums.get('forkpress-x86_64-apple-darwin.tar.gz'), 'b'.repeat(64));
});

test('generates a ForkPress formula with platform-specific assets', () => {
	const sums = new Map(homebrewAssets.map((asset, index) => [asset.name, `${index}`.repeat(64)]));
	const formula = generateHomebrewFormula('0.1.13', sums);

	assert.match(formula, /class Forkpress < Formula/);
	assert.match(formula, /version "0\.1\.13"/);
	assert.match(formula, /license "GPL-2\.0-only"/);
	for (const asset of homebrewAssets) {
		assert.match(
			formula,
			new RegExp(`https://github.com/Automattic/forkpress/releases/download/v0\\.1\\.13/${asset.name}`),
		);
	}
	assert.match(formula, /assert_match version\.to_s, shell_output/);
});

test('rejects formula generation when a required checksum is missing', () => {
	assert.throws(
		() => generateHomebrewFormula('0.1.13', new Map()),
		/SHA256SUMS is missing forkpress-aarch64-apple-darwin\.tar\.gz/,
	);
});
