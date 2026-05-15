import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

test('local launcher downloads, verifies, caches, and execs forkpress', (t) => {
	const root = mkdtempSync(path.join(tmpdir(), 'forkpress-launcher-test-'));
	t.after(() => rmSync(root, { recursive: true, force: true }));

	const project = path.join(root, 'project');
	const release = path.join(root, 'release');
	const build = path.join(root, 'build');
	mkdirSync(project, { recursive: true });
	mkdirSync(release, { recursive: true });
	mkdirSync(build, { recursive: true });
	copyFileSync('scripts/forkpress', path.join(project, 'forkpress'));
	spawn('chmod', ['0755', path.join(project, 'forkpress')]);

	writeFileSync(path.join(build, 'forkpress'), '#!/bin/sh\necho "forkpress 0.1.13 $*"\n');
	spawn('chmod', ['0755', path.join(build, 'forkpress')]);

	const asset = 'forkpress-test-target.tar.gz';
	const archive = path.join(release, asset);
	spawn('tar', ['-czf', archive, '-C', build, 'forkpress']);
	const checksum = createHash('sha256').update(readFileSync(archive)).digest('hex');
	writeFileSync(path.join(release, 'SHA256SUMS'), `${checksum}  ${asset}\n`);

	const first = runLauncher(project, release, 'alpha');
	assert.equal(first.status, 0, first.stderr);
	assert.match(first.stderr, /downloading forkpress-test-target\.tar\.gz/);
	assert.match(first.stdout, /forkpress 0\.1\.13 alpha/);
	assert.equal(
		spawnSync('test', ['-x', path.join(project, '.forkpress-bin/latest/test-target/forkpress')]).status,
		0,
	);

	rmSync(release, { recursive: true, force: true });
	const second = runLauncher(project, release, 'beta');
	assert.equal(second.status, 0, second.stderr);
	assert.equal(second.stderr, '');
	assert.match(second.stdout, /forkpress 0\.1\.13 beta/);
});

function runLauncher(project, release, arg) {
	return spawnSync('./forkpress', [arg], {
		cwd: project,
		encoding: 'utf8',
		env: {
			...process.env,
			FORKPRESS_RELEASE_BASE_URL: `file://${release}`,
			FORKPRESS_TARGET: 'test-target',
			PATH: process.env.PATH,
		},
	});
}

function spawn(command, args, options = {}) {
	const result = spawnSync(command, args, {
		cwd: path.resolve(path.dirname(new URL(import.meta.url).pathname), '..'),
		encoding: 'utf8',
		...options,
	});
	if (result.status !== 0) {
		throw new Error(`${command} ${args.join(' ')} failed\n${result.stderr}`);
	}
	return result;
}
