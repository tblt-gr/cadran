import { listAccountGroups } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import styles from './ParentGroupField.module.css';

interface ParentGroupFieldProps {
  currentId?: string;
  onChange: (parentId: string) => void;
  value: string;
}

export function ParentGroupField({ currentId, onChange, value }: ParentGroupFieldProps) {
  const { t } = useTranslation();
  const candidates = useQuery({
    queryKey: ['account-group-parent-candidates'],
    queryFn: async ({ signal }) => {
      const result = await listAccountGroups({
        ...authApiOptions(),
        query: { parentEligible: true, page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw new Error('Unable to load group parent candidates.');
      }
      return result.data;
    },
    retry: false,
  });
  const parents = (candidates.data?.items ?? []).filter((group) => group.id !== currentId);
  const loading = undefined === candidates.data && !candidates.isError;

  return (
    <label className={styles.field}>
      <span>{t('accountGroups.fields.parent')}</span>
      <select
        disabled={loading || candidates.isError}
        onChange={(event) => onChange(event.target.value)}
        value={value}
      >
        <option value="">{t('accountGroups.form.noParent')}</option>
        {parents.map((parent) => (
          <option key={parent.id} value={parent.id}>
            {parent.label}
          </option>
        ))}
      </select>
      {loading ? (
        <small className={styles.hint} role="status">
          {t('accountGroups.form.parentsLoading')}
        </small>
      ) : candidates.isError ? (
        <small role="alert">{t('accountGroups.form.parentsError')}</small>
      ) : null}
    </label>
  );
}
