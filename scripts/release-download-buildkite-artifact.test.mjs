import test from 'node:test';
import assert from 'node:assert/strict';

import {
	artifactPathMatches,
	BuildkiteArtifactError,
	createBuildkiteClient,
	findFinishedArtifact,
	isTransientBuildkiteStatus,
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

test('artifactPathMatches accepts Buildkite path normalization differences', () => {
	assert.equal(
		artifactPathMatches(
			'.\\target\\x86_64-pc-windows-msvc\\release\\forkpress.exe',
			'target/x86_64-pc-windows-msvc/release/forkpress.exe',
		),
		true,
	);
	assert.equal(
		artifactPathMatches(
			'/var/lib/buildkite-agent/builds/forkpress/target/aarch64-apple-darwin/release/forkpress',
			'target/aarch64-apple-darwin/release/forkpress',
		),
		true,
	);
});

test('isTransientBuildkiteStatus identifies retryable API responses', () => {
	for (const status of [429, 500, 502, 503, 504]) {
		assert.equal(isTransientBuildkiteStatus(status), true, `${status} should be retryable`);
	}
	for (const status of [400, 401, 403, 404, 422]) {
		assert.equal(isTransientBuildkiteStatus(status), false, `${status} should not be retryable`);
	}
});

test('getJson retries transient Buildkite API failures', async () => {
	const calls = [];
	const client = createBuildkiteClient({
		token: 'token',
		apiBase: 'https://buildkite.example/v2',
		retryDelayMs: 0,
		sleepImpl: async () => {},
		fetchImpl: async (url, options) => {
			calls.push({ url, options });
			if (calls.length === 1) {
				return {
					ok: false,
					status: 500,
					statusText: 'Internal Server Error',
				};
			}
			return {
				ok: true,
				json: async () => ({ passed: true }),
			};
		},
	});

	assert.deepEqual(await client.getJson('/organizations/automattic/pipelines/forkpress/builds'), { passed: true });
	assert.equal(calls.length, 2);
	assert.equal(calls[0].url, 'https://buildkite.example/v2/organizations/automattic/pipelines/forkpress/builds');
	assert.equal(calls[0].options.headers.Authorization, 'Bearer token');
});

test('getJson retries transient Buildkite network failures', async () => {
	let calls = 0;
	const networkError = new TypeError('fetch failed');
	networkError.cause = { code: 'UND_ERR_CONNECT_TIMEOUT' };
	const client = createBuildkiteClient({
		token: 'token',
		apiBase: 'https://buildkite.example/v2',
		retryDelayMs: 0,
		sleepImpl: async () => {},
		fetchImpl: async () => {
			calls += 1;
			if (calls === 1) {
				throw networkError;
			}
			return {
				ok: true,
				json: async () => [{ number: 476 }],
			};
		},
	});

	assert.deepEqual(await client.getJson('/organizations/automattic/pipelines/forkpress/builds'), [{ number: 476 }]);
	assert.equal(calls, 2);
});

test('getJson keeps artifact permission failures non-retryable', async () => {
	let calls = 0;
	const client = createBuildkiteClient({
		token: 'token',
		apiBase: 'https://buildkite.example/v2',
		fetchImpl: async () => {
			calls += 1;
			return {
				ok: false,
				status: 403,
				statusText: 'Forbidden',
			};
		},
	});

	await assert.rejects(
		() => client.getJson('/organizations/automattic/pipelines/forkpress/builds/1/artifacts?per_page=100'),
		(error) => error instanceof BuildkiteArtifactError && error.message.includes('read_artifacts REST API scope'),
	);
	assert.equal(calls, 1);
});
