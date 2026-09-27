import { listAccountGroups, listAccounts, listCategories } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

const PAGE_SIZE = 50;
const MAX_PAGES = 20;

export interface CatalogueEntry {
  id: string;
  label: string;
}

interface Page<T> {
  items: T[];
  total: number;
}

async function collect<T extends { id: string; label: string }>(
  fetchPage: (page: number) => Promise<{ data?: Page<T>; response?: Response }>,
): Promise<CatalogueEntry[]> {
  const entries: CatalogueEntry[] = [];
  let total = 0;
  for (let page = 1; page <= MAX_PAGES && (page === 1 || entries.length < total); page += 1) {
    const result = await fetchPage(page);
    if (!result.response?.ok || !result.data) throw new Error('annual-catalogue');
    total = result.data.total;
    entries.push(...result.data.items.map(({ id, label }) => ({ id, label })));
    if (result.data.items.length === 0) break;
  }

  return entries;
}

/** Every category, group and account of the workspace, archived or not, that a column can point at. */
export function useColumnCatalogue(enabled: boolean) {
  return useQuery({
    enabled,
    queryKey: ['annual-report-catalogue'],
    queryFn: async () => {
      const [categories, groups, accounts] = await Promise.all([
        collect((page) =>
          listCategories({
            ...authApiOptions(),
            query: { includeArchived: true, page, perPage: PAGE_SIZE },
          }),
        ),
        collect((page) =>
          listAccountGroups({
            ...authApiOptions(),
            query: { includeArchived: true, page, perPage: PAGE_SIZE },
          }),
        ),
        collect((page) =>
          listAccounts({
            ...authApiOptions(),
            query: { includeArchived: true, includeClosed: true, page, perPage: PAGE_SIZE },
          }),
        ),
      ]);

      return { categories, groups, accounts };
    },
    retry: false,
  });
}
