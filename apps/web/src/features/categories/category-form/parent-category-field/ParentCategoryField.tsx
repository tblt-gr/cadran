import { listCategories, type CategoryType } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import styles from './ParentCategoryField.module.css';

interface ParentCategoryFieldProps {
  onChange: (parentId: string) => void;
  type: CategoryType;
  value: string;
}

export function ParentCategoryField({ onChange, type, value }: ParentCategoryFieldProps) {
  const { t } = useTranslation();
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebouncedValue(search.trim());
  const candidates = useQuery({
    queryKey: ['category-parent-candidates', type, debouncedSearch],
    queryFn: async ({ signal }) => {
      const result = await listCategories({
        ...authApiOptions(),
        query: {
          type,
          search: debouncedSearch || undefined,
          parentEligible: true,
          page: 1,
          perPage: 50,
        },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw new Error('Unable to load category parent candidates.');
      }
      return result.data;
    },
    placeholderData: (previous) => previous,
    retry: false,
  });
  const parents = candidates.data?.items ?? [];
  // A new search key restarts the query; keeping the previous page visible avoids the select
  // flickering unusable on every keystroke.
  const loading = undefined === candidates.data && !candidates.isError;

  return (
    <div className={styles.fields}>
      <label>
        <span>{t('categories.form.parentSearch')}</span>
        <input
          autoComplete="off"
          maxLength={80}
          onChange={(event) => {
            setSearch(event.target.value);
            onChange('');
          }}
          value={search}
        />
      </label>
      <label>
        <span>{t('categories.fields.parent')}</span>
        <select
          disabled={loading || candidates.isError}
          onChange={(event) => onChange(event.target.value)}
          value={value}
        >
          <option value="">{t('categories.form.noParent')}</option>
          {parents.map((parent) => (
            <option key={parent.id} value={parent.id}>
              {parent.label}
            </option>
          ))}
        </select>
        {loading ? (
          <small className={styles.hint} role="status">
            {t('categories.form.parentsLoading')}
          </small>
        ) : candidates.isError ? (
          <small role="alert">{t('categories.form.parentsError')}</small>
        ) : parents.length === 0 && debouncedSearch ? (
          <small className={styles.hint}>{t('categories.form.parentsEmpty')}</small>
        ) : null}
      </label>
    </div>
  );
}
