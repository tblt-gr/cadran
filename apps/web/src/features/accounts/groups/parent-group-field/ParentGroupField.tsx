import type { AccountGroup } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { listAllAccountGroups } from '@/features/accounts/groups/listAllAccountGroups';
import styles from './ParentGroupField.module.css';

interface ParentGroupFieldProps {
  currentId?: string;
  onChange: (parentId: string, parent: AccountGroup | null) => void;
  value: string;
}

export function ParentGroupField({ currentId, onChange, value }: ParentGroupFieldProps) {
  const { t } = useTranslation();
  const candidates = useQuery({
    queryKey: ['account-group-parent-candidates'],
    queryFn: ({ signal }) => listAllAccountGroups(false, signal, true),
    retry: false,
  });
  const parents = (candidates.data ?? []).filter((group) => group.id !== currentId);
  const loading = undefined === candidates.data && !candidates.isError;

  return (
    <label className={styles.field}>
      <span>{t('accountGroups.fields.parent')}</span>
      <select
        disabled={loading || candidates.isError}
        onChange={(event) =>
          onChange(
            event.target.value,
            parents.find((parent) => parent.id === event.target.value) ?? null,
          )
        }
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
