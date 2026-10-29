import type { GoalVersionHistoryItem } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatGoalMonth } from '@/features/goals/goalMonth';
import { FIELD_LABELS, useGoalVersionValue } from './goalVersionValue';
import styles from './GoalHistory.module.css';

interface Props {
  assetCode: string;
  item: GoalVersionHistoryItem;
  /** The latest version opens by itself; older ones stay folded until asked for. */
  initiallyOpen: boolean;
}

/** One recorded version: month and kind of correction in the summary, the rest folded away. */
export function HistoryVersion({ assetCode, item, initiallyOpen }: Props) {
  const { i18n, t } = useTranslation();
  const [open, setOpen] = useState(initiallyOpen);
  const format = useGoalVersionValue(assetCode);
  const recordedOn = new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium' }).format(
    new Date(item.createdAt),
  );
  return (
    <li className={styles.version}>
      <Disclosure
        meta={
          <>
            {t('goals.history.appliesFrom', {
              month: formatGoalMonth(item.effectiveFrom, i18n.language),
            })}
            {item.retroactive ? (
              <StatusBadge icon="alert" tone="warning">
                {t('goals.history.retroactive')}
              </StatusBadge>
            ) : null}
          </>
        }
        onToggle={setOpen}
        open={open}
        title={t('goals.history.versionValue', { version: item.versionNo })}
      >
        <div className={styles.details}>
          <p className={styles.recorded}>{t('goals.history.recordedOn', { date: recordedOn })}</p>
          {item.retroactive && item.reason ? (
            <p className={styles.recorded}>
              {t('goals.history.reasonLine', { reason: item.reason })}
            </p>
          ) : null}
          {item.changes.length > 0 ? (
            <ul
              aria-label={t('goals.history.changesOf', { version: item.versionNo })}
              className={styles.changes}
            >
              {item.changes.map((change) => (
                <li key={change.field}>
                  <span>{t(FIELD_LABELS[change.field])}</span>
                  <span className={styles.values}>
                    <span className="sr-only">{t('goals.history.before')}</span>
                    {format(change.before, change.field)}
                    <span aria-hidden="true">→</span>
                    <span className="sr-only">{t('goals.history.after')}</span>
                    {format(change.after, change.field)}
                  </span>
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      </Disclosure>
    </li>
  );
}
