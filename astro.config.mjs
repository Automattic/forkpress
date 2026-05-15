import starlight from '@astrojs/starlight';
import { defineConfig } from 'astro/config';
import starlightLlmsTxt from 'starlight-llms-txt';

import rewriteDocLinks from './src/remark-rewrite-doc-links.mjs';
import removePageTitleHeading from './src/remark-remove-page-title-heading.mjs';

const site = process.env.DOCS_SITE ?? 'https://automattic.github.io';
const base = process.env.DOCS_BASE ?? '/forkpress';

export default defineConfig({
	site,
	base,
	output: 'static',
	outDir: 'docs-dist',
	trailingSlash: 'always',
	markdown: {
		remarkPlugins: [rewriteDocLinks, removePageTitleHeading],
	},
	integrations: [
		starlight({
			title: 'ForkPress',
			description: 'Static binary WordPress branch previews with copy-on-write storage.',
			editLink: {
				baseUrl: 'https://github.com/Automattic/forkpress/edit/trunk/',
			},
			pagefind: true,
			social: [
				{
					icon: 'github',
					label: 'GitHub',
					href: 'https://github.com/Automattic/forkpress',
				},
			],
			sidebar: [
				{ label: 'Overview', slug: 'index' },
				{
					label: 'Core workflows',
					items: [
						{ label: 'Branching', slug: 'docs/branching' },
						{ label: 'Merging', slug: 'docs/merging' },
						{ label: 'Git workflow', slug: 'docs/git-workflow' },
						{ label: 'Agents', slug: 'docs/agents' },
					],
				},
				{
					label: 'Storage',
					items: [
						{ label: 'Overview', slug: 'docs/storage/overview' },
						{ label: 'macOS', slug: 'docs/storage/macos' },
						{ label: 'Linux', slug: 'docs/storage/linux' },
						{ label: 'Windows', slug: 'docs/storage/windows' },
						{ label: 'Engines', slug: 'docs/storage/engines' },
					],
				},
				{
					label: 'Reference',
					items: [
						{ label: 'Commands', slug: 'docs/commands' },
						{ label: 'Logs', slug: 'docs/logs' },
					],
				},
				{
					label: 'Project',
					items: [
						{ label: 'Architecture', slug: 'docs/architecture' },
						{ label: 'Development', slug: 'docs/development' },
						{ label: 'Documentation site', slug: 'docs/documentation-site' },
					],
				},
				{ label: 'Experiments', slug: 'docs/experiments' },
			],
			plugins: [
				starlightLlmsTxt({
					projectName: 'ForkPress',
					description:
						'ForkPress ships static binaries for local WordPress branch previews backed by copy-on-write storage and Git worktrees.',
				}),
			],
		}),
	],
});
