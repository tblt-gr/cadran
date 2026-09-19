import {
  closePeriod,
  readPeriodStatus,
  reopenPeriod,
  type ClosePeriodRequest,
  type PeriodStatus,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { periodClosureRequestError } from './periodClosureError';

const statusKey = (period: string) => ['period-status', period] as const;

/** The month's server-computed state and the two writes that change it. */
export function usePeriodClosure(period: string, onDone: () => void) {
  const queryClient = useQueryClient();

  const status = useQuery<PeriodStatus>({
    queryKey: statusKey(period),
    queryFn: async ({ signal }) => {
      const result = await readPeriodStatus({ ...authApiOptions(), path: { period }, signal });
      if (!result.response?.ok || !result.data) throw periodClosureRequestError(result);
      return result.data;
    },
    retry: false,
  });

  async function refresh() {
    await queryClient.invalidateQueries({ queryKey: statusKey(period) });
    await queryClient.invalidateQueries({ queryKey: ['period-closures'] });
  }

  const close = useMutation({
    mutationFn: async (body: ClosePeriodRequest) => {
      const result = await withCsrfRetry(() =>
        closePeriod({ ...authApiOptions(), path: { period }, body }),
      );
      if (!result.response?.ok || !result.data) throw periodClosureRequestError(result);
      return result.data;
    },
    onError: refresh,
    onSuccess: async () => {
      await refresh();
      onDone();
    },
  });

  const reopen = useMutation({
    mutationFn: async (body: { reason: string; version: number }) => {
      const result = await withCsrfRetry(() =>
        reopenPeriod({ ...authApiOptions(), path: { period }, body }),
      );
      if (!result.response?.ok || !result.data) throw periodClosureRequestError(result);
      return result.data;
    },
    onError: refresh,
    onSuccess: async () => {
      await refresh();
      onDone();
    },
  });

  return { close, reopen, status };
}
