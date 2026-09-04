import { listProductModels, type ProductModel } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

const PAGE_SIZE = 100;

export interface TemplateOptions {
  items: ProductModel[];
  /** How many templates the workspace holds, which may exceed the page shown. */
  total: number;
  isPending: boolean;
  isError: boolean;
  refetch: () => void;
}

/**
 * The reusable product models of the calling workspace an account may be
 * created from.
 *
 * Archived templates are left out: archiving is how a template stops backing
 * new use, so offering one here would offer exactly the use it was retired
 * from. Nothing here computes or defaults a rule; the account only ever keeps
 * the reference, and its dated periods are read again where they are needed.
 */
export function useTemplateOptions(): TemplateOptions {
  const templates = useQuery({
    // Scoped to this picker: the product models page reads the same endpoint
    // with its own page and size, and a shared key would serve each the
    // other's page.
    queryKey: ['product-models', 'account-wizard'],
    queryFn: async ({ signal }) => {
      const result = await listProductModels({
        ...authApiOptions(),
        query: { includeArchived: false, page: 1, perPage: PAGE_SIZE },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw new Error('Unable to load the workspace product models.');
      }
      return result.data;
    },
    retry: false,
  });

  return {
    items: templates.data?.items ?? [],
    total: templates.data?.total ?? 0,
    isPending: templates.isPending,
    isError: templates.isError,
    refetch: () => void templates.refetch(),
  };
}
