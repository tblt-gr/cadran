import {
  createCategory,
  listCategories,
  updateCategory,
  type Category,
  type CreateCategoryRequest,
  type UpdateCategoryRequest,
} from '@cadran/api-client';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BudgetNavigation } from '@/features/budget/budget-navigation/BudgetNavigation';
import { readBudgetContext } from '@/features/budget/budget-navigation/budgetNavigation';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { CategoryForm } from './category-form/CategoryForm';
import { CategoryLifecycleDialog } from './category-lifecycle/CategoryLifecycleDialog';
import {
  useCategoryLifecycle,
  type LifecycleOutcome,
} from './category-lifecycle/useCategoryLifecycle';
import { CategoryTree } from './category-tree/CategoryTree';
import { CategoryTreeSkeleton } from './category-tree/CategoryTreeSkeleton';
import { CategoryRequestError, categoryErrorKind, categoryRequestError } from './categoryError';
import styles from './CategoryPage.module.css';

const PAGE_SIZE = 100;
const MAX_PAGES = 5;

/** The workspace holds more categories than the tree can load: a partial tree would mislead. */
class PartialCategoryList extends Error {}

type Editor = Category | 'create' | null;

export function CategoryPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [includeArchived, setIncludeArchived] = useState(false);
  const [editor, setEditor] = useState<Editor>(null);
  const [saved, setSaved] = useState<LifecycleOutcome | 'saved' | null>(null);
  const lifecycle = useCategoryLifecycle(setSaved);

  function openEditor(target: Exclude<Editor, null>) {
    setSaved(null);
    save.reset();
    setEditor(target);
  }

  function closeEditor() {
    setEditor(null);
    save.reset();
  }

  const categories = useQuery({
    queryKey: ['categories', includeArchived],
    queryFn: async ({ signal }) => {
      // The tree needs every category of the workspace: it is bounded to 500, so at most 5 pages.
      const items: Category[] = [];
      let total = 0;
      for (let page = 1; page <= MAX_PAGES; page += 1) {
        const result = await listCategories({
          ...authApiOptions(),
          query: { includeArchived, page, pageSize: PAGE_SIZE },
          signal,
        });
        if (!result.response?.ok || !result.data) {
          throw categoryRequestError(result);
        }
        total = result.data.total;
        items.push(...result.data.items);
        if (result.data.items.length === 0 || items.length >= result.data.total) {
          break;
        }
      }
      if (items.length < total) {
        throw new PartialCategoryList();
      }
      return items;
    },
    placeholderData: keepPreviousData,
    retry: false,
  });

  const save = useMutation({
    mutationFn: async (body: CreateCategoryRequest | UpdateCategoryRequest) => {
      const result =
        editor && editor !== 'create'
          ? await withCsrfRetry(() =>
              updateCategory({
                ...authApiOptions(),
                path: { id: editor.id },
                body: body as UpdateCategoryRequest,
              }),
            )
          : await withCsrfRetry(() =>
              createCategory({ ...authApiOptions(), body: body as CreateCategoryRequest }),
            );

      if (!result.response?.ok || !result.data) {
        throw categoryRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      setEditor(null);
      setSaved('saved');
      // A category carries its colour and icon into every transaction row that
      // references it, so a recoloured category invalidates those rows too.
      await Promise.all(
        [['categories'], ['category-candidates'], ['transactions']].map((queryKey) =>
          queryClient.invalidateQueries({ queryKey }),
        ),
      );
    },
  });

  const submitError = categoryErrorKind(save.error, save.isError);
  const unauthorized =
    categories.error instanceof CategoryRequestError && categories.error.kind === 'unauthorized';
  const partial = categories.error instanceof PartialCategoryList;
  // A merged source only exists to redirect its history to the target: nothing can be done with it.
  const items = (categories.data ?? []).filter((item) => item.replacement?.kind !== 'MERGE');

  const budgetContext = readBudgetContext(window.location.search);

  return (
    <div className={styles.page}>
      <BudgetNavigation current="categories" {...budgetContext} />
      <HeaderActions>
        <button className="primary-action" onClick={() => openEditor('create')} type="button">
          <Icon name="add" size={18} />
          {t('categories.add')}
        </button>
        <button
          aria-label={t(includeArchived ? 'categories.hideArchived' : 'categories.showArchived')}
          aria-pressed={includeArchived}
          className="icon-button"
          onClick={() => setIncludeArchived((current) => !current)}
          title={t(includeArchived ? 'categories.hideArchived' : 'categories.showArchived')}
          type="button"
        >
          <Icon name="archive" size={18} />
        </button>
        <a
          className="secondary-action"
          href="/transactions/categories/rules"
          onClick={(event) => handleClientNavigation(event, '/transactions/categories/rules')}
        >
          <Icon name="sliders" size={18} />
          {t('categories.manageRules')}
        </a>
      </HeaderActions>

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>
          {t(saved === 'saved' ? 'categories.saved' : `categories.lifecycle.${saved}`)}
        </Toast>
      ) : null}

      {editor ? (
        <Modal
          close={closeEditor}
          intent={editor === 'create' ? 'create' : 'edit'}
          title={t(
            editor === 'create' ? 'categories.form.createTitle' : 'categories.form.editTitle',
          )}
        >
          <CategoryForm
            category={editor === 'create' ? undefined : editor}
            key={editor === 'create' ? 'create' : editor.id}
            onCancel={closeEditor}
            onSubmit={(body) => save.mutate(body)}
            pending={save.isPending}
            submitError={submitError}
          />
        </Modal>
      ) : null}

      {lifecycle.target ? (
        <Modal
          close={lifecycle.close}
          intent={
            lifecycle.target.operation === 'ARCHIVE'
              ? 'archive'
              : lifecycle.target.operation === 'DELETE'
                ? 'delete'
                : 'confirm'
          }
          title={t(`categories.lifecycle.${lifecycle.target.operation}.title`)}
        >
          <CategoryLifecycleDialog
            category={lifecycle.target.category}
            key={`${lifecycle.target.category.id}-${lifecycle.target.operation}`}
            onCancel={lifecycle.close}
            onConfirm={(confirmation) => lifecycle.apply.mutate(confirmation)}
            operation={lifecycle.target.operation}
            pending={lifecycle.apply.isPending}
            submitError={lifecycle.apply.error}
            submitFailed={lifecycle.apply.isError}
          />
        </Modal>
      ) : null}

      {categories.isPending ? (
        <CategoryTreeSkeleton status={t('categories.loading')} />
      ) : categories.isError ? (
        <section className={`card ${styles.state}`} role="alert">
          <h2>{t(unauthorized ? 'categories.unauthorized.title' : 'categories.error.title')}</h2>
          <p>
            {t(
              unauthorized
                ? 'categories.unauthorized.description'
                : partial
                  ? 'categories.error.partial'
                  : 'categories.error.description',
            )}
          </p>
          {!unauthorized && !partial ? (
            <button
              className="secondary-action"
              onClick={() => void categories.refetch()}
              type="button"
            >
              {t('foundation.retry')}
            </button>
          ) : null}
        </section>
      ) : items.length === 0 ? (
        <section className={`card ${styles.state}`}>
          <h2>
            {t(includeArchived ? 'categories.emptyArchived.title' : 'categories.empty.title')}
          </h2>
          <p>
            {t(
              includeArchived
                ? 'categories.emptyArchived.description'
                : 'categories.empty.description',
            )}
          </p>
          {!includeArchived ? (
            <button className="primary-action" onClick={() => openEditor('create')} type="button">
              {t('categories.addFirst')}
            </button>
          ) : null}
        </section>
      ) : (
        <CategoryTree
          categories={items}
          onEdit={openEditor}
          onLifecycle={(category, operation) => {
            setSaved(null);
            lifecycle.open(category, operation);
          }}
        />
      )}
    </div>
  );
}
