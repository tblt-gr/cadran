import type { GoalVersionHistoryItem } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatGoalMonth } from '@/features/goals/goalMonth';
import { FIELD_LABELS, useGoalVersionValue } from './goalVersionValue';
import styles from './GoalHistory.module.css';

/** The column headers, shared with the skeleton so both read the same. */
export function GoalHistoryHead() {
  const { t } = useTranslation();
  return (
    <thead>
      <tr>
        <th scope="col">{t('goals.history.version')}</th>
        <th scope="col">{t('goals.history.modifiedOn')}</th>
        <th scope="col">{t('goals.history.effectiveFrom')}</th>
        <th scope="col">{t('goals.history.evolution')}</th>
      </tr>
    </thead>
  );
}

/** Versions of a goal, newest first, always visible: number, date, first month, value evolution. */
export function GoalHistoryTable({
  assetCode,
  items,
}: {
  assetCode: string;
  items: GoalVersionHistoryItem[];
}) {
  const { i18n, t } = useTranslation();
  const format = useGoalVersionValue(assetCode);
  const dash = <span aria-label={t('goals.notCalculable')}>-</span>;
  return (
    <div className={styles.tableWrap}>
      <table className={styles.table}>
        <caption>{t('goals.history.caption')}</caption>
        <GoalHistoryHead />
        <tbody>
          {items.map((item) => (
            <tr key={item.versionNo}>
              <th data-label={t('goals.history.version')} scope="row">
                {item.versionNo}
              </th>
              <td data-label={t('goals.history.modifiedOn')}>
                {new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium' }).format(
                  new Date(item.createdAt),
                )}
              </td>
              <td data-label={t('goals.history.effectiveFrom')}>
                {formatGoalMonth(item.effectiveFrom, i18n.language)}
                {item.retroactive ? (
                  <StatusBadge icon="alert" tone="warning">
                    {t('goals.history.retroactive')}
                  </StatusBadge>
                ) : null}
                {item.retroactive && item.reason ? (
                  <small>{t('goals.history.reasonLine', { reason: item.reason })}</small>
                ) : null}
              </td>
              <td data-label={t('goals.history.evolution')}>
                {item.changes.length === 0 ? (
                  dash
                ) : (
                  <ul className={styles.changes}>
                    {item.changes.map((change) => (
                      <li key={change.field}>
                        <span className="sr-only">{t(FIELD_LABELS[change.field])} : </span>
                        {format(change.before, change.field)}
                        <span aria-hidden="true"> → </span>
                        <span className="sr-only">{t('goals.history.after')} : </span>
                        {format(change.after, change.field)}
                      </li>
                    ))}
                  </ul>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
