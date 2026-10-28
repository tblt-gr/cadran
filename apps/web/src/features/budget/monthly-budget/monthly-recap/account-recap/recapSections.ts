import type {
  MonthlyRecapAccount,
  MonthlyRecapGroup,
  NetWorthAmount,
  NetWorthShare,
} from '@cadran/api-client';
import { groupLabelParts, type GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';

const MAX_DEPTH = 8;

export interface RecapMember {
  account: MonthlyRecapAccount;
  /** Sub-group caption in parent mode, null when the account sits directly on the section root. */
  subGroup: { label: string; parentLabel: string | null } | null;
}

export interface RecapSection {
  groupId: string;
  label: string;
  /** Immediate parent's name, shown in parentheses; null for a root. */
  parentLabel: string | null;
  color: string;
  previous: NetWorthAmount | null;
  current: NetWorthAmount | null;
  share: NetWorthShare;
  members: RecapMember[];
}

export interface RecapSections {
  sections: RecapSection[];
  ungrouped: MonthlyRecapAccount[];
}

/**
 * Builds the panel sections from the server's groups, in their saved depth-first order. Amounts are
 * the backend's direct totals (sub-group mode) or roll-ups (parent mode); nothing is summed here.
 */
export function recapSections(
  groups: readonly MonthlyRecapGroup[],
  accounts: readonly MonthlyRecapAccount[],
  mode: GroupingMode,
): RecapSections {
  const groupsById = new Map(groups.map((group) => [group.groupId, group]));
  const direct = new Map<string, MonthlyRecapAccount[]>();
  const ungrouped: MonthlyRecapAccount[] = [];

  for (const account of accounts) {
    if (account.primaryGroupId === null || !groupsById.has(account.primaryGroupId)) {
      ungrouped.push(account);
      continue;
    }
    direct.set(account.primaryGroupId, [...(direct.get(account.primaryGroupId) ?? []), account]);
  }

  const rootOf = (groupId: string): string => {
    let current = groupsById.get(groupId);
    for (let step = 0; step < MAX_DEPTH && current?.parentId != null; step += 1) {
      const parent = groupsById.get(current.parentId);
      if (parent === undefined) break;
      current = parent;
    }
    return current?.groupId ?? groupId;
  };

  if (mode === 'PRIMARY') {
    const sections = groups.flatMap((group): RecapSection[] => {
      const members = direct.get(group.groupId) ?? [];
      if (members.length === 0) return [];
      const parts = groupLabelParts(group.groupId, groupsById);
      return [
        {
          groupId: group.groupId,
          label: parts.label,
          parentLabel: parts.parentLabel,
          color: group.color,
          previous: group.directPreviousValue,
          current: group.directValue,
          share: group.directShare,
          members: members.map((account) => ({ account, subGroup: null })),
        },
      ];
    });
    return { sections, ungrouped };
  }

  const bySection = new Map<string, RecapMember[]>();
  for (const group of groups) {
    const root = rootOf(group.groupId);
    const parts = groupLabelParts(group.groupId, groupsById);
    const subGroup = root === group.groupId ? null : parts;
    const members = (direct.get(group.groupId) ?? []).map((account) => ({ account, subGroup }));
    if (members.length > 0) bySection.set(root, [...(bySection.get(root) ?? []), ...members]);
  }

  const sections = groups.flatMap((group): RecapSection[] => {
    const members = bySection.get(group.groupId);
    if (members === undefined || rootOf(group.groupId) !== group.groupId) return [];
    return [
      {
        groupId: group.groupId,
        label: group.label,
        parentLabel: null,
        color: group.color,
        previous: group.previousValue,
        current: group.value,
        share: group.share,
        members,
      },
    ];
  });
  return { sections, ungrouped };
}
