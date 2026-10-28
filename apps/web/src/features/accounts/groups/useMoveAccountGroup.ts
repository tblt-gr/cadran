import { moveAccountGroup, type AccountGroup, type AccountGroupMove } from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { groupRequestError } from '@/features/accounts/groups/groupError';

export interface GroupMove {
  group: AccountGroup;
  beforeGroupId: string | null;
  /** The active sibling order the tree displayed, moved group included. */
  expectedSiblingIds: string[];
}

/** Every list that orders groups: the tree, the overview, the allocation, the recap and the report columns. */
const ORDERED_QUERIES = [
  'account-groups',
  'accounts',
  'net-worth',
  'monthly-recap',
  'annual-report-catalogue',
];

/**
 * Saves one reorder step. Nothing is optimistic: the tree keeps the last saved order until the
 * refetch after the write, so a refused move leaves it intact.
 */
export function useMoveAccountGroup(
  onMoved: (group: AccountGroup, move: AccountGroupMove) => void,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ group, beforeGroupId, expectedSiblingIds }: GroupMove) => {
      const result = await withCsrfRetry(() =>
        moveAccountGroup({
          ...authApiOptions(),
          path: { id: group.id },
          body: { beforeGroupId, expectedSiblingIds },
        }),
      );
      if (!result.response?.ok || !result.data) {
        throw groupRequestError(result);
      }
      return result.data;
    },
    onSuccess: async (moved, variables) => {
      await Promise.all(
        ORDERED_QUERIES.map((key) => queryClient.invalidateQueries({ queryKey: [key] })),
      );
      onMoved(variables.group, moved);
    },
  });
}
