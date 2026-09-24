import {
  createCategory,
  listCategories,
  updateCategory,
  type Category,
  type CreateCategoryRequest,
  type UpdateCategoryRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { CategoryForm } from './category-form/CategoryForm';
import { CategoryLifecycleDialog } from './category-lifecycle/CategoryLifecycleDialog';
import { useCategoryLifecycle } from './category-lifecycle/useCategoryLifecycle';
import { CategoryList } from './category-list/CategoryList';
import { CategoryRequestError, categoryErrorKind, categoryRequestError } from './categoryError';
import styles from './CategoryPage.module.css';

type Editor = Category | 'create' | null;

export function CategoryPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [includeArchived, setIncludeArchived] = useState(false);
  const [page, setPage] = useState(1);
  const [editor, setEditor] = useState<Editor>(null);
  const [saved, setSaved] = useState<'applied' | 'saved' | null>(null);
  const lifecycle = useCategoryLifecycle(() => setSaved('applied'));

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
    queryKey: ['categories', includeArchived, page],
    queryFn: async ({ signal }) => {
      const result = await listCategories({
        ...authApiOptions(),
        query: { includeArchived, page, perPage: 50 },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw categoryRequestError(result);
      }
      return result.data;
    },
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
  const items = categories.data?.items ?? [];
  const totalPages = Math.max(1, Math.ceil((categories.data?.total ?? 0) / 50));

  // The result set can shrink under the current page (a concurrent archive, a refetch on focus).
  // React re-renders with the corrected page before committing, so the empty state is never shown
  // for a workspace that still has categories.
  if (categories.data && page > totalPages) {
    setPage(totalPages);
  }

  return (
    <div className={styles.page}>
      <section className={styles.intro} aria-labelledby="category-intro-title">
        <div>
          <p>{t('categories.eyebrow')}</p>
          <div className={styles.titleRow}>
            <a
              aria-label={t('categories.backToTransactions')}
              className={`icon-button ${styles.back}`}
              href="/transactions"
              onClick={(event) => handleClientNavigation(event, '/transactions')}
            >
              <Icon name="arrow-left" size={18} />
            </a>
            <h2 id="category-intro-title">{t('categories.title')}</h2>
          </div>
          <span>{t('categories.description')}</span>
        </div>
        <div className={styles.introActions}>
          <a
            className="secondary-action"
            href="/transactions/categories/rules"
            onClick={(event) => handleClientNavigation(event, '/transactions/categories/rules')}
          >
            {t('categories.manageRules')}
          </a>
          <button className="primary-action" onClick={() => openEditor('create')} type="button">
            {t('categories.add')}
          </button>
        </div>
      </section>

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>
          {t(saved === 'applied' ? 'categories.lifecycle.applied' : 'categories.saved')}
        </Toast>
      ) : null}

      {editor ? (
        <Modal
          close={closeEditor}
          eyebrow={t(
            editor === 'create' ? 'categories.form.createEyebrow' : 'categories.form.editEyebrow',
          )}
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
          eyebrow={t(`categories.lifecycle.${lifecycle.target.operation}.eyebrow`)}
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

      <div className={styles.toolbar}>
        <label>
          <input
            checked={includeArchived}
            onChange={(event) => {
              setIncludeArchived(event.target.checked);
              setPage(1);
            }}
            type="checkbox"
          />
          <span>{t('categories.includeArchived')}</span>
        </label>
      </div>

      {categories.isPending ? (
        <section className={`card ${styles.state}`} aria-busy="true" role="status">
          <h2>{t('categories.loading')}</h2>
        </section>
      ) : categories.isError ? (
        <section className={`card ${styles.state}`} role="alert">
          <h2>{t(unauthorized ? 'categories.unauthorized.title' : 'categories.error.title')}</h2>
          <p>
            {t(
              unauthorized ? 'categories.unauthorized.description' : 'categories.error.description',
            )}
          </p>
          {!unauthorized ? (
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
        <CategoryList
          categories={items}
          onEdit={openEditor}
          onLifecycle={(category, operation) => {
            setSaved(null);
            lifecycle.open(category, operation);
          }}
        />
      )}

      {categories.isSuccess && (totalPages > 1 || page > 1) ? (
        <nav className={styles.pagination} aria-label={t('categories.pagination.label')}>
          <button
            className="secondary-action"
            disabled={page === 1}
            onClick={() => setPage((current) => current - 1)}
            type="button"
          >
            {t('categories.pagination.previous')}
          </button>
          <span>{t('categories.pagination.position', { page, total: totalPages })}</span>
          <button
            className="secondary-action"
            disabled={page >= totalPages}
            onClick={() => setPage((current) => current + 1)}
            type="button"
          >
            {t('categories.pagination.next')}
          </button>
        </nav>
      ) : null}
    </div>
  );
}
