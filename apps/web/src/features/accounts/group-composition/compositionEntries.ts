import type { AccountGroupComposition, NetWorthAmount, NetWorthShare } from '@cadran/api-client';

/** One slice and table row, whichever view it comes from. */
export interface CompositionEntry {
  color: string | null;
  institution: string | null;
  key: string;
  label: string;
  share: NetWorthShare;
  value: NetWorthAmount | null;
}

export type CompositionView = 'subgroup' | 'account';

export function subgroupEntries(
  composition: AccountGroupComposition,
  directLabel: string,
): CompositionEntry[] {
  return composition.bySubgroup.map((entry) => ({
    color: entry.color,
    institution: null,
    key: `${entry.kind}:${entry.id}`,
    label: entry.kind === 'DIRECT' ? directLabel : entry.label,
    share: entry.share,
    value: entry.value,
  }));
}

export function accountEntries(composition: AccountGroupComposition): CompositionEntry[] {
  return composition.byAccount.map((entry) => ({
    color: entry.color,
    institution: entry.institution,
    key: entry.id,
    label: entry.label,
    share: entry.share,
    value: entry.value,
  }));
}
