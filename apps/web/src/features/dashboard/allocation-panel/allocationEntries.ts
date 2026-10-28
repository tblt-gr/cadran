import type { NetWorthAllocationEntry, NetWorthAmount, NetWorthShare } from '@cadran/api-client';
import { groupLabelParts, type GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';
import { isZeroDecimal } from '@/lib/decimal';

/** One slice or legend row, in the mode's own value and share. */
export interface AllocationItem {
  color: string;
  groupId: string;
  /** The group's own name. */
  label: string;
  /** Immediate parent's name, null for a root group. */
  parentLabel: string | null;
  /** Label as displayed, `Name (Parent)`: the tooltip and accessible name repeat it. */
  path: string;
  share: NetWorthShare;
  value: NetWorthAmount | null;
}

/**
 * PARENT: root groups at their exclusive roll-up. PRIMARY: every group with a non-zero direct
 * total, at that direct total. When no direct total is calculable at all, every group is kept
 * unvalued so the reason stays visible. Values and shares are the backend's; nothing is summed here.
 */
export function allocationEntries(
  allocation: readonly NetWorthAllocationEntry[],
  mode: GroupingMode,
): AllocationItem[] {
  const notCalculable = allocation.every((entry) => entry.directValue === null);
  const groupsById = new Map(allocation.map((entry) => [entry.groupId, entry]));

  return allocation.flatMap((entry): AllocationItem[] => {
    const parts = groupLabelParts(entry.groupId, groupsById);
    const base = {
      color: entry.color,
      groupId: entry.groupId,
      label: parts.label,
      parentLabel: parts.parentLabel,
      path: parts.parentLabel === null ? parts.label : `${parts.label} (${parts.parentLabel})`,
    };

    if (mode === 'PARENT') {
      return entry.depth === 1 ? [{ ...base, share: entry.share, value: entry.value }] : [];
    }

    if (notCalculable) {
      // Nothing is valued that day: keep every group so the legend can state the reason.
      return [{ ...base, share: entry.directShare, value: null }];
    }

    if (entry.directValue === null || isZeroDecimal(entry.directValue.value)) {
      return [];
    }

    return [{ ...base, share: entry.directShare, value: entry.directValue }];
  });
}
