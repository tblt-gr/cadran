import { readHoldings, type ReadHoldingsData } from '@cadran/api-client';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

export type HoldingsSort = NonNullable<NonNullable<ReadHoldingsData['query']>['sort']>;

export const DEFAULT_HOLDINGS_SORT: HoldingsSort = 'VALUE_DESC';
const SORTS: readonly HoldingsSort[] = ['VALUE_DESC', 'GAIN_DESC', 'PERFORMANCE_DESC', 'NAME_ASC'];

/** Only the published orders reach the server; anything else in the URL is the default. */
export function parseHoldingsSort(value: string | null): HoldingsSort {
  return SORTS.find((sort) => sort === value) ?? DEFAULT_HOLDINGS_SORT;
}

export type HoldingsViewMode = 'asset' | 'account';

export function parseHoldingsView(value: string | null): HoldingsViewMode {
  return value === 'account' ? 'account' : 'asset';
}

/** One read of every holding. The key stays under `holdings`, which every portfolio write invalidates. */
export function useHoldings(sort: HoldingsSort) {
  return useQuery({
    queryKey: ['holdings', sort],
    retry: false,
    // A sort click keeps the rows, and the disclosures opened on them, while the new order loads.
    placeholderData: keepPreviousData,
    queryFn: async ({ signal }) => {
      const result = await readHoldings({ ...authApiOptions(), query: { sort }, signal });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
  });
}
