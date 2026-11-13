import type { Recurrence } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { CategoryIdentity } from '@/features/categories/category-identity/CategoryIdentity';
import { RecurrenceAmount } from '@/features/recurrences/recurrence-row/RecurrenceAmount';
import styles from '@/features/recurrences/recurrence-row/RecurrenceRow.module.css';
import { RecurrenceRow } from '@/features/recurrences/recurrence-row/RecurrenceRow';
import { useRecurrenceCategory } from '@/features/recurrences/useRecurrenceCategory';
import { formatCalendarDay } from '@/lib/decimal';

interface RecurrenceCardProps {
  /** The row menu of the recurrence. */
  actions: ReactNode;
  recurrence: Recurrence;
}

/** One confirmed schedule as a line: label, rhythm, amount and next date, then its menu. */
export function RecurrenceCard({ actions, recurrence }: RecurrenceCardProps) {
  const { i18n, t } = useTranslation();
  const category = useRecurrenceCategory(recurrence.autoCreate ? recurrence.categoryId : null);

  return (
    <RecurrenceRow
      actions={actions}
      aside={
        recurrence.autoCreate ? (
          <>
            {category ? (
              <CategoryIdentity
                color={category.effectiveColor}
                icon={category.effectiveIcon}
                label={category.label}
              />
            ) : null}
            <StatusBadge icon="repeat" tone="info">
              {t('recurrences.confirmed.automatic')}
            </StatusBadge>
          </>
        ) : null
      }
      meta={
        <>
          {t(`recurrences.intervals.${recurrence.intervalKind}`)} ·{' '}
          <RecurrenceAmount
            assetCode={recurrence.expectedAmount.assetCode}
            value={recurrence.expectedAmount.value}
          />{' '}
          ·{' '}
          <span className={styles.next}>
            {t('recurrences.confirmed.next', {
              date: formatCalendarDay(recurrence.nextExpectedOn, i18n.language),
            })}
          </span>
        </>
      }
      title={recurrence.label}
    />
  );
}
