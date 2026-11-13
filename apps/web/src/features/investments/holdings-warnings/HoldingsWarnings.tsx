import type { Holdings } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import styles from './HoldingsWarnings.module.css';

/** Warnings of the read, one message each; the mixed valuation dates are told by the summary's date line instead. */
export function holdingsWarningMessages(
  holdings: Holdings,
  t: TFunction,
): { key: string; code: string; text: string }[] {
  const labels = new Map<string, string>();
  for (const item of holdings.items) {
    for (const account of item.accounts) labels.set(account.accountId, account.label);
  }
  for (const account of holdings.unsplitAccounts) labels.set(account.accountId, account.label);

  return holdings.warnings.flatMap((warning) => {
    if (warning.code === 'MIXED_VALUATION_DATES') return [];
    if (warning.code === 'POSITIONS_CHANGED') {
      return warning.accountIds.map((id) => ({
        key: `${warning.code}-${id}`,
        code: warning.code,
        text: t('investments.warnings.POSITIONS_CHANGED', { labels: labels.get(id) ?? id }),
      }));
    }
    return [
      { key: warning.code, code: warning.code, text: t(`investments.warnings.${warning.code}`) },
    ];
  });
}

/** `truncation` keeps the bound of the aggregation apart from the other warnings, which are grouped. */
export function HoldingsWarnings({
  holdings,
  part = 'all',
}: {
  holdings: Holdings;
  part?: 'all' | 'truncation' | 'others';
}) {
  const { t } = useTranslation();
  const messages = holdingsWarningMessages(holdings, t).filter(
    ({ code }) => part === 'all' || (code === 'TRUNCATED_ACCOUNTS') === (part === 'truncation'),
  );
  if (messages.length === 0) return null;

  return (
    <div role="status">
      <ul className={styles.list}>
        {messages.map((message) => (
          <li key={message.key}>{message.text}</li>
        ))}
      </ul>
    </div>
  );
}
