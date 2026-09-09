import { listAccounts, type Account } from '@cadran/api-client';
import { authApiOptions } from '@/features/auth/apiOptions';
import { transactionRequestError } from './transactionError';

const PAGE_SIZE = 100;
const MAX_PAGE = 1_000;

export async function listReferencedAccounts(
  accountIds: readonly string[],
  signal?: AbortSignal,
): Promise<Account[]> {
  const unresolved = new Set(accountIds);
  const resolved: Account[] = [];

  for (let page = 1; unresolved.size > 0 && page <= MAX_PAGE; page += 1) {
    const result = await listAccounts({
      ...authApiOptions(),
      query: { includeArchived: true, includeClosed: true, page, perPage: PAGE_SIZE },
      signal,
    });
    if (!result.response?.ok || !result.data) {
      throw transactionRequestError(result);
    }

    for (const account of result.data.items) {
      if (unresolved.delete(account.id)) {
        resolved.push(account);
      }
    }

    if (page * PAGE_SIZE >= result.data.total) {
      break;
    }
  }

  return resolved;
}

export function mergeAccountOptions(primary: Account[], referenced: Account[]): Account[] {
  const merged = [...primary];
  const known = new Set(primary.map((account) => account.id));

  for (const account of referenced) {
    if (!known.has(account.id)) {
      merged.push(account);
    }
  }

  return merged;
}
