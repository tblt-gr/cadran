import { listCategories, type Category } from '@cadran/api-client';
import { authApiOptions } from '@/features/auth/apiOptions';
import { categoryRequestError } from './categoryError';

const PAGE_SIZE = 100;
const MAX_PAGES = 5;

/** The workspace holds more categories than the tree can load: a partial tree would mislead. */
export class PartialCategoryList extends Error {}

/** The tree needs every category of the workspace: it is bounded to 500, so at most 5 pages. */
export async function listAllCategories(
  includeArchived: boolean,
  signal: AbortSignal,
): Promise<Category[]> {
  const items: Category[] = [];
  let total = 0;
  for (let page = 1; page <= MAX_PAGES; page += 1) {
    const result = await listCategories({
      ...authApiOptions(),
      query: { includeArchived, page, pageSize: PAGE_SIZE },
      signal,
    });
    if (!result.response?.ok || !result.data) {
      throw categoryRequestError(result);
    }
    total = result.data.total;
    items.push(...result.data.items);
    if (result.data.items.length === 0 || items.length >= result.data.total) {
      break;
    }
  }
  if (items.length < total) {
    throw new PartialCategoryList();
  }
  return items;
}
