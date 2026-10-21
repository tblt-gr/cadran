import { useAccountGroupIdentities } from '@/features/accounts/useAccountGroupIdentities';

export interface AccountGroupLabelQuery {
  label: string | null;
  color: string | null;
  isPending: boolean;
  isError: boolean;
}

/** Resolve every primary group, including archived groups and later metadata pages. */
export function useAccountGroupLabel(groupId: string | null): AccountGroupLabelQuery {
  const groups = useAccountGroupIdentities(groupId !== null);
  if (groupId === null) {
    return { label: null, color: null, isPending: false, isError: false };
  }
  const group = groups.data?.get(groupId);
  return {
    color: group?.resolvedColor ?? null,
    label: group?.label ?? null,
    isPending: groups.isPending,
    isError: groups.isError,
  };
}
