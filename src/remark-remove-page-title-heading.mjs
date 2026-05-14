export default function removePageTitleHeading() {
	return (tree) => {
		const firstContentIndex = tree.children.findIndex((node) => node.type !== 'yaml');
		const firstContent = tree.children[firstContentIndex];

		if (firstContent?.type === 'heading' && firstContent.depth === 1) {
			tree.children.splice(firstContentIndex, 1);
		}
	};
}
