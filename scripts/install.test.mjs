import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

test('curl installer installs and verifies a release asset', (t) => {
	const root = mkdtempSync(path.join(tmpdir(), 'forkpress-install-test-'));
	t.after(() => rmSync(root, { recursive: true, force: true }));

	const release = path.join(root, 'release');
	const build = path.join(root, 'build');
	const installDir = path.join(root, 'bin');
	mkdirSync(release, { recursive: true });
	mkdirSync(build, { recursive: true });
	writeFileSync(path.join(build, 'forkpress'), '#!/bin/sh\necho "forkpress 0.1.13"\n');
	spawn('chmod', ['0755', path.join(build, 'forkpress')]);

	const asset = 'forkpress-test-target.tar.gz';
	const archive = path.join(release, asset);
	spawn('tar', ['-czf', archive, '-C', build, 'forkpress']);
	const checksum = createHash('sha256').update(readFileSync(archive)).digest('hex');
	writeFileSync(path.join(release, 'SHA256SUMS'), `${checksum}  ${asset}\n`);

	const result = spawn('sh', ['scripts/install.sh'], {
		env: {
			...process.env,
			FORKPRESS_INSTALL_DIR: installDir,
			FORKPRESS_RELEASE_BASE_URL: `file://${release}`,
			FORKPRESS_TARGET: 'test-target',
			PATH: process.env.PATH,
		},
	});

	assert.equal(result.status, 0, result.stderr);
	assert.match(result.stdout, /forkpress 0\.1\.13/);
	assert.match(spawn(path.join(installDir, 'forkpress'), ['--version']).stdout, /forkpress 0\.1\.13/);
});

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
