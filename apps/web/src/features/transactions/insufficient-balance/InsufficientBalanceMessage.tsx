import { useTranslation } from 'react-i18next';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './InsufficientBalanceMessage.module.css';

/** What was refused: an amount typed in a form, a snapshot withdrawal, or a switch to transaction valuation. */
type RefusedWrite = 'amount' | 'withdrawal' | 'reconfiguration';

const MESSAGE_KEYS = {
  amount: {
    dated: 'transactions.errors.insufficientBalance',
    undated: 'transactions.errors.insufficientBalanceUndated',
  },
  withdrawal: {
    dated: 'accounts.snapshots.withdrawalRefused',
    undated: 'accounts.snapshots.withdrawalRefusedUndated',
  },
  reconfiguration: {
    dated: 'accounts.editor.reconfigurationRefused',
    undated: 'accounts.editor.reconfigurationRefusedUndated',
  },
} as const;

interface InsufficientBalanceMessageProps {
  /** The first day the balance would be negative (ISO 8601), or `null` when the server did not say. */
  date: string | null;
  /** Neither a withdrawal nor a reconfiguration has an amount to blame; default is a typed amount. */
  write?: RefusedWrite;
}

const DATE_MARKUP = /^([^<]*)<date>([^<]*)<\/date>(.*)$/s;

/**
 * "Insufficient balance: this amount would put the account below zero on {date}." The date sits in
 * `<date>` tags in the dictionary so it can be set in the mono face without a rich-text component.
 */
export function InsufficientBalanceMessage({
  date,
  write = 'amount',
}: InsufficientBalanceMessageProps) {
  const { i18n, t } = useTranslation();
  const keys = MESSAGE_KEYS[write];

  if (date === null) {
    return t(keys.undated);
  }

  const text = t(keys.dated, {
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
