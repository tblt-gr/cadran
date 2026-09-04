import { listProducts, type Product } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

const PAGE_SIZE = 100;

export interface CatalogProducts {
  items: Product[];
  total: number;
  isPending: boolean;
  isError: boolean;
  refetch: () => void;
}

/**
 * The system catalogue products a workspace model can be started from, resolved
 * on the viewer's day. The catalogue is a small read-only reference, so one
 * page holds it; the server copies the row itself, so nothing here is sent back
 * beyond the chosen code.
 */
export function useCatalogProducts(asOf: string): CatalogProducts {
  const products = useQuery({
    queryKey: ['products', 'product-models', asOf],
    queryFn: async ({ signal }) => {
      const result = await listProducts({
        ...authApiOptions(),
        query: { asOf, page: 1, perPage: PAGE_SIZE },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw new Error('Unable to load the product catalogue.');
      }
      return result.data;
    },
    retry: false,
  });

  return {
    items: products.data?.items ?? [],
    total: products.data?.total ?? 0,
    isPending: products.isPending,
    isError: products.isError,
    refetch: () => void products.refetch(),
  };
}
