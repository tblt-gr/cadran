import type { Category } from '@cadran/api-client';

export interface CategoryTreeNodeData {
  category: Category;
  children: CategoryTreeNodeData[];
}

/**
 * Presentational grouping by `parentId`. A category whose parent is not in the list (archived
 * parent while archived categories are hidden) is shown as a root. Categories that only reference
 * each other cannot be reached from a root and are dropped, which bounds the recursion.
 */
export function buildCategoryTree(categories: Category[]): CategoryTreeNodeData[] {
  const nodes = new Map<string, CategoryTreeNodeData>(
    categories.map((category) => [category.id, { category, children: [] }]),
  );
  const roots: CategoryTreeNodeData[] = [];

  for (const node of nodes.values()) {
    const parent = node.category.parentId === null ? undefined : nodes.get(node.category.parentId);
    (parent ? parent.children : roots).push(node);
  }

  return roots;
}
