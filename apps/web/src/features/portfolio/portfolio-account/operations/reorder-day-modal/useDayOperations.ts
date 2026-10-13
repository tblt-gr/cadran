import { listPortfolioOperations, type PortfolioOperation } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

const PAGE_SIZE = 100;

/** Reads the complete active set for a day, independently of table filters and pagination. */
export function useDayOperations(accountId: string, tradeDate: string) {
  return useQuery({
    queryKey: ['portfolio-operation-day', accountId, tradeDate],
    queryFn: async ({ signal }) => {
      const items: PortfolioOperation[] = [];
      let page = 1;
      let total = 0;
      do {
        const result = await listPortfolioOperations({
          ...authApiOptions(),
          path: { accountId },
          query: {
            from: tradeDate,
            to: tradeDate,
            page,
            pageSize: PAGE_SIZE,
            includeVoided: false,
          },
          signal,
        });
        if (!result.response?.ok || !result.data) throw operationRequestError(result);
        items.push(...result.data.items);
        total = result.data.total;
        page += 1;
      } while (items.length < total);
      return items
        .filter((item) => item.state === 'ACTIVE')
        .sort((left, right) => left.sequence - right.sequence);
    },
    retry: false,
  });
}
