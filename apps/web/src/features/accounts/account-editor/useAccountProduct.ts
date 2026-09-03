import { readProduct, type Product } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

export interface AccountProductQuery {
  product: Product | null;
  isPending: boolean;
  isError: boolean;
}

/**
 * The catalogue model an existing account follows, read on the account's own
 * opening date so the rules shown are the ones that covered it.
 *
 * An account with no product resolves immediately to none. When the catalogue
 * cannot be read the product stays null and the caller says so, rather than
 * letting the form offer a kind the API would refuse.
 */
export function useAccountProduct(code: string | null, asOf: string): AccountProductQuery {
  const product = useQuery({
    enabled: code !== null,
    queryKey: ['product', code, asOf],
    queryFn: async ({ signal }) => {
      const result = await readProduct({
        ...authApiOptions(),
        path: { code: code as string },
        query: { asOf },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw new Error('Unable to load the account product.');
      }
      return result.data;
    },
    retry: false,
  });

  if (code === null) {
    return { product: null, isPending: false, isError: false };
  }

  return {
    product: product.data ?? null,
    isPending: product.isPending,
    isError: product.isError,
  };
}
