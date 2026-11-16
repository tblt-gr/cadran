import type { NetWorthAmount, NetWorthShare } from '@cadran/api-client';
import { groupColor } from '@/features/accounts/group-color';
import type { AllocationItem } from '@/features/accounts/grouping-mode/allocationEntries';
import { allocationSize } from './allocationSize';

export interface AllocationSlice {
  [key: string]: unknown;
  fill: string;
  groupId: string;
  label: string;
  name: string;
  parentLabel: string | null;
  path: string;
  percentDisplay: string | null;
  size: number;
  value: NetWorthAmount | null;
}

export function sliceFromEntry(
  entry: AllocationItem<NetWorthAmount, NetWorthShare>,
): AllocationSlice | null {
  if (entry.share.percent === null) {
    return null;
  }

  const size = allocationSize(entry.share.percent);
  if (size === null) {
    return null;
  }

  return {
    fill: groupColor(entry.color),
    groupId: entry.groupId,
    label: entry.label,
    name: entry.label,
    parentLabel: entry.parentLabel,
    path: entry.path,
    percentDisplay: entry.share.percentDisplay,
    size,
    value: entry.value,
  };
}

export function isSlice(value: unknown): value is AllocationSlice {
  return (
    typeof value === 'object' &&
    value !== null &&
    'percentDisplay' in value &&
    'label' in value &&
    'value' in value
  );
}
