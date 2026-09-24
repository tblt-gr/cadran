import { listAccountGroups } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

const PAGE_SIZE = 100;

export interface AccountGroupLabelQuery {
  label: string | null;
  isPending: boolean;
  isError: boolean;
}

/**
 * The label of one account's primary group.
 *
 * No single-group read endpoint exists, and the group tree is small enough
 * that one page holds every group a workspace ever created. Archived groups
 * are included: a primary group that was later archived must still resolve
 * to its own label rather than reading as an unrelated absence.
 */
export function useAccountGroupLabel(groupId: string | null): AccountGroupLabelQuery {
  const groups = useQuery({
    enabled: groupId !== null,
    queryKey: ['account-groups', 'account-detail'],
    queryFn: async ({ signal }) => {
      const result = await listAccountGroups({
        ...authApiOptions(),
        query: { includeArchived: true, page: 1, perPage: PAGE_SIZE },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw new Error('Unable to load the account groups.');
      }
      return result.data;
    },
    retry: false,
  });

  if (groupId === null) {
    return { label: null, isPending: false, isError: false };
  }

  return {
    label: groups.data?.items.find((group) => group.id === groupId)?.label ?? null,
    isPending: groups.isPending,
    isError: groups.isError,
  };
}
