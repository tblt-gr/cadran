import { useTranslation } from 'react-i18next';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './InsufficientBalanceMessage.module.css';

interface InsufficientBalanceMessageProps {
  /** The first day the balance would be negative (ISO 8601), or `null` when the server did not say. */
  date: string | null;
}

const DATE_MARKUP = /^([^<]*)<date>([^<]*)<\/date>(.*)$/s;

/**
 * "Insufficient balance: this amount would put the account below zero on {date}." The date sits in
 * `<date>` tags in the dictionary so it can be set in the mono face without a rich-text component.
 */
export function InsufficientBalanceMessage({ date }: InsufficientBalanceMessageProps) {
  const { i18n, t } = useTranslation();

  if (date === null) {
    return t('transactions.errors.insufficientBalanceUndated');
  }

  const text = t('transactions.errors.insufficientBalance', {
    date: formatCalendarDay(date, i18n.language),
  });
  const parts = DATE_MARKUP.exec(text);
  if (parts === null) {
    return text;
  }

  return (
    <>
      {parts[1]}
      <time className={styles.date} dateTime={date}>
        {parts[2]}
      </time>
      {parts[3]}
    </>
  );
}
