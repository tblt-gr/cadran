import { useQueryClient } from '@tanstack/react-query';

/**
 * Invalidates every view a rule write can affect: the rule list itself, and
 * every transaction list a resolved or reverted categorization may have
 * changed.
 */
export function useRefreshCategorizationData() {
  const queryClient = useQueryClient();
  return async function refresh() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['categorization-rules'] }),
      queryClient.invalidateQueries({ queryKey: ['transactions'] }),
    ]);
  };
}
