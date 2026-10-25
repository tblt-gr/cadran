import { listAccounts, type Account, type AccountPage } from '@cadran/api-client';
import { authApiOptions } from '@/features/auth/apiOptions';
import { transactionRequestError } from './transactionError';

const PAGE_SIZE = 100;
const MAX_PAGE = 1_000;

/** Read every option so a primary account beyond the first page is still offered. */
export async function listActiveAccounts(signal?: AbortSignal): Promise<AccountPage> {
  const items: Account[] = [];

  for (let page = 1; page <= MAX_PAGE; page += 1) {
    const result = await listAccounts({
      ...authApiOptions(),
      query: { includeArchived: false, includeClosed: false, page, pageSize: PAGE_SIZE },
      signal,
    });
    if (!result.response?.ok || !result.data) throw transactionRequestError(result);
    items.push(...result.data.items);
    if (page * PAGE_SIZE >= result.data.total) return { ...result.data, items };
  }

  throw new Error('The account option limit was exceeded.');
}

export async function listReferencedAccounts(
  accountIds: readonly string[],
  signal?: AbortSignal,
): Promise<Account[]> {
  const unresolved = new Set(accountIds);
  const resolved: Account[] = [];

  for (let page = 1; unresolved.size > 0 && page <= MAX_PAGE; page += 1) {
    const result = await listAccounts({
      ...authApiOptions(),
      query: { includeArchived: true, includeClosed: true, page, pageSize: PAGE_SIZE },
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
