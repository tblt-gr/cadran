import { listAccountGroups, type AccountGroup } from '@cadran/api-client';
import { authApiOptions } from '@/features/auth/apiOptions';
import { groupRequestError } from '@/features/accounts/groups/groupError';

const PAGE_SIZE = 100;

/** Every page of the workspace groups; throws when any page cannot be read. */
export async function listAllAccountGroups(
  includeArchived: boolean,
  signal?: AbortSignal,
): Promise<AccountGroup[]> {
  const items: AccountGroup[] = [];
  let totalPages = 1;
  for (let page = 1; page <= totalPages; page += 1) {
    const result = await listAccountGroups({
      ...authApiOptions(),
      query: { includeArchived, page, pageSize: PAGE_SIZE },
      ...(signal ? { signal } : {}),
    });
    if (!result.response?.ok || !result.data) {
      throw groupRequestError(result);
    }
    if (page === 1) {
      totalPages = Math.ceil(result.data.total / result.data.pageSize);
    }
    items.push(...result.data.items);
  }

  return items;
}
