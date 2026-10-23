import { readMonthlyBounds } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

const MONTHLY_BOUNDS_KEY = 'monthly-bounds';

/** First month with eligible workspace data and the workspace's current month. */
export function useMonthlyBounds() {
  return useQuery({
    queryKey: [MONTHLY_BOUNDS_KEY],
    queryFn: async ({ signal }) => {
      const result = await readMonthlyBounds({ ...authApiOptions(), signal });
      if (!result.response?.ok || !result.data) throw new Error(MONTHLY_BOUNDS_KEY);
      return result.data;
    },
    retry: false,
  });
}
