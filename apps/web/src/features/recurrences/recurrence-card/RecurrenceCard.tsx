import type { Recurrence } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { CategoryIdentity } from '@/features/categories/category-identity/CategoryIdentity';
import { useRecurrenceCategory } from '@/features/recurrences/useRecurrenceCategory';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import styles from './RecurrenceCard.module.css';

interface RecurrenceCardProps {
  actions: ReactNode;
  recurrence: Recurrence;
}

export function RecurrenceCard({ actions, recurrence }: RecurrenceCardProps) {
  const { i18n, t } = useTranslation();
  const category = useRecurrenceCategory(recurrence.autoCreate ? recurrence.categoryId : null);

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
        {recurrence.autoCreate ? (
          <div className={styles.badges}>
            <StatusBadge icon="repeat" tone="info">
              {t('recurrences.confirmed.automatic')}
            </StatusBadge>
            {category ? (
              <CategoryIdentity
                color={category.effectiveColor}
                icon={category.effectiveIcon}
                label={category.label}
              />
            ) : null}
          </div>
        ) : null}
      </div>
      <div className={styles.scheduleActions}>{actions}</div>
    </article>
  );
}
