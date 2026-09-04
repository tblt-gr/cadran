import { listProductModels, type ProductModelPage } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { productModelRequestError, requestFailed } from './productModelError';

export const PRODUCT_MODELS_PAGE_SIZE = 50;

/**
 * One page of the calling workspace's product models. Archived models are left
 * out unless asked for; they stay readable one by one so an account created
 * from a retired model keeps a reference it can resolve.
 */
export function useProductModels(includeArchived: boolean, page: number) {
  return useQuery<ProductModelPage>({
    queryKey: ['product-models', includeArchived, page],
    queryFn: async ({ signal }) => {
      const result = await listProductModels({
        ...authApiOptions(),
        query: { includeArchived, page, perPage: PRODUCT_MODELS_PAGE_SIZE },
        signal,
      });
      if (requestFailed(result)) {
        throw productModelRequestError(result);
      }
      return result.data as ProductModelPage;
    },
    retry: false,
  });
}
