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
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { CategoryForm } from './category-form/CategoryForm';
import { CategoryList } from './category-list/CategoryList';
import styles from './CategoryPage.module.css';

class CategoryRequestError extends Error {
  readonly status: number;

  constructor(status: number) {
    super(`Category request failed with status ${status}.`);
    this.status = status;
  }
}

type Editor = Category | 'create' | null;

export function CategoryPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [includeArchived, setIncludeArchived] = useState(false);
  const [page, setPage] = useState(1);
  const [editor, setEditor] = useState<Editor>(null);
  const [saved, setSaved] = useState(false);

  function openEditor(target: Exclude<Editor, null>) {
    setSaved(false);
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
        throw new CategoryRequestError(result.response?.status ?? 0);
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
        throw new CategoryRequestError(result.response?.status ?? 0);
      }
      return result.data;
    },
    onSuccess: async () => {
      setEditor(null);
      setSaved(true);
      await queryClient.invalidateQueries({ queryKey: ['categories'] });
    },
  });

  const submitError =
    save.error instanceof CategoryRequestError
      ? save.error.status === 409
        ? 'conflict'
        : save.error.status === 422
          ? 'invalid'
          : 'network'
      : save.isError
        ? 'network'
        : null;
  const unauthorized =
    categories.error instanceof CategoryRequestError && categories.error.status === 401;
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
          <h2 id="category-intro-title">{t('categories.title')}</h2>
          <span>{t('categories.description')}</span>
        </div>
        <button className="primary-action" onClick={() => openEditor('create')} type="button">
          {t('categories.add')}
        </button>
      </section>

      {saved ? (
        <p className={styles.success} role="status">
          {t('categories.saved')}
        </p>
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
        <CategoryList categories={items} onEdit={openEditor} />
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
