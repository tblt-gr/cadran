/** PARENT: one slice per root group (roll-up). PRIMARY: one slice per group with direct members. */
export type GroupingMode = 'PARENT' | 'PRIMARY';

export const GROUPING_MODES: readonly GroupingMode[] = ['PARENT', 'PRIMARY'];

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
