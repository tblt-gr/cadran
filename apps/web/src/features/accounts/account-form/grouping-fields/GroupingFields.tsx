import type { NetWorthShare } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { ShareCell } from '@/features/accounts/groups/share-cell/ShareCell';
import { orderGroupsDepthFirst } from '@/features/accounts/groups/groupPaths';
import { listAllAccountGroups } from '@/features/accounts/groups/listAllAccountGroups';
import styles from './GroupingFields.module.css';

interface GroupingFieldsProps {
  includeInNetWorth: boolean;
  invalid?: boolean;
  primaryGroupId: string;
  share?: NetWorthShare;
  onPrimaryChange: (primaryGroupId: string) => void;
}

export function GroupingFields({
  includeInNetWorth,
  invalid = false,
  primaryGroupId,
  share,
  onPrimaryChange,
}: GroupingFieldsProps) {
  const { t } = useTranslation();
  const groups = useQuery({
    queryKey: ['account-groups', 'account-form'],
    queryFn: ({ signal }) => listAllAccountGroups(false, signal),
    retry: false,
  });
  const items = useMemo(() => orderGroupsDepthFirst(groups.data ?? []), [groups.data]);
  const onlyGroupId = items.length === 1 ? items[0]?.group.id : undefined;

  useEffect(() => {
    if (includeInNetWorth && primaryGroupId === '' && onlyGroupId !== undefined) {
      onPrimaryChange(onlyGroupId);
    }
  }, [includeInNetWorth, onlyGroupId, onPrimaryChange, primaryGroupId]);

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
          onChange={(event) => onPrimaryChange(event.target.value)}
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
          {items.map(({ group, depth, parentLabel, path }) => (
            <option aria-label={path} key={group.id} value={group.id}>
              {'\u00a0\u00a0'.repeat(depth - 1) +
                (parentLabel === null ? group.label : `${group.label} (${parentLabel})`)}
            </option>
          ))}
        </select>
      </label>
      <p className={styles.hint}>{t('accounts.form.primaryGroupHelp')}</p>
      {invalid ? (
        <p className={styles.error} role="alert">
          {items.length === 0
            ? t('accounts.form.noGroupWhenIncluded')
            : t('accounts.validation.primaryGroup')}
        </p>
      ) : null}

      {groups.isError ? <p role="alert">{t('accounts.form.groupsError')}</p> : null}

      {share ? (
        <p className={styles.share}>
          <span>{t('accounts.fields.share')}</span>
          <ShareCell share={share} />
        </p>
      ) : null}
    </fieldset>
  );
}
