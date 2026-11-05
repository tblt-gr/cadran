import {
  readTransactionFacets,
  type ReadTransactionFacetsData,
  type TransactionFacets,
} from '@cadran/api-client';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { transactionRequestError } from '@/features/transactions/transactionError';
import { useDebouncedValue } from '@/hooks/use-debounced-value';

/** The listing's filters without its paging: the facets endpoint refuses `cursor` and `pageSize`. */
export type TransactionFacetsQuery = NonNullable<ReadTransactionFacetsData['query']>;

/** What a screen asks the filter column to count: the list's filters, and whether they can match. */
export interface TransactionFacetsRequest {
  /** False while the filters can match nothing: there is nothing to count. */
  enabled: boolean;
  query: TransactionFacetsQuery;
}

export interface TransactionFacetsState {
  /** Counts by section, then by option; absent while pending, after a failure and while disabled. */
  counts: TransactionFacets | undefined;
  /** True only until the first answer: a later answer replaces counts already on screen. */
  pending: boolean;
}

/** A burst of filter clicks costs one request: the counts follow once the filters settle. */
const SETTLE_MS = 250;

/**
 * How many transactions each option of the filter column would keep, under the filters of the
 * list. The counts are best-effort decoration: any failure only hides them, and the previous
 * answer stays on screen while the next one loads rather than flashing a skeleton.
 */
export function useTransactionFacets(
  query: TransactionFacetsQuery,
  enabled: boolean,
): TransactionFacetsState {
  // The debounce restarts whenever the value changes identity, and `query` is rebuilt on every
  // render: its serialization is the stable value to wait on.
  const settled = useDebouncedValue(JSON.stringify(query), SETTLE_MS);
  const settledQuery = useMemo(() => JSON.parse(settled) as TransactionFacetsQuery, [settled]);

  const facets = useQuery({
    queryKey: ['transactions', 'facets', settledQuery],
    queryFn: async ({ signal }) => {
      const result = await readTransactionFacets({
        ...authApiOptions(),
        query: settledQuery,
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    enabled,
    placeholderData: keepPreviousData,
    retry: false,
  });

  // A disabled query stays pending forever, and may still hold an answer for another filter set:
  // neither is shown. A failed background refresh also retains cached data, which must disappear
  // on error. A query the caller enables but cannot run (a frozen preview) still reads as pending,
  // so its options render their skeleton counts.
  return {
    counts: enabled && !facets.isError ? facets.data : undefined,
    pending: enabled && facets.isPending,
  };
}
