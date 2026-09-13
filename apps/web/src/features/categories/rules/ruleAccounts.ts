import { listAccounts, type Account } from '@cadran/api-client';
import { authApiOptions } from '@/features/auth/apiOptions';
import {
  CategorizationRuleRequestError,
  categorizationRuleRequestError,
} from './categorizationRuleError';

const PAGE_SIZE = 100;

export async function listRuleScopeAccounts(signal?: AbortSignal): Promise<Account[]> {
  const accounts: Account[] = [];
  let total = 0;

  for (let page = 1; page === 1 || accounts.length < total; page += 1) {
    const result = await listAccounts({
      ...authApiOptions(),
      query: { includeArchived: false, includeClosed: false, page, perPage: PAGE_SIZE },
      signal,
    });
    if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);

    total = result.data.total;
    accounts.push(...result.data.items);

    if (result.data.items.length === 0 && accounts.length < total) {
      throw new CategorizationRuleRequestError(0);
    }
  }

  return accounts;
}
