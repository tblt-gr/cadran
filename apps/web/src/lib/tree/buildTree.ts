export interface TreeNode<T extends { id: string; parentId: string | null }> {
  item: T;
  children: TreeNode<T>[];
}

/**
 * Presentational grouping by `parentId`. An item whose parent is not in the list (archived parent
 * while archived items are hidden) is shown as a root. Items that only reference each other cannot
 * be reached from a root and are dropped, which bounds the recursion.
 */
export function buildTree<T extends { id: string; parentId: string | null }>(
  items: T[],
): TreeNode<T>[] {
  const nodes = new Map<string, TreeNode<T>>(
    items.map((item) => [item.id, { item, children: [] }]),
  );
  const roots: TreeNode<T>[] = [];

  for (const node of nodes.values()) {
    const parent = node.item.parentId === null ? undefined : nodes.get(node.item.parentId);
    (parent ? parent.children : roots).push(node);
  }

  return roots;
}
