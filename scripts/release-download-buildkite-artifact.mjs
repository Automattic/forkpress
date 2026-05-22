#!/usr/bin/env node
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const DEFAULT_API_BASE = 'https://api.buildkite.com/v2';
const DEFAULT_ORG = 'automattic';
const DEFAULT_PIPELINE = 'forkpress';
const DEFAULT_POLL_SECONDS = 30;
const DEFAULT_WAIT_SECONDS = 45 * 60;
const DEFAULT_API_RETRIES = 3;
const DEFAULT_API_RETRY_DELAY_MS = 1000;

export class BuildkiteArtifactError extends Error {}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
	main(process.argv.slice(2));
}

export async function main(argv) {
	try {
		const options = parseArgs(argv);
		const token = process.env.BUILDKITE_API_TOKEN;
		if (!token) {
			throw new BuildkiteArtifactError('BUILDKITE_API_TOKEN is required to download Buildkite artifacts.');
		}
		const client = createBuildkiteClient({ token, apiBase: options.apiBase });
		const { build, artifact } = await waitForBuildArtifact(client, options);
		await downloadArtifact(client, artifact, options.output);
		console.log(`Downloaded ${artifact.path} from Buildkite build #${build.number} to ${options.output}.`);
	} catch (error) {
		if (error instanceof BuildkiteArtifactError) {
			console.error(error.message);
			process.exit(1);
		}
		throw error;
	}
}

export function parseArgs(argv) {
	const options = {
		apiBase: process.env.BUILDKITE_API_BASE || DEFAULT_API_BASE,
		org: process.env.BUILDKITE_ORG || DEFAULT_ORG,
		pipeline: process.env.BUILDKITE_PIPELINE || DEFAULT_PIPELINE,
		commit: null,
		artifactPath: null,
		output: null,
		waitSeconds: DEFAULT_WAIT_SECONDS,
		pollSeconds: DEFAULT_POLL_SECONDS,
	};

	for (let index = 0; index < argv.length; index += 1) {
		const arg = argv[index];
		if (arg === '--commit') {
			options.commit = readValue(argv, index, arg);
			index += 1;
		} else if (arg === '--artifact-path') {
			options.artifactPath = readValue(argv, index, arg);
			index += 1;
		} else if (arg === '--output') {
			options.output = readValue(argv, index, arg);
			index += 1;
		} else if (arg === '--org') {
			options.org = readValue(argv, index, arg);
			index += 1;
		} else if (arg === '--pipeline') {
			options.pipeline = readValue(argv, index, arg);
			index += 1;
		} else if (arg === '--api-base') {
			options.apiBase = readValue(argv, index, arg).replace(/\/+$/, '');
			index += 1;
		} else if (arg === '--wait-seconds') {
			options.waitSeconds = parseNonNegativeInteger(readValue(argv, index, arg), arg);
			index += 1;
		} else if (arg === '--poll-seconds') {
			options.pollSeconds = parseNonNegativeInteger(readValue(argv, index, arg), arg);
			index += 1;
		} else if (arg === '--help') {
			printUsage();
			process.exit(0);
		} else {
			throw new BuildkiteArtifactError(`Unknown option: ${arg}`);
		}
	}

	for (const required of ['commit', 'artifactPath', 'output']) {
		if (!options[required]) {
			throw new BuildkiteArtifactError(`--${required.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)} is required.`);
		}
	}
	return options;
}

export async function waitForPassedBuild(client, options) {
	const deadline = Date.now() + options.waitSeconds * 1000;
	let lastSeen = [];

	while (true) {
		const builds = await listBuildsForCommit(client, options);
		lastSeen = builds;
		const passed = selectPassedBuild(builds, options.commit);
		if (passed) {
			return passed;
		}
		const terminal = builds.filter((build) => ['failed', 'canceled', 'skipped'].includes(build.state));
		const active = builds.filter((build) => ['scheduled', 'running', 'not_run', 'waiting', 'waiting_failed'].includes(build.state));
		if (terminal.length > 0 && active.length === 0) {
			const summary = terminal.map((build) => `#${build.number} ${build.state} ${build.web_url || ''}`.trim()).join(', ');
			throw new BuildkiteArtifactError(`No passed Buildkite build found for ${options.commit}; terminal builds: ${summary}`);
		}
		if (Date.now() >= deadline) {
			const summary = lastSeen.length > 0 ? lastSeen.map((build) => `#${build.number} ${build.state}`).join(', ') : 'none';
			throw new BuildkiteArtifactError(`Timed out waiting for a passed Buildkite build for ${options.commit}; seen builds: ${summary}`);
		}
		console.log(`Waiting for Buildkite build for ${options.commit}; seen ${builds.length || 0} build(s).`);
		await sleep(options.pollSeconds * 1000);
	}
}

export async function waitForBuildArtifact(client, options) {
	const deadline = Date.now() + options.waitSeconds * 1000;
	let lastSeen = [];

	while (true) {
		const builds = await listBuildsForCommit(client, options);
		lastSeen = builds;
		const matchingBuilds = builds
			.filter((build) => build.commit === options.commit)
			.sort((left, right) => right.number - left.number);

		for (const build of matchingBuilds) {
			const artifacts = await listArtifactsForBuild(client, {
				org: options.org,
				pipeline: options.pipeline,
				build,
			});
			const artifact = findFinishedArtifact(artifacts, options.artifactPath);
			if (artifact) {
				return { build, artifact };
			}
			if (artifacts.length > 0) {
				console.log(`Buildkite build #${build.number} artifacts: ${describeArtifacts(artifacts)}`);
			}
		}

		const terminal = matchingBuilds.filter((build) => ['passed', 'failed', 'canceled', 'skipped'].includes(build.state));
		const active = matchingBuilds.filter((build) => ['scheduled', 'running', 'not_run', 'waiting', 'waiting_failed'].includes(build.state));
		if (terminal.length > 0 && active.length === 0) {
			const summary = terminal.map((build) => `#${build.number} ${build.state} ${build.web_url || ''}`.trim()).join(', ');
			throw new BuildkiteArtifactError(`Buildkite artifact not found for ${options.commit}: ${options.artifactPath}; terminal builds: ${summary}`);
		}
		if (Date.now() >= deadline) {
			const summary = lastSeen.length > 0 ? lastSeen.map((build) => `#${build.number} ${build.state}`).join(', ') : 'none';
			throw new BuildkiteArtifactError(`Timed out waiting for Buildkite artifact ${options.artifactPath} for ${options.commit}; seen builds: ${summary}`);
		}
		console.log(`Waiting for Buildkite artifact ${options.artifactPath} for ${options.commit}; seen ${builds.length || 0} build(s).`);
		await sleep(options.pollSeconds * 1000);
	}
}

export function selectPassedBuild(builds, commit) {
	return builds
		.filter((build) => build.commit === commit && build.state === 'passed')
		.sort((left, right) => right.number - left.number)[0] || null;
}

export function selectArtifact(artifacts, artifactPath) {
	const matches = artifacts.filter((artifact) => artifactPathMatches(artifact.path, artifactPath) && artifact.state === 'finished');
	if (matches.length === 0) {
		const available = artifacts.map((artifact) => `${artifact.path} (${artifact.state})`).join(', ');
		throw new BuildkiteArtifactError(`Buildkite artifact not found: ${artifactPath}. Available artifacts: ${available || 'none'}`);
	}
	if (matches.length > 1) {
		throw new BuildkiteArtifactError(`Buildkite artifact matched more than once: ${artifactPath}`);
	}
	return matches[0];
}

export function findFinishedArtifact(artifacts, artifactPath) {
	const matches = artifacts.filter((artifact) => artifactPathMatches(artifact.path, artifactPath) && artifact.state === 'finished');
	if (matches.length > 1) {
		throw new BuildkiteArtifactError(`Buildkite artifact matched more than once: ${artifactPath}`);
	}
	return matches[0] || null;
}

export function artifactPathMatches(actualPath, expectedPath) {
	const actual = normalizeArtifactPath(actualPath);
	const expected = normalizeArtifactPath(expectedPath);
	return actual === expected || actual.endsWith(`/${expected}`);
}

export function normalizeArtifactPath(path) {
	return String(path).replaceAll('\\', '/').replace(/^\.\/+/, '').replace(/^\/+/, '');
}

export function describeArtifacts(artifacts) {
	return artifacts.map((artifact) => `${artifact.path} (${artifact.state})`).join(', ');
}

export async function listBuildsForCommit(client, options) {
	const params = new URLSearchParams({
		commit: options.commit,
		per_page: '20',
	});
	return client.getJson(`/organizations/${options.org}/pipelines/${options.pipeline}/builds?${params}`);
}

export async function listBuildArtifacts(client, { org, pipeline, buildNumber }) {
	return client.getJson(`/organizations/${org}/pipelines/${pipeline}/builds/${buildNumber}/artifacts?per_page=100`);
}

export async function listArtifactsForBuild(client, { org, pipeline, build }) {
	const artifacts = await listBuildArtifacts(client, {
		org,
		pipeline,
		buildNumber: build.number,
	});
	const seen = new Set(artifacts.map((artifact) => artifact.id || `${artifact.job_id}:${artifact.path}`));

	for (const job of build.jobs || []) {
		const jobArtifactsUrl = job.artifact_url || (job.id
			? `/organizations/${org}/pipelines/${pipeline}/builds/${build.number}/jobs/${job.id}/artifacts?per_page=100`
			: null);
		if (!jobArtifactsUrl) {
			continue;
		}
		const jobArtifacts = await client.getJson(jobArtifactsUrl);
		for (const artifact of jobArtifacts) {
			const key = artifact.id || `${artifact.job_id}:${artifact.path}`;
			if (!seen.has(key)) {
				seen.add(key);
				artifacts.push(artifact);
			}
		}
	}

	return artifacts;
}

export async function downloadArtifact(client, artifact, output) {
	const location = await client.getRedirectLocation(artifact.download_url);
	const response = await fetch(location);
	if (!response.ok) {
		throw new BuildkiteArtifactError(`Failed to download Buildkite artifact from signed URL: ${response.status} ${response.statusText}`);
	}
	const bytes = Buffer.from(await response.arrayBuffer());
	if (artifact.sha1sum) {
		const sha1sum = createHash('sha1').update(bytes).digest('hex');
		if (sha1sum !== artifact.sha1sum) {
			throw new BuildkiteArtifactError(`Buildkite artifact SHA-1 mismatch for ${artifact.path}: expected ${artifact.sha1sum}, got ${sha1sum}`);
		}
	}
	mkdirSync(dirname(resolve(output)), { recursive: true });
	writeFileSync(output, bytes);
}

export function createBuildkiteClient({
	token,
	apiBase,
	fetchImpl = fetch,
	retries = DEFAULT_API_RETRIES,
	retryDelayMs = DEFAULT_API_RETRY_DELAY_MS,
	sleepImpl = sleep,
}) {
	const headers = {
		Authorization: `Bearer ${token}`,
		Accept: 'application/json',
	};
	return {
		async getJson(path) {
			const url = path.startsWith('http') ? path : `${apiBase}${path}`;
			for (let attempt = 0; attempt <= retries; attempt += 1) {
				let response;
				try {
					response = await fetchImpl(url, { headers });
				} catch (error) {
					if (attempt < retries) {
						await waitBeforeBuildkiteRetry({
							action: `Buildkite API request failed: ${formatBuildkiteFetchError(error)} ${url}`,
							attempt,
							retries,
							retryDelayMs,
							sleepImpl,
						});
						continue;
					}
					throw new BuildkiteArtifactError(`Buildkite API request failed: ${formatBuildkiteFetchError(error)} ${url}`);
				}
				if (response.ok) {
					return response.json();
				}
				if (response.status === 403 && path.includes('/artifacts')) {
					throw new BuildkiteArtifactError(`Buildkite artifact API request failed: 403 Forbidden ${url}. Ensure BUILDKITE_API_TOKEN has the read_artifacts REST API scope.`);
				}
				if (attempt < retries && isTransientBuildkiteStatus(response.status)) {
					await waitBeforeBuildkiteRetry({
						action: `Buildkite API request failed: ${response.status} ${response.statusText} ${url}`,
						attempt,
						retries,
						retryDelayMs,
						sleepImpl,
					});
					continue;
				}
				throw new BuildkiteArtifactError(`Buildkite API request failed: ${response.status} ${response.statusText} ${url}`);
			}
		},
		async getRedirectLocation(url) {
			for (let attempt = 0; attempt <= retries; attempt += 1) {
				let response;
				try {
					response = await fetchImpl(url, { headers, redirect: 'manual' });
				} catch (error) {
					if (attempt < retries) {
						await waitBeforeBuildkiteRetry({
							action: `Buildkite artifact download request failed: ${formatBuildkiteFetchError(error)} ${url}`,
							attempt,
							retries,
							retryDelayMs,
							sleepImpl,
						});
						continue;
					}
					throw new BuildkiteArtifactError(`Buildkite artifact download request failed: ${formatBuildkiteFetchError(error)} ${url}`);
				}
				if (response.status === 302 || response.status === 303) {
					const location = response.headers.get('location');
					if (!location) {
						throw new BuildkiteArtifactError(`Buildkite artifact download did not return a Location header for ${url}`);
					}
					return location;
				}
				if (response.ok) {
					const body = await response.json().catch(() => null);
					if (body?.url) {
						return body.url;
					}
				}
				if (response.status === 403) {
					throw new BuildkiteArtifactError(`Buildkite artifact download request failed: 403 Forbidden ${url}. Ensure BUILDKITE_API_TOKEN has the read_artifacts REST API scope.`);
				}
				if (attempt < retries && isTransientBuildkiteStatus(response.status)) {
					await waitBeforeBuildkiteRetry({
						action: `Buildkite artifact download request failed: ${response.status} ${response.statusText} ${url}`,
						attempt,
						retries,
						retryDelayMs,
						sleepImpl,
					});
					continue;
				}
				throw new BuildkiteArtifactError(`Buildkite artifact download request failed: ${response.status} ${response.statusText} ${url}`);
			}
		},
	};
}

export function isTransientBuildkiteStatus(status) {
	return status === 429 || status === 500 || status === 502 || status === 503 || status === 504;
}

function formatBuildkiteFetchError(error) {
	const message = error?.message || String(error);
	return error?.cause?.code ? `${message} (${error.cause.code})` : message;
}

async function waitBeforeBuildkiteRetry({ action, attempt, retries, retryDelayMs, sleepImpl }) {
	const attemptNumber = attempt + 1;
	console.warn(`${action}; retrying ${attemptNumber}/${retries} in ${retryDelayMs}ms.`);
	await sleepImpl(retryDelayMs);
}

function readValue(argv, index, option) {
	const value = argv[index + 1];
	if (!value || value.startsWith('-')) {
		throw new BuildkiteArtifactError(`${option} requires a value.`);
	}
	return value;
}

function parseNonNegativeInteger(value, option) {
	const parsed = Number(value);
	if (!Number.isInteger(parsed) || parsed < 0) {
		throw new BuildkiteArtifactError(`${option} must be a non-negative integer.`);
	}
	return parsed;
}

function sleep(milliseconds) {
	return new Promise((resolveSleep) => setTimeout(resolveSleep, milliseconds));
}

function printUsage() {
	console.log(`Usage: node scripts/release-download-buildkite-artifact.mjs --commit <sha> --artifact-path <path> --output <path>

Options:
  --commit <sha>          Exact Git commit SHA to find in Buildkite.
  --artifact-path <path>  Exact Buildkite artifact path to download.
  --output <path>         Local destination path.
  --org <slug>            Buildkite organization slug. Defaults to automattic.
  --pipeline <slug>       Buildkite pipeline slug. Defaults to forkpress.
  --wait-seconds <n>      Seconds to wait for a passed build. Defaults to 2700.
  --poll-seconds <n>      Seconds between polling attempts. Defaults to 30.
`);
}
