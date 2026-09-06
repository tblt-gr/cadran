import { listProducts, type Product } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

const PAGE_SIZE = 100;

export interface ProductOptions {
  items: Product[];
  /** How many products the catalogue holds, which may exceed the page shown. */
  total: number;
  isPending: boolean;
  isError: boolean;
  refetch: () => void;
}

/**
 * The catalogue products an account may be created from, resolved on a business
 * date so every inherited rule arrives with the period it covers.
 *
 * The catalogue is a small read-only system reference, so one page holds it.
 * Nothing here computes or defaults a regulatory value: a product whose rule is
 * unsourced on this date arrives with that kind listed as unavailable.
 */
export function useProductOptions(asOf: string): ProductOptions {
  const products = useQuery({
    // Scoped to this picker: the catalogue page reads the same endpoint with
    // its own page size, and a shared key would serve each the other's page.
    queryKey: ['products', 'account-wizard', asOf],
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
    enabled: asOf.length > 0,
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
