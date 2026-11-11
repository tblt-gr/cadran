import { listProductModels } from '@cadran/api-client';
import { useIncrementalList } from '@/hooks/incremental-list/useIncrementalList';
import { authApiOptions } from '@/features/auth/apiOptions';
import { productModelRequestError } from './productModelError';

const PRODUCT_MODELS_PAGE_SIZE = 50;

/**
 * One page of the calling workspace's product models. Archived models are left
 * out unless asked for; they stay readable one by one so an account created
 * from a retired model keeps a reference it can resolve.
 */
export function useProductModels(includeArchived: boolean) {
  return useIncrementalList({
    queryKey: ['product-models', includeArchived],
    request: async ({ signal, page, revision }) => {
      const result = await listProductModels({
        ...authApiOptions(),
        headers: { 'X-List-Revision': revision },
        query: { includeArchived, page, pageSize: PRODUCT_MODELS_PAGE_SIZE },
        signal,
      });
      return result;
    },
    requestError: productModelRequestError,
  });
}
