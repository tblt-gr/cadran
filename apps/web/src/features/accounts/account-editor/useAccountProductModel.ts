import { readProductModel, type ProductModel } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

export interface AccountProductModelQuery {
  template: ProductModel | null;
  isPending: boolean;
  isError: boolean;
}

/**
 * The workspace template an existing account follows.
 *
 * An account with no template resolves immediately to none. When the
 * template cannot be read the reference stays null and the caller says so,
 * rather than letting the form offer a kind the API would refuse. Dated
 * periods stay on the model and are resolved by the rules endpoint; this
 * hook only needs the envelope (family, capabilities, valuation mode) to
 * lock the form.
 */
export function useAccountProductModel(id: string | null): AccountProductModelQuery {
  const model = useQuery({
    enabled: id !== null,
    queryKey: ['product-model', id],
    queryFn: async ({ signal }) => {
      const result = await readProductModel({
        ...authApiOptions(),
        path: { id: id as string },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw new Error('Unable to load the account product model.');
      }
      return result.data;
    },
    retry: false,
  });

  if (id === null) {
    return { template: null, isPending: false, isError: false };
  }

  return {
    template: model.data ?? null,
    isPending: model.isPending,
    isError: model.isError,
  };
}
