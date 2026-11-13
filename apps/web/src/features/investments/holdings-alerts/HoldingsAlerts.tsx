import type { Holdings } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import {
  HoldingsWarnings,
  holdingsWarningMessages,
} from '@/features/investments/holdings-warnings/HoldingsWarnings';
import { UnsplitAccountsNotice } from '@/features/investments/unsplit-accounts-notice/UnsplitAccountsNotice';
import styles from './HoldingsAlerts.module.css';

/**
 * The notices of the read in one closed disclosure with their count. The bound of the aggregation
 * stays outside it: it changes what the totals cover.
 */
export function HoldingsAlerts({
  holdings,
  showUnsplit,
  showWarnings = true,
}: {
  holdings: Holdings;
  showUnsplit: boolean;
  /** Off where the read has no totals: its warnings qualify nothing then. */
  showWarnings?: boolean;
}) {
  const { t } = useTranslation();
  const unsplit = showUnsplit ? holdings.unsplitAccounts : [];
  const others = showWarnings
    ? holdingsWarningMessages(holdings, t).filter(({ code }) => code !== 'TRUNCATED_ACCOUNTS')
    : [];
  const count = others.length + unsplit.length;

  return (
    <>
      {showWarnings ? <HoldingsWarnings holdings={holdings} part="truncation" /> : null}
      {count === 0 ? null : (
        <details className={styles.alerts}>
          <summary>{t('investments.alerts.summary', { count })}</summary>
          {showWarnings ? <HoldingsWarnings holdings={holdings} part="others" /> : null}
          <UnsplitAccountsNotice accounts={unsplit} />
        </details>
      )}
    </>
  );
}
