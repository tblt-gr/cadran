import {
  addProductModelRule,
  archiveProductModel,
  createProductModel,
  createProductModelFromProduct,
  duplicateProductModel,
  type CreateProductModelFromProductRequest,
  type CreateProductModelRequest,
  type ProductModel,
  type ProductModelRuleInput,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  productModelRequestError,
  ProductModelRequestError,
  requestFailed,
} from './productModelError';

/** The five writes of the product models page; each success refreshes the list after `onWritten`. */
export function useProductModelWrites(onWritten: (kind: 'saved' | 'archived') => void) {
  const queryClient = useQueryClient();

  async function refreshOnStaleState(error: unknown) {
    if (
      error instanceof ProductModelRequestError &&
      (error.kind === 'stale' || error.kind === 'archived')
    ) {
      await queryClient.invalidateQueries({ queryKey: ['product-models'] });
    }
  }

  async function onSaved(kind: 'saved' | 'archived') {
    onWritten(kind);
    await queryClient.invalidateQueries({ queryKey: ['product-models'] });
  }

  const create = useMutation({
    mutationFn: async (body: CreateProductModelRequest) => {
      const result = await withCsrfRetry(() => createProductModel({ ...authApiOptions(), body }));
      if (requestFailed(result)) {
        throw productModelRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleState,
    onSuccess: () => onSaved('saved'),
  });

  const fromProduct = useMutation({
    mutationFn: async (body: CreateProductModelFromProductRequest) => {
      const result = await withCsrfRetry(() =>
        createProductModelFromProduct({ ...authApiOptions(), body }),
      );
      if (requestFailed(result)) {
        throw productModelRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleState,
    onSuccess: () => onSaved('saved'),
  });

  const duplicate = useMutation({
    mutationFn: async ({ model, name }: { model: ProductModel; name: string }) => {
      const result = await withCsrfRetry(() =>
        duplicateProductModel({ ...authApiOptions(), path: { id: model.id }, body: { name } }),
      );
      if (requestFailed(result)) {
        throw productModelRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleState,
    onSuccess: () => onSaved('saved'),
  });

  const addPeriod = useMutation({
    mutationFn: async ({
      model,
      rule,
      version,
    }: {
      model: ProductModel;
      rule: ProductModelRuleInput;
      version: number;
    }) => {
      const result = await withCsrfRetry(() =>
        addProductModelRule({
          ...authApiOptions(),
          path: { id: model.id },
          body: { ...rule, version },
        }),
      );
      if (requestFailed(result)) {
        throw productModelRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleState,
    onSuccess: () => onSaved('saved'),
  });

  const archive = useMutation({
    mutationFn: async (model: ProductModel) => {
      const result = await withCsrfRetry(() =>
        archiveProductModel({
          ...authApiOptions(),
          path: { id: model.id },
          body: { version: model.version },
        }),
      );
      if (requestFailed(result)) {
        throw productModelRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleState,
    onSuccess: () => onSaved('archived'),
  });

  return { create, fromProduct, duplicate, addPeriod, archive };
}
