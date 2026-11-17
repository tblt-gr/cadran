import type { Recurrence } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { CategoryGlyph } from '@/features/categories/category-glyph/CategoryGlyph';
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
      lead={
        category ? (
          <span className={styles.glyph}>
            <CategoryGlyph
              color={category.effectiveColor}
              icon={category.effectiveIcon}
              label={category.label}
            />
          </span>
        ) : null
      }
      amount={
        <RecurrenceAmount
          assetCode={recurrence.expectedAmount.assetCode}
          value={recurrence.expectedAmount.value}
        />
      }
      meta={
        <span className={styles.facts}>
          <span>{t(`recurrences.intervals.${recurrence.intervalKind}`)}</span>
          <span className={styles.next}>
            {t('recurrences.confirmed.next', {
              date: formatCalendarDay(recurrence.nextExpectedOn, i18n.language),
            })}
          </span>
        </span>
      }
      title={recurrence.label}
      titleEnd={
        recurrence.autoCreate ? (
          <span
            aria-label={t('recurrences.confirmed.automatic')}
            className={styles.glyph}
            role="img"
            title={t('recurrences.confirmed.automatic')}
          >
            <Icon name="repeat" size={14} />
          </span>
        ) : null
      }
    />
  );
}
