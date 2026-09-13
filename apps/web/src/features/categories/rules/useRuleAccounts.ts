import { useQuery } from '@tanstack/react-query';
import { CategorizationRuleRequestError } from './categorizationRuleError';
import { listRuleScopeAccounts } from './ruleAccounts';

/**
 * Every non-archived, non-closed account in the caller workspace, resolved
 * across all of its pages so the rule editor always offers a complete scope
 * instead of silently truncating it at the first page.
 */
export function useRuleAccounts() {
  const query = useQuery({
    queryKey: ['accounts', 'rule-scope'],
    queryFn: ({ signal }) => listRuleScopeAccounts(signal),
    retry: false,
  });
  const available = query.isSuccess && query.data !== undefined;
  const unauthorized =
    query.error instanceof CategorizationRuleRequestError && query.error.kind === 'unauthorized';

  return { available, query, unauthorized };
}
