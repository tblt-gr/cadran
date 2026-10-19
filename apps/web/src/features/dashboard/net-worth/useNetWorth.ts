import { readNetWorth, readNetWorthHistory } from '@cadran/api-client';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { netWorthRequestError } from './netWorthError';

import type { NetWorthPeriod } from '@/features/dashboard/net-worth-period/useNetWorthPeriod';

/**
 * The aggregate is workspace-wide and reads every account, so callers that
 * only need it on the dashboard pass `enabled: false` elsewhere rather than
 * paying for it on every route.
 */
export function useNetWorth(enabled = true) {
  return useQuery({
    enabled,
    queryKey: ['net-worth'],
    queryFn: async ({ signal }) => {
      const result = await readNetWorth({ ...authApiOptions(), signal });
      if (!result.response?.ok || !result.data) {
        throw netWorthRequestError(result);
      }

      return result.data;
    },
    retry: false,
  });
}

export function useNetWorthHistory(period: NetWorthPeriod) {
  return useQuery({
    queryKey: ['net-worth-history', period],
    placeholderData: keepPreviousData,
    queryFn: async ({ signal }) => {
      const result = await readNetWorthHistory({
        ...authApiOptions(),
        query: { period },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw netWorthRequestError(result);
      }

      return result.data;
    },
    retry: false,
  });
}
