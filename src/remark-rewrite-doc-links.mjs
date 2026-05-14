import { existsSync } from 'node:fs';
import path from 'node:path';

const markdownFileExtension = /\.(md|mdx)$/i;
const urlScheme = /^[a-zA-Z][a-zA-Z\d+.-]*:/;

export default function rewriteDocLinks(options = {}) {
	const projectRoot = path.resolve(options.projectRoot ?? process.cwd());

	return (tree, file) => {
		const sourceEntry = entryPathForFile(file, projectRoot);

		if (!sourceEntry) {
			return;
		}

		visitLinkNodes(tree, (node) => {
			node.url = rewriteDocLink(node.url, sourceEntry, projectRoot);
		});
	};
}

export function rewriteDocLink(url, sourceEntry, projectRoot) {
	if (!shouldRewriteUrl(url)) {
		return url;
	}

	const parts = splitUrl(url);

	if (!markdownFileExtension.test(parts.pathname)) {
		return url;
	}

	const sourceDirectory = path.posix.dirname(sourceEntry);
	const targetEntry = path.posix.normalize(
		path.posix.join(sourceDirectory, safeDecodeUri(parts.pathname)),
	);

	if (!isProjectRelativePath(targetEntry)) {
		return url;
	}

	if (!existsSync(path.resolve(projectRoot, targetEntry))) {
		return url;
	}

	return `${relativeRouteUrl(routeIdForEntry(sourceEntry), routeIdForEntry(targetEntry))}${parts.query}${parts.hash}`;
}

export function routeIdForEntry(entry) {
	const normalized = entry.replace(/\\/g, '/').replace(/\.(md|mdx)$/i, '');

	if (normalized === 'README') {
		return 'index';
	}

	if (normalized.endsWith('/README')) {
		return normalized.slice(0, -'/README'.length);
	}

	return normalized;
}

function entryPathForFile(file, projectRoot) {
	const filePath = file?.path ?? file?.history?.[0];

	if (typeof filePath !== 'string' || filePath === '') {
		return;
	}

	const relativePath = path.relative(projectRoot, path.resolve(filePath)).replace(/\\/g, '/');

	if (!isProjectRelativePath(relativePath)) {
		return;
	}

	return relativePath;
}

function visitLinkNodes(node, callback) {
	if (!node || typeof node !== 'object') {
		return;
	}

	if ((node.type === 'link' || node.type === 'definition') && typeof node.url === 'string') {
		callback(node);
	}

	if (!Array.isArray(node.children)) {
		return;
	}

	for (const child of node.children) {
		visitLinkNodes(child, callback);
	}
}

function shouldRewriteUrl(url) {
	return (
		typeof url === 'string' &&
		url !== '' &&
		!url.startsWith('#') &&
		!url.startsWith('/') &&
		!url.startsWith('//') &&
		!urlScheme.test(url)
	);
}

function splitUrl(url) {
	const hashIndex = url.indexOf('#');
	const beforeHash = hashIndex === -1 ? url : url.slice(0, hashIndex);
	const hash = hashIndex === -1 ? '' : url.slice(hashIndex);
	const queryIndex = beforeHash.indexOf('?');

	return {
		pathname: queryIndex === -1 ? beforeHash : beforeHash.slice(0, queryIndex),
		query: queryIndex === -1 ? '' : beforeHash.slice(queryIndex),
		hash,
	};
}

function safeDecodeUri(uri) {
	try {
		return decodeURI(uri);
	} catch {
		return uri;
	}
}

function isProjectRelativePath(filePath) {
	return filePath !== '' && filePath !== '..' && !filePath.startsWith('../') && !path.isAbsolute(filePath);
}

function relativeRouteUrl(sourceRouteId, targetRouteId) {
	const relativePath = path.posix.relative(routePath(sourceRouteId), routePath(targetRouteId));

	return `${relativePath === '' ? '.' : relativePath}/`;
}

function routePath(routeId) {
	return routeId === 'index' ? '' : routeId;
}
