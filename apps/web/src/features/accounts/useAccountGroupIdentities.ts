import { useQuery } from '@tanstack/react-query';
import { listAllAccountGroups } from '@/features/accounts/groups/listAllAccountGroups';

/** Identity metadata includes archived groups and is independent of valuation eligibility. */
export function useAccountGroupIdentities(enabled = true) {
  return useQuery({
    enabled,
    queryKey: ['account-groups', 'identities'],
    queryFn: async ({ signal }) =>
      new Map((await listAllAccountGroups(true, signal)).map((group) => [group.id, group])),
    retry: false,
  });
}
