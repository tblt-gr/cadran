import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { CurveChartSkeleton } from '@/components/ui/charts/curve-chart/CurveChartSkeleton';
import { ChartPeriodControl } from '@/components/ui/chart-period-control/ChartPeriodControl';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { AccountHistoryTable } from './AccountHistoryTable';
import {
  historyKeys,
  showsManualPortfolioNotice,
  type HistoryAccount,
} from './accountHistoryLabels';
import { ACCOUNT_PERIODS } from './accountHistoryPeriods';
import styles from './AccountHistoryPanel.module.css';

/**
 * Mirrors AccountHistoryPanel element for element. Without the account (still loading) the
 * title is the generic balance one; the page skeleton announces the loading once.
 */
export function AccountHistoryPanelSkeleton({ account }: { account: HistoryAccount | null }) {
  const { t } = useTranslation();
  const id = useId();
  return (
    <section className={`card ${styles.card}`} aria-labelledby={id} aria-busy="true">
      <div className={styles.header}>
        <h3 id={id}>{t(historyKeys(account).title)}</h3>
        <ChartPeriodControl
          label={t('accounts.history.period')}
          disabled
          periods={ACCOUNT_PERIODS}
          value="LAST_12_MONTHS"
          onChange={() => undefined}
        />
      </div>
      {account && showsManualPortfolioNotice(account) ? (
        <p className={styles.notice}>{t('accounts.history.manualPortfolioNotice')}</p>
      ) : null}
      <CurveChartSkeleton />
      <Disclosure
        title={t('accounts.history.showTable')}
        disabled
        open={false}
        onToggle={() => undefined}
      >
        <AccountHistoryTable account={account} points={null} />
      </Disclosure>
    </section>
  );
}
