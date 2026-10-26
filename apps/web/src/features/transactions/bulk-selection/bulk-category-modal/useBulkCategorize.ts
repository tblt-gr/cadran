import {
  bulkCategorizeTransactions,
  type BulkCategorizeResult,
  type Problem,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { invalidateAccountBalances } from '@/features/accounts/invalidateAccountBalances';
import { authApiOptions } from '@/features/auth/apiOptions';
import { useSubmissionKey } from '@/hooks/use-submission-key';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import type { BulkScope } from '@/features/transactions/bulk-selection/useBulkSelection';

export type BulkErrorKind =
  | 'conflict'
  | 'invalid'
  | 'network'
  | 'periodClosed'
  | 'scopeChanged'
  | 'scopeTooLarge'
  | 'unauthorized';

/** A refused bulk request, classified by its RFC 9457 problem type. */
export class BulkRequestError extends Error {
  readonly actualCount: number | null;
  readonly kind: BulkErrorKind;

  constructor(status: number, problem: (Problem & { actualCount?: number }) | undefined) {
    super(`Bulk request failed with status ${status}.`);
    this.actualCount = typeof problem?.actualCount === 'number' ? problem.actualCount : null;
    this.kind = classify(status, problem?.type);
  }
}

function classify(status: number, type: string | undefined): BulkErrorKind {
  if (type === '/problems/transactions.bulk-scope-too-large') return 'scopeTooLarge';
  if (type === '/problems/transactions.bulk-scope-changed') return 'scopeChanged';
  if (type === '/problems/period-closed') return 'periodClosed';
  if (status === 401 || status === 403) return 'unauthorized';
  if (status === 409) return 'conflict';
  return status === 400 || status === 404 || status === 422 ? 'invalid' : 'network';
}

interface UseBulkCategorizeOptions {
  reloadAfterWrite: () => Promise<void>;
}

/** The bulk category write: one idempotency key per distinct body, so a retry replays. */
export function useBulkCategorize({ reloadAfterWrite }: UseBulkCategorizeOptions) {
  const queryClient = useQueryClient();
  const submission = useSubmissionKey();

  return useMutation<BulkCategorizeResult, unknown, { categoryId: string; scope: BulkScope }>({
    mutationFn: async (body) => {
      const result = await withCsrfRetry(() =>
        bulkCategorizeTransactions({
          ...authApiOptions(),
          headers: { ...authApiOptions().headers, 'Idempotency-Key': submission.keyFor(body) },
          body,
        }),
      );
      if (!result.response?.ok || !result.data) {
        throw new BulkRequestError(
          result.response?.status ?? 0,
          result.error as Problem | undefined,
        );
      }
      return result.data;
    },
    onSuccess: async () => {
      submission.release();
      await queryClient.invalidateQueries({ queryKey: ['transactions', 'count'] });
      await reloadAfterWrite();
      await invalidateAccountBalances(queryClient);
    },
  });
}
