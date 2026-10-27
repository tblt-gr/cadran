export const GROUP_PATH_SEPARATOR = ' › ';

interface OrderableGroup {
  id: string;
  label: string;
  parentId: string | null;
  sortOrder: number;
}

export interface OrderedGroup<T extends OrderableGroup> {
  group: T;
  /** 1 for a root group. */
  depth: number;
  /** Labels of the ancestors and of the group itself, joined by the path separator. */
  path: string;
}

function savedOrder(left: OrderableGroup, right: OrderableGroup): number {
  return (
    left.sortOrder - right.sortOrder ||
    left.label.toLowerCase().localeCompare(right.label.toLowerCase()) ||
    left.id.localeCompare(right.id)
  );
}

/**
 * Depth-first order of the group tree, siblings by (sortOrder, lower(label), id) as the API saves
 * them. A group whose parent is not in the list is treated as a root.
 */
export function orderGroupsDepthFirst<T extends OrderableGroup>(groups: T[]): OrderedGroup<T>[] {
  const ids = new Set(groups.map((group) => group.id));
  const children = new Map<string | null, T[]>();
  for (const group of groups) {
    const key = group.parentId !== null && ids.has(group.parentId) ? group.parentId : null;
    children.set(key, [...(children.get(key) ?? []), group]);
  }

  const ordered: OrderedGroup<T>[] = [];
  const visit = (parentKey: string | null, depth: number, parentPath: string | null) => {
    for (const group of [...(children.get(parentKey) ?? [])].sort(savedOrder)) {
      const path =
        parentPath === null ? group.label : `${parentPath}${GROUP_PATH_SEPARATOR}${group.label}`;
      ordered.push({ group, depth, path });
      visit(group.id, depth + 1, path);
    }
  };
  visit(null, 1, null);

  return ordered;
}
