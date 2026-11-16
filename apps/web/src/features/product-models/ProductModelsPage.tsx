import type { ProductModel } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { SettingsTabs } from '@/components/layout/settings-tabs/SettingsTabs';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { AddPeriodForm } from './add-period-form/AddPeriodForm';
import { ArchiveModelDialog } from './archive-model-dialog/ArchiveModelDialog';
import { DuplicateModelForm } from './duplicate-model-form/DuplicateModelForm';
import { FromProductForm } from './from-product-form/FromProductForm';
import { ModelForm } from './model-form/ModelForm';
import { ProductModelFilters } from './product-model-filters/ProductModelFilters';
import { ProductModelList } from './product-model-list/ProductModelList';
import { IncrementalList } from '@/components/ui/incremental-list/IncrementalList';
import { ProductModelPeriodTable } from './product-model-periods/ProductModelPeriodTable';
import { ProductModelsState } from './product-models-state/ProductModelsState';
import { ProductModelsPageSkeleton } from './ProductModelsPageSkeleton';
import { productModelErrorKind, ProductModelRequestError } from './productModelError';
import { useProductModelWrites } from './useProductModelWrites';
import { useProductModels } from './useProductModels';
import styles from './ProductModelsPage.module.css';

type Editor = 'create' | 'from-product' | null;

export function ProductModelsPage() {
  const { t } = useTranslation();
  const [includeArchived, setIncludeArchived] = useState(false);
  const [editor, setEditor] = useState<Editor>(null);
  const [duplicating, setDuplicating] = useState<ProductModel | null>(null);
  const [archiving, setArchiving] = useState<ProductModel | null>(null);
  const [addingPeriod, setAddingPeriod] = useState<ProductModel | null>(null);
  const [inspecting, setInspecting] = useState<ProductModel | null>(null);
  const [saved, setSaved] = useState<'saved' | 'archived' | null>(null);

  const models = useProductModels(includeArchived);

  const { create, fromProduct, duplicate, addPeriod, archive } = useProductModelWrites((kind) => {
    setEditor(null);
    setDuplicating(null);
    setArchiving(null);
    setAddingPeriod(null);
    setSaved(kind);
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
    models.error instanceof ProductModelRequestError && models.error.kind === 'unauthorized';
  const items = models.data?.items ?? [];
  return (
    <div className={styles.page}>
      <SettingsTabs path="/parameters/product-models" />
      <HeaderActions>
        <button className="primary-action" onClick={() => openEditor('from-product')} type="button">
          <Icon name="add" size={18} />
          {t('productModels.add')}
        </button>
        <ProductModelFilters
          includeArchived={includeArchived}
          onIncludeArchivedChange={(checked) => {
            setIncludeArchived(checked);
          }}
        />
      </HeaderActions>

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>
          {t(saved === 'archived' ? 'productModels.archived' : 'productModels.saved')}
        </Toast>
      ) : null}

      {editor !== null ? (
        <Modal
          close={() => setEditor(null)}
          intent="create"
          title={t(
            editor === 'create'
              ? 'productModels.form.createTitle'
              : 'productModels.fromProduct.title',
          )}
        >
          {editor === 'create' ? (
            <ModelForm
              alternative={
                <button
                  className="secondary-action"
                  onClick={() => openEditor('from-product')}
                  type="button"
                >
                  {t('productModels.fromProduct.switchBack')}
                </button>
              }
              onCancel={() => setEditor(null)}
              onSubmit={(body) => create.mutate(body)}
              pending={create.isPending}
              submitError={productModelErrorKind(create.error, create.isError)}
            />
          ) : (
            <FromProductForm
              alternative={
                <button
                  className="secondary-action"
                  onClick={() => openEditor('create')}
                  type="button"
                >
                  {t('productModels.fromProduct.switchToManual')}
                </button>
              }
              onCancel={() => setEditor(null)}
              onSubmit={(body) => fromProduct.mutate(body)}
              pending={fromProduct.isPending}
              submitError={productModelErrorKind(fromProduct.error, fromProduct.isError)}
            />
          )}
        </Modal>
      ) : null}

      {duplicating ? (
        <Modal
          close={() => setDuplicating(null)}
          intent="create"
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
          intent="create"
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
          intent="archive"
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
          intent="detail"
          title={t('productModels.periods.title', { name: inspecting.name })}
        >
          <ProductModelPeriodTable name={inspecting.name} rules={inspecting.rules} />
        </Modal>
      ) : null}

      {models.isPending && models.data === undefined ? (
        <ProductModelsPageSkeleton status={t('productModels.loading')} />
      ) : (models.isError && !models.data) || items.length === 0 ? (
        <ProductModelsState
          kind={unauthorized ? 'unauthorized' : models.isError ? 'error' : 'empty'}
          onCreate={() => openEditor('from-product')}
          onRetry={() => void models.refetch()}
        />
      ) : (
        <IncrementalList items={items} query={models}>
          {(visibleItems) => (
            <ProductModelList
              models={visibleItems}
              onAddPeriod={openAddPeriod}
              onArchive={openArchive}
              onDuplicate={openDuplicate}
              onInspect={setInspecting}
            />
          )}
        </IncrementalList>
      )}
    </div>
  );
}
