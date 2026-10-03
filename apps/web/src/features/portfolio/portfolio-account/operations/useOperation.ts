import { readPortfolioOperation, type PortfolioOperation } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

/** One operation by id; `initial` shows the already-listed row while the read refreshes it. */
export function useOperation(id: string | null, initial?: PortfolioOperation) {
  return useQuery({
    queryKey: ['portfolio-operation', id],
    enabled: id !== null,
    initialData: initial,
    queryFn: async ({ signal }) => {
      const result = await readPortfolioOperation({
        ...authApiOptions(),
        path: { id: id! },
        signal,
      });
      if (!result.response?.ok || !result.data) throw new Error('Operation read failed');
      return result.data;
    },
    retry: false,
  });
}
