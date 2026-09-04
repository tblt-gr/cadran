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
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { AddPeriodForm } from './add-period-form/AddPeriodForm';
import { ArchiveModelDialog } from './archive-model-dialog/ArchiveModelDialog';
import { DuplicateModelForm } from './duplicate-model-form/DuplicateModelForm';
import { FromProductForm } from './from-product-form/FromProductForm';
import { ModelForm } from './model-form/ModelForm';
import { ProductModelList } from './product-model-list/ProductModelList';
import { ProductModelPagination } from './product-model-pagination/ProductModelPagination';
import { ProductModelPeriodTable } from './product-model-periods/ProductModelPeriodTable';
import { ProductModelsState } from './product-models-state/ProductModelsState';
import {
  productModelErrorKind,
  productModelRequestError,
  ProductModelRequestError,
  requestFailed,
} from './productModelError';
import { PRODUCT_MODELS_PAGE_SIZE, useProductModels } from './useProductModels';
import styles from './ProductModelsPage.module.css';

type Editor = 'create' | 'from-product' | null;

export function ProductModelsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [includeArchived, setIncludeArchived] = useState(false);
  const [page, setPage] = useState(1);
  const [editor, setEditor] = useState<Editor>(null);
  const [duplicating, setDuplicating] = useState<ProductModel | null>(null);
  const [archiving, setArchiving] = useState<ProductModel | null>(null);
  const [addingPeriod, setAddingPeriod] = useState<ProductModel | null>(null);
  const [inspecting, setInspecting] = useState<ProductModel | null>(null);
  const [saved, setSaved] = useState<'saved' | 'archived' | null>(null);

  const models = useProductModels(includeArchived, page);

  async function refreshOnStaleState(error: unknown) {
    if (
      error instanceof ProductModelRequestError &&
      (error.kind === 'stale' || error.kind === 'archived')
    ) {
      await queryClient.invalidateQueries({ queryKey: ['product-models'] });
    }
  }

  async function onSaved(kind: 'saved' | 'archived') {
    setEditor(null);
    setDuplicating(null);
    setArchiving(null);
    setAddingPeriod(null);
    setSaved(kind);
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

  function openEditor(target: Exclude<Editor, null>) {
    setSaved(null);
    create.reset();
    fromProduct.reset();
    setEditor(target);
  }

  function openDuplicate(model: ProductModel) {
    setSaved(null);
    duplicate.reset();
    setDuplicating(model);
  }

  function openArchive(model: ProductModel) {
    setSaved(null);
    archive.reset();
    setArchiving(model);
  }

  function openAddPeriod(model: ProductModel) {
    setSaved(null);
    addPeriod.reset();
    setAddingPeriod(model);
  }

  const unauthorized =
    models.error instanceof ProductModelRequestError && models.error.status === 401;
  const items = models.data?.items ?? [];
  const totalPages = Math.max(1, Math.ceil((models.data?.total ?? 0) / PRODUCT_MODELS_PAGE_SIZE));

  if (models.data && page > totalPages) {
    setPage(totalPages);
  }

  return (
    <div className={styles.page}>
      <section className={styles.intro} aria-labelledby="product-models-intro-title">
        <div>
          <p>{t('productModels.eyebrow')}</p>
          <h2 id="product-models-intro-title">{t('productModels.title')}</h2>
          <span>{t('productModels.description')}</span>
        </div>
        <div className={styles.introActions}>
          <button
            className="secondary-action"
            onClick={() => openEditor('from-product')}
            type="button"
          >
            {t('productModels.addFromProduct')}
          </button>
          <button className="primary-action" onClick={() => openEditor('create')} type="button">
            {t('productModels.add')}
          </button>
        </div>
      </section>

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>
          {t(saved === 'archived' ? 'productModels.archived' : 'productModels.saved')}
        </Toast>
      ) : null}

      {editor === 'create' ? (
        <Modal
          close={() => setEditor(null)}
          eyebrow={t('productModels.form.createEyebrow')}
          title={t('productModels.form.createTitle')}
        >
          <ModelForm
            onCancel={() => setEditor(null)}
            onSubmit={(body) => create.mutate(body)}
            pending={create.isPending}
            submitError={productModelErrorKind(create.error, create.isError)}
          />
        </Modal>
      ) : null}

      {editor === 'from-product' ? (
        <Modal
          close={() => setEditor(null)}
          eyebrow={t('productModels.fromProduct.eyebrow')}
          title={t('productModels.fromProduct.title')}
        >
          <FromProductForm
            onCancel={() => setEditor(null)}
            onSubmit={(body) => fromProduct.mutate(body)}
            pending={fromProduct.isPending}
            submitError={productModelErrorKind(fromProduct.error, fromProduct.isError)}
          />
        </Modal>
      ) : null}

      {duplicating ? (
        <Modal
          close={() => setDuplicating(null)}
          eyebrow={t('productModels.duplicate.eyebrow')}
          title={t('productModels.duplicate.title')}
        >
          <DuplicateModelForm
            key={duplicating.id}
            model={duplicating}
            onCancel={() => setDuplicating(null)}
            onSubmit={(name) => duplicate.mutate({ model: duplicating, name })}
            pending={duplicate.isPending}
            submitError={productModelErrorKind(duplicate.error, duplicate.isError)}
          />
        </Modal>
      ) : null}

      {addingPeriod ? (
        <Modal
          close={() => setAddingPeriod(null)}
          eyebrow={t('productModels.period.eyebrow')}
          title={t('productModels.period.title', { name: addingPeriod.name })}
        >
          <AddPeriodForm
            key={addingPeriod.id}
            model={addingPeriod}
            onCancel={() => setAddingPeriod(null)}
            onSubmit={(rule, version) => addPeriod.mutate({ model: addingPeriod, rule, version })}
            pending={addPeriod.isPending}
            submitError={productModelErrorKind(addPeriod.error, addPeriod.isError)}
          />
        </Modal>
      ) : null}

      {archiving ? (
        <Modal
          close={() => setArchiving(null)}
          eyebrow={t('productModels.archive.eyebrow')}
          title={t('productModels.archive.title')}
        >
          <ArchiveModelDialog
            key={archiving.id}
            model={archiving}
            onCancel={() => setArchiving(null)}
            onConfirm={() => archive.mutate(archiving)}
            pending={archive.isPending}
            submitError={productModelErrorKind(archive.error, archive.isError)}
          />
        </Modal>
      ) : null}

      {inspecting ? (
        <Modal
          close={() => setInspecting(null)}
          eyebrow={t('productModels.periods.eyebrow')}
          title={t('productModels.periods.title', { name: inspecting.name })}
        >
          <ProductModelPeriodTable name={inspecting.name} rules={inspecting.rules} />
        </Modal>
      ) : null}

      <label className={styles.filter}>
        <input
          checked={includeArchived}
          onChange={(event) => {
            setIncludeArchived(event.target.checked);
            setPage(1);
          }}
          type="checkbox"
        />
        <span>{t('productModels.includeArchived')}</span>
      </label>

      {models.isPending || models.isError || items.length === 0 ? (
        <ProductModelsState
          kind={
            models.isPending
              ? 'loading'
              : unauthorized
                ? 'unauthorized'
                : models.isError
                  ? 'error'
                  : 'empty'
          }
          onCreate={() => openEditor('create')}
          onRetry={() => void models.refetch()}
        />
      ) : (
        <ProductModelList
          models={items}
          onAddPeriod={openAddPeriod}
          onArchive={openArchive}
          onDuplicate={openDuplicate}
          onInspect={setInspecting}
        />
      )}

      {models.isSuccess && (totalPages > 1 || page > 1) ? (
        <ProductModelPagination onPageChange={setPage} page={page} totalPages={totalPages} />
      ) : null}
    </div>
  );
}
