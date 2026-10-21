import { listAccountGroups, type AccountGroup } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

const PAGE_SIZE = 100;

/** Identity metadata includes archived groups and is independent of valuation eligibility. */
export function useAccountGroupIdentities(enabled = true) {
  return useQuery({
    enabled,
    queryKey: ['account-groups', 'identities'],
    queryFn: async ({ signal }) => {
      const items: AccountGroup[] = [];
      let totalPages = 1;
      for (let page = 1; page <= totalPages; page += 1) {
        const result = await listAccountGroups({
          ...authApiOptions(),
          query: { includeArchived: true, page, pageSize: PAGE_SIZE },
          signal,
        });
        if (!result.response?.ok || !result.data) {
          throw new Error('Unable to load account group identities.');
        }
        if (page === 1) {
          totalPages = Math.ceil(result.data.total / result.data.pageSize);
        }
        items.push(...result.data.items);
      }
      return new Map(items.map((group) => [group.id, group]));
    },
    retry: false,
  });
}
