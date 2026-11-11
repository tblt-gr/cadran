import { listCategorizationRules } from '@cadran/api-client';
import { useIncrementalList } from '@/hooks/incremental-list/useIncrementalList';
import { authApiOptions } from '@/features/auth/apiOptions';
import { categorizationRuleRequestError } from './categorizationRuleError';

export function useCategorizationRules() {
  const query = useIncrementalList({
    queryKey: ['categorization-rules'],
    request: ({ page, revision, signal }) =>
      listCategorizationRules({
        ...authApiOptions(),
        headers: { 'X-List-Revision': revision },
        query: { includeArchived: false, page, pageSize: 100 },
        signal,
      }),
    requestError: categorizationRuleRequestError,
  });
  return { list: query.data?.items ?? [], query };
}
