import type { Recurrence } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import styles from './RecurrenceCard.module.css';

interface RecurrenceCardProps {
  actions: ReactNode;
  recurrence: Recurrence;
}

export function RecurrenceCard({ actions, recurrence }: RecurrenceCardProps) {
  const { i18n, t } = useTranslation();

  return (
    <article className={`card ${styles.schedule}`}>
      <div>
        <h4>{recurrence.label}</h4>
        <p>
          {t(`recurrences.intervals.${recurrence.intervalKind}`)} ·{' '}
          {formatAmount(
            recurrence.expectedAmount.value,
            recurrence.expectedAmount.assetCode,
            i18n.language,
          )}
        </p>
        <small>
          {t('recurrences.confirmed.next', {
            date: formatCalendarDay(recurrence.nextExpectedOn, i18n.language),
          })}
        </small>
      </div>
      <div className={styles.scheduleActions}>{actions}</div>
    </article>
  );
}
