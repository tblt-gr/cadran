import { listAccountGroups, type NetWorthShare } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { ShareCell } from '@/features/account-groups/share-cell/ShareCell';
import { authApiOptions } from '@/features/auth/apiOptions';
import styles from './GroupingFields.module.css';

interface GroupingFieldsProps {
  includeInNetWorth: boolean;
  invalid?: boolean;
  primaryGroupId: string;
  share?: NetWorthShare;
  tagGroupIds: string[];
  onPrimaryChange: (primaryGroupId: string) => void;
  onTagsChange: (tagGroupIds: string[]) => void;
}

export function GroupingFields({
  includeInNetWorth,
  invalid = false,
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
  const onlyGroupId = items.length === 1 ? items[0]?.id : undefined;

  useEffect(() => {
    if (includeInNetWorth && primaryGroupId === '' && onlyGroupId !== undefined) {
      onPrimaryChange(onlyGroupId);
    }
  }, [includeInNetWorth, onlyGroupId, onPrimaryChange, primaryGroupId]);

  function toggleTag(id: string, checked: boolean) {
    if (id === primaryGroupId) {
      return;
    }

    onTagsChange(checked ? [...tagGroupIds, id] : tagGroupIds.filter((tag) => tag !== id));
  }

  return (
    <fieldset className={styles.fieldset}>
      <legend>{t('accounts.fields.grouping')}</legend>
      <p className={styles.hint}>
        {t(includeInNetWorth ? 'accounts.form.groupingHint' : 'accounts.form.groupingHintExcluded')}
      </p>

      <label>
        <span>
          {t(
            includeInNetWorth
              ? 'accounts.fields.primaryGroupRequired'
              : 'accounts.fields.primaryGroup',
          )}
        </span>
        <select
          aria-invalid={invalid ? true : undefined}
          disabled={groups.isPending || groups.isError}
          onChange={(event) => {
            const next = event.target.value;
            onPrimaryChange(next);
            onTagsChange(tagGroupIds.filter((tag) => tag !== next));
          }}
          required={includeInNetWorth}
          value={primaryGroupId}
        >
          {includeInNetWorth ? (
            primaryGroupId === '' ? (
              <option value="">{t('accounts.form.chooseGroup')}</option>
            ) : null
          ) : (
            <option value="">{t('accounts.form.noGroup')}</option>
          )}
          {items.map((group) => (
            <option key={group.id} value={group.id}>
              {group.label}
            </option>
          ))}
        </select>
      </label>
      {invalid ? (
        <p className={styles.error} role="alert">
          {items.length === 0
            ? t('accounts.form.noGroupWhenIncluded')
            : t('accounts.validation.primaryGroup')}
        </p>
      ) : null}

      <fieldset className={styles.tags}>
        <legend>{t('accounts.fields.tags')}</legend>
        {groups.isPending ? (
          <p className={styles.hint} role="status">
            {t('accounts.form.groupsLoading')}
          </p>
        ) : groups.isError ? (
          <p role="alert">{t('accounts.form.groupsError')}</p>
        ) : items.length === 0 ? (
          <p className={styles.hint}>{t('accounts.form.noTagCandidates')}</p>
        ) : (
          <>
            {items.map((group) => {
              const isPrimary = group.id === primaryGroupId;

              return (
                <label key={group.id}>
                  <input
                    checked={isPrimary || tagGroupIds.includes(group.id)}
                    disabled={isPrimary}
                    onChange={(event) => toggleTag(group.id, event.target.checked)}
                    type="checkbox"
                  />
                  <span>
                    {group.label}
                    {isPrimary ? ` — ${t('accounts.form.tagIsPrimary')}` : ''}
                  </span>
                </label>
              );
            })}
            {tagCandidates.length === 0 ? (
              <p className={styles.hint}>{t('accounts.form.needAnotherGroupForTag')}</p>
            ) : null}
          </>
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
