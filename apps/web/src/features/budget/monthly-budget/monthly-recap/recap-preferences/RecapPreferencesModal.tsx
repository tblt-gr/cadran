import {
  listCategories,
  type AnalyticAxes,
  type MonthlyRecapPreferences,
} from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { budgetErrorKind } from '@/features/budget/budgetError';
import { useSaveRecapPreferences } from '@/features/budget/monthly-budget/monthly-recap/useMonthlyRecap';
import styles from './RecapPreferencesModal.module.css';

const axes: AnalyticAxes = [
  'DISCRETIONARY',
  'ESSENTIAL',
  'FIXED',
  'PERSONAL',
  'PROFESSIONAL',
  'VARIABLE',
];
const CATEGORY_PAGE_SIZE = 100;
const MAX_VISIBLE_CATEGORIES = 100;

interface RecapPreferencesModalProps {
  close: () => void;
  onReload: () => void;
  preferences: MonthlyRecapPreferences;
  representedCategoryIds: string[];
}

export function RecapPreferencesModal({
  close,
  onReload,
  preferences,
  representedCategoryIds,
}: RecapPreferencesModalProps) {
  const { t } = useTranslation();
  const save = useSaveRecapPreferences();
  const categories = useQuery({
    queryKey: ['categories', 'recap-preferences', representedCategoryIds],
    queryFn: async ({ signal }) => {
      const items = [];
      let total = 0;

      for (let page = 1; page === 1 || items.length < total; page += 1) {
        const result = await listCategories({
          ...authApiOptions(),
          query: { includeArchived: true, page, perPage: CATEGORY_PAGE_SIZE },
          signal,
        });
        if (!result.response?.ok || !result.data) throw new Error('recap-categories');

        total = result.data.total;
        items.push(...result.data.items);
        if (result.data.items.length === 0 && items.length < total) {
          throw new Error('recap-categories');
        }
      }

      return items.filter((category) => representedCategoryIds.includes(category.id));
    },
    retry: false,
  });
  const [categoryIds, setCategoryIds] = useState<string[]>(preferences.visibleCategoryIds);
  const [visibleAxes, setVisibleAxes] = useState<AnalyticAxes>(preferences.visibleAxes);
  const error = budgetErrorKind(save.error, save.isError);
  const stale = error === 'conflict';

  function reloadAfterStale() {
    save.reset();
    onReload();
  }

  function toggle(values: string[], value: string, update: (next: string[]) => void) {
    update(values.includes(value) ? values.filter((item) => item !== value) : [...values, value]);
  }
  function submit(event: React.FormEvent) {
    event.preventDefault();
    save.mutate(
      { visibleCategoryIds: categoryIds, visibleAxes, version: preferences.version },
      { onSuccess: close },
    );
  }
  return (
    <Modal
      close={close}
      eyebrow={t('budget.monthly.recap.preferences.eyebrow')}
      title={t('budget.monthly.recap.preferences.title')}
    >
      <form className={styles.form} onSubmit={submit}>
        {stale ? (
          <div className={styles.alert} role="alert">
            <p>{t('budget.monthly.recap.preferences.stale')}</p>
            <button className="secondary-action" onClick={reloadAfterStale} type="button">
              {t('budget.monthly.recap.preferences.reload')}
            </button>
          </div>
        ) : null}
        {error && !stale ? (
          <p className={styles.alert} role="alert">
            {t(`budget.errors.${error}`)}
          </p>
        ) : null}
        <fieldset disabled={categories.isPending || save.isPending}>
          <legend>{t('budget.monthly.recap.preferences.categories')}</legend>
          {categories.isPending ? (
            <p role="status">{t('budget.monthly.recap.preferences.loading')}</p>
          ) : categories.isError ? (
            <div className={styles.alert} role="alert">
              <p>{t('budget.monthly.recap.preferences.categoriesError')}</p>
              <button
                className="secondary-action"
                onClick={() => void categories.refetch()}
                type="button"
              >
                {t('foundation.retry')}
              </button>
            </div>
          ) : categories.data?.length ? (
            <div className={styles.options}>
              {categories.data.map((category) => (
                <label key={category.id}>
                  <input
                    checked={categoryIds.includes(category.id)}
                    disabled={
                      !categoryIds.includes(category.id) &&
                      categoryIds.length >= MAX_VISIBLE_CATEGORIES
                    }
                    onChange={() => toggle(categoryIds, category.id, setCategoryIds)}
                    type="checkbox"
                  />
                  {category.label}
                </label>
              ))}
            </div>
          ) : (
            <p>{t('budget.monthly.recap.preferences.categoriesEmpty')}</p>
          )}
        </fieldset>
        <fieldset disabled={save.isPending}>
          <legend>{t('budget.monthly.recap.preferences.axes')}</legend>
          <div className={styles.options}>
            {axes.map((axis) => (
              <label key={axis}>
                <input
                  checked={visibleAxes.includes(axis)}
                  onChange={() =>
                    toggle(visibleAxes, axis, (next) => setVisibleAxes(next as AnalyticAxes))
                  }
                  type="checkbox"
                />
                {t(`budget.monthly.axes.${axis}`)}
              </label>
            ))}
          </div>
        </fieldset>
        <p className={styles.hint}>{t('budget.monthly.recap.preferences.emptyHint')}</p>
        <div className={styles.actions}>
          <button className="secondary-action" onClick={close} type="button">
            {t('actions.cancel')}
          </button>
          <button
            className="primary-action"
            disabled={save.isPending || categories.isPending || stale}
            type="submit"
          >
            {t(
              save.isPending
                ? 'budget.monthly.recap.preferences.saving'
                : 'budget.monthly.recap.preferences.save',
            )}
          </button>
        </div>
      </form>
    </Modal>
  );
}
