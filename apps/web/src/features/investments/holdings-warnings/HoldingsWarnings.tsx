import type { Holdings } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './HoldingsWarnings.module.css';

/** Warnings of the read; the mixed valuation dates are told by the summary's date line instead. */
export function HoldingsWarnings({ holdings }: { holdings: Holdings }) {
  const { t } = useTranslation();
  const labels = new Map<string, string>();
  for (const item of holdings.items) {
    for (const account of item.accounts) labels.set(account.accountId, account.label);
  }
  for (const account of holdings.unsplitAccounts) labels.set(account.accountId, account.label);

  const messages = holdings.warnings.flatMap((warning) => {
    if (warning.code === 'MIXED_VALUATION_DATES') return [];
    if (warning.code === 'POSITIONS_CHANGED') {
      return warning.accountIds.map((id) => ({
        key: `${warning.code}-${id}`,
        text: t('investments.warnings.POSITIONS_CHANGED', { labels: labels.get(id) ?? id }),
      }));
    }
    return [{ key: warning.code, text: t(`investments.warnings.${warning.code}`) }];
  });
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
