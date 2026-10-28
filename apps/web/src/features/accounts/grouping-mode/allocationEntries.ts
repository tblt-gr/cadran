import { groupLabelParts, type GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';
import { isZeroDecimal } from '@/lib/decimal';

/** The group figures both the Synthèse and the annual report carry, in their own amount and share types. */
export interface AllocationSource<A, S> {
  color: string | null;
  depth: number;
  directShare: S;
  directValue: A | null;
  groupId: string;
  label: string;
  parentId: string | null;
  share: S;
  value: A | null;
}

/** One slice or legend row, in the mode's own value and share. */
export interface AllocationItem<A, S> {
  color: string | null;
  groupId: string;
  /** The group's own name. */
  label: string;
  /** Immediate parent's name, null for a root group. */
  parentLabel: string | null;
  /** Label as displayed, `Name (Parent)`: the tooltip and accessible name repeat it. */
  path: string;
  share: S;
  value: A | null;
}

/**
 * PARENT: root groups at their exclusive roll-up. PRIMARY: every group with a non-zero direct
 * total, at that direct total. When no direct total is calculable at all, every group is kept
 * unvalued so the reason stays visible. Values and shares are the backend's; nothing is summed here.
 */
export function allocationEntries<A, S>(
  allocation: readonly AllocationSource<A, S>[],
  mode: GroupingMode,
  decimalOf: (amount: A) => string,
): AllocationItem<A, S>[] {
  const notCalculable = allocation.every((entry) => entry.directValue === null);
  const groupsById = new Map(allocation.map((entry) => [entry.groupId, entry]));

  return allocation.flatMap((entry): AllocationItem<A, S>[] => {
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

    if (entry.directValue === null || isZeroDecimal(decimalOf(entry.directValue))) {
      return [];
    }

    return [{ ...base, share: entry.directShare, value: entry.directValue }];
  });
}
