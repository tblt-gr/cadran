import type { HoldingsUnsplitAccount } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './UnsplitAccountsNotice.module.css';

/** Accounts counted in the total value whose securities have no per-instrument detail. */
export function UnsplitAccountsNotice({
  accounts,
}: {
  accounts: readonly HoldingsUnsplitAccount[];
}) {
  const { t } = useTranslation();
  if (accounts.length === 0) return null;

  return (
    <ul className={styles.list}>
      {accounts.map((account) => {
        const href = `/portfolios/${account.accountId}?tab=valuations`;
        return (
          <li className={`card ${styles.notice}`} key={account.accountId}>
            <span>{t('investments.unsplit.message', { label: account.label })}</span>
            <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
              {t('investments.unsplit.action')}
            </a>
          </li>
        );
      })}
    </ul>
  );
}
