import { execFileSync } from 'node:child_process';
import { test } from 'node:test';

test('popular plugin compatibility manifest is valid', () => {
	execFileSync('node', ['scripts/popular-plugin-compat.mjs', 'validate'], {
		stdio: 'pipe',
	});
});
