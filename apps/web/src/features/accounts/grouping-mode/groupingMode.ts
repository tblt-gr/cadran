/** PARENT: one slice per root group (roll-up). PRIMARY: one slice per group with direct members. */
export type GroupingMode = 'PARENT' | 'PRIMARY';

export const GROUPING_MODES: readonly GroupingMode[] = ['PARENT', 'PRIMARY'];

const GROUP_PATH_SEPARATOR = ' › ';

interface GroupNode {
  label: string;
  parentId: string | null;
}

/** The group's own name and its immediate parent's name, null for a root or an unknown parent. */
export function groupLabelParts(
  groupId: string,
  groupsById: ReadonlyMap<string, GroupNode>,
): { label: string; parentLabel: string | null } {
  const group = groupsById.get(groupId);
  const parent = group?.parentId == null ? undefined : groupsById.get(group.parentId);

  return { label: group?.label ?? '', parentLabel: parent?.label ?? null };
}

/** Ancestors and the group itself, root first. Cycle-safe: a repeated id stops the walk. */
export function groupPath(groupId: string, groupsById: ReadonlyMap<string, GroupNode>): string {
  const labels: string[] = [];
  const seen = new Set<string>();
  let current: string | null = groupId;
  while (current !== null && !seen.has(current)) {
    seen.add(current);
    const group = groupsById.get(current);
    if (group === undefined) {
      break;
    }
    labels.unshift(group.label);
    current = group.parentId;
  }

  return labels.join(GROUP_PATH_SEPARATOR);
}
