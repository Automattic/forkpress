import test from 'node:test';
import assert from 'node:assert/strict';

import {
	BuildkiteArtifactError,
	findFinishedArtifact,
	parseArgs,
	selectArtifact,
	selectPassedBuild,
} from './release-download-buildkite-artifact.mjs';

test('parseArgs requires the release artifact inputs', () => {
	assert.throws(
		() => parseArgs(['--commit', 'abc']),
		(error) => error instanceof BuildkiteArtifactError && error.message.includes('--artifact-path is required'),
	);
});

test('parseArgs accepts Buildkite selection overrides', () => {
	assert.deepEqual(
		parseArgs([
			'--commit',
			'abcdef',
			'--artifact-path',
			'target/aarch64-apple-darwin/release/forkpress',
			'--output',
			'dist/forkpress',
			'--org',
			'automattic',
			'--pipeline',
			'forkpress',
			'--wait-seconds',
			'10',
			'--poll-seconds',
			'2',
		]),
		{
			apiBase: 'https://api.buildkite.com/v2',
			org: 'automattic',
			pipeline: 'forkpress',
			commit: 'abcdef',
			artifactPath: 'target/aarch64-apple-darwin/release/forkpress',
			output: 'dist/forkpress',
			waitSeconds: 10,
			pollSeconds: 2,
		},
	);
});

test('selectPassedBuild picks the newest passed build for the exact commit', () => {
	assert.deepEqual(
		selectPassedBuild(
			[
				{ number: 41, commit: 'abc', state: 'failed' },
				{ number: 43, commit: 'abc', state: 'passed' },
				{ number: 44, commit: 'other', state: 'passed' },
				{ number: 42, commit: 'abc', state: 'passed' },
			],
			'abc',
		),
		{ number: 43, commit: 'abc', state: 'passed' },
	);
});

test('selectArtifact requires one finished artifact at the exact path', () => {
	assert.deepEqual(
		selectArtifact(
			[
				{ path: 'target/aarch64-apple-darwin/release/forkpress', state: 'finished' },
				{ path: 'target/x86_64-apple-darwin/release/forkpress', state: 'finished' },
			],
			'target/aarch64-apple-darwin/release/forkpress',
		),
		{ path: 'target/aarch64-apple-darwin/release/forkpress', state: 'finished' },
	);
});

test('selectArtifact rejects missing artifacts', () => {
	assert.throws(
		() => selectArtifact([{ path: 'forkpress', state: 'finished' }], 'missing'),
		(error) => error instanceof BuildkiteArtifactError && error.message.includes('Buildkite artifact not found'),
	);
});

test('findFinishedArtifact returns null while the artifact is not available yet', () => {
	assert.equal(
		findFinishedArtifact(
			[
				{ path: 'target/aarch64-apple-darwin/release/forkpress', state: 'uploading' },
				{ path: 'target/x86_64-apple-darwin/release/forkpress', state: 'finished' },
			],
			'target/aarch64-apple-darwin/release/forkpress',
		),
		null,
	);
});
