import { listAccountGroups, type NetWorthShare } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { ShareCell } from '@/features/account-groups/share-cell/ShareCell';
import { authApiOptions } from '@/features/auth/apiOptions';
import styles from './GroupingFields.module.css';

interface GroupingFieldsProps {
  primaryGroupId: string;
  share?: NetWorthShare;
  tagGroupIds: string[];
  onPrimaryChange: (primaryGroupId: string) => void;
  onTagsChange: (tagGroupIds: string[]) => void;
}

export function GroupingFields({
  primaryGroupId,
  share,
  tagGroupIds,
  onPrimaryChange,
  onTagsChange,
}: GroupingFieldsProps) {
  const { t } = useTranslation();
  const groups = useQuery({
    queryKey: ['account-groups', 'account-form'],
    queryFn: async ({ signal }) => {
      const result = await listAccountGroups({
        ...authApiOptions(),
        query: { page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw new Error('Unable to load account groups.');
      }
      return result.data;
    },
    retry: false,
  });
  const items = groups.data?.items ?? [];
  const tagCandidates = items.filter((group) => group.id !== primaryGroupId);

  function toggleTag(id: string, checked: boolean) {
    onTagsChange(checked ? [...tagGroupIds, id] : tagGroupIds.filter((tag) => tag !== id));
  }

  return (
    <fieldset className={styles.fieldset}>
      <legend>{t('accounts.fields.grouping')}</legend>
      <p className={styles.hint}>{t('accounts.form.groupingHint')}</p>

      <label>
        <span>{t('accounts.fields.primaryGroup')}</span>
        <select
          disabled={groups.isPending || groups.isError}
          onChange={(event) => {
            const next = event.target.value;
            onPrimaryChange(next);
            onTagsChange(tagGroupIds.filter((tag) => tag !== next));
          }}
          value={primaryGroupId}
        >
          <option value="">{t('accounts.form.noGroup')}</option>
          {items.map((group) => (
            <option key={group.id} value={group.id}>
              {group.label}
            </option>
          ))}
        </select>
      </label>

      <fieldset className={styles.tags}>
        <legend>{t('accounts.fields.tags')}</legend>
        {groups.isPending ? (
          <p className={styles.hint} role="status">
            {t('accounts.form.groupsLoading')}
          </p>
        ) : groups.isError ? (
          <p role="alert">{t('accounts.form.groupsError')}</p>
        ) : tagCandidates.length === 0 ? (
          <p className={styles.hint}>{t('accounts.form.noTagCandidates')}</p>
        ) : (
          tagCandidates.map((group) => (
            <label key={group.id}>
              <input
                checked={tagGroupIds.includes(group.id)}
                onChange={(event) => toggleTag(group.id, event.target.checked)}
                type="checkbox"
              />
              <span>{group.label}</span>
            </label>
          ))
        )}
      </fieldset>

      {share ? (
        <p className={styles.share}>
          <span>{t('accounts.fields.share')}</span>
          <ShareCell share={share} />
        </p>
      ) : null}
    </fieldset>
  );
}
