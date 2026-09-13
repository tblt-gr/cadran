import { listCategorizationRules } from '@cadran/api-client';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { categorizationRuleRequestError } from './categorizationRuleError';

const PAGE_SIZE = 100;

/**
 * One page of active, non-archived rules, ordered as the backend states
 * them. The previous page's rows stay on screen while the next one
 * resolves, so a keyboard user's focus is never torn away from the
 * pagination control they just activated. A page left stranded past the
 * end by a concurrent archive is brought back into range once the new
 * total is known.
 */
export function useCategorizationRules() {
  const [page, setPage] = useState(1);
  const query = useQuery({
    queryKey: ['categorization-rules', page],
    queryFn: async ({ signal }) => {
      const result = await listCategorizationRules({
        ...authApiOptions(),
        query: { includeArchived: false, page, perPage: PAGE_SIZE },
        signal,
      });
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    placeholderData: keepPreviousData,
    retry: false,
  });
  const list = query.data?.items ?? [];
  const totalPages = Math.max(1, Math.ceil((query.data?.total ?? 0) / PAGE_SIZE));

  useEffect(() => {
    if (query.data && page > totalPages) setPage(totalPages);
  }, [page, query.data, totalPages]);

  return { goToPage: setPage, list, page, query, totalPages };
}
