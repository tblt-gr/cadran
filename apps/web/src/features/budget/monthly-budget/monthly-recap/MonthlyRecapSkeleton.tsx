import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import {
  SkeletonBlock,
  SkeletonStatus,
  SkeletonTableRows,
} from '@/components/ui/skeleton/Skeleton';
import accountStyles from './account-recap/AccountRecap.module.css';
import totalsStyles from './totals-card/TotalsCard.module.css';
import wealthStyles from './wealth-card/WealthCard.module.css';
import styles from './MonthlyRecap.module.css';

const WEALTH_LABELS = [
  'budget.monthly.recap.wealth.previous',
  'budget.monthly.recap.wealth.current',
  'budget.monthly.recap.wealth.difference',
  'budget.monthly.recap.wealth.changePercent',
] as const;

const TOTAL_LABELS = [
  'reports.metrics.cashIncome',
  'reports.metrics.budgetExpenses',
  'reports.metrics.savingsInflows',
  'reports.metrics.savingsWithdrawals',
  'reports.metrics.netSavingsTransfers',
  'reports.metrics.netSavingsRate',
] as const;

/** One TotalsCard row: the metric name and its explain button are real, the value pending. */
function PendingMetricRow({ label }: { label?: string }) {
  const { t } = useTranslation();
  return (
    <li className={totalsStyles.row}>
      <div>
        <span className={totalsStyles.label}>
          {label === undefined ? (
            <SkeletonBlock className={styles.skeletonLabel} />
          ) : (
            <>
              <span>{label}</span>
              <InfoButton disabled label={t('reports.explainKpi', { kpi: label })} />
            </>
          )}
        </span>
        <SkeletonBlock className={styles.skeletonAmount} />
      </div>
    </li>
  );
}

/** Mirrors TotalsCard element for element; MonthlyRecap also shows it while preferences load. */
export function TotalsCardPlaceholder() {
  const { t } = useTranslation();
  return (
    <section className={`card ${totalsStyles.panel}`}>
      <header className={totalsStyles.heading}>
        <div className={totalsStyles.headingTitle}>
          <div className={totalsStyles.titleGroup}>
            <h3>{t('budget.monthly.recap.totals.title')}</h3>
            <InfoButton disabled label={t('budget.monthly.recap.info.open')} />
          </div>
          <button className="secondary-action" disabled type="button">
            {t('budget.monthly.recap.preferences.open')}
          </button>
        </div>
      </header>
      <ul className={totalsStyles.rows}>
        {TOTAL_LABELS.map((label) => (
          <PendingMetricRow key={label} label={t(label)} />
        ))}
      </ul>
      <h4>{t('budget.monthly.recap.totals.axes')}</h4>
      <ul className={totalsStyles.rows}>
        <PendingMetricRow />
        <PendingMetricRow />
      </ul>
      <h4>{t('budget.monthly.recap.totals.categories')}</h4>
      <ul className={totalsStyles.rows}>
        <PendingMetricRow />
        <PendingMetricRow />
        <PendingMetricRow />
      </ul>
    </section>
  );
}

/**
 * Mirrors MonthlyRecap element for element. Without `status` it makes no
 * announcement, so a page skeleton can embed it silently.
 */
export function MonthlyRecapPlaceholder({ status }: { status?: string }) {
  const { t } = useTranslation();
  const accountColumns = [
    t('budget.monthly.recap.accounts.account'),
    t('budget.monthly.recap.accounts.previousShort'),
    t('budget.monthly.recap.accounts.currentShort'),
    t('budget.monthly.recap.accounts.share'),
  ];

  return (
    <aside
      aria-busy={status === undefined ? undefined : true}
      aria-label={t('budget.monthly.recap.label')}
      className={styles.recap}
      data-testid={status === undefined ? undefined : 'monthly-recap-skeleton'}
    >
      {status === undefined ? null : <SkeletonStatus>{status}</SkeletonStatus>}
      <div className={styles.wealth}>
        <section className={`card ${wealthStyles.panel}`}>
          <h3>{t('budget.monthly.recap.wealth.title')}</h3>
          <dl className={wealthStyles.figures}>
            {WEALTH_LABELS.map((label) => (
              <div key={label}>
                <dt>{t(label)}</dt>
                <dd>
                  <SkeletonBlock className={styles.skeletonValue} />
                </dd>
              </div>
            ))}
          </dl>
        </section>
      </div>
      <div className={styles.totals}>
        <TotalsCardPlaceholder />
      </div>
      <div className={styles.accounts}>
        <section className={`card ${accountStyles.panel}`}>
          <h3>{t('budget.monthly.recap.accounts.title')}</h3>
          <div className={accountStyles.tableScroll}>
            <table>
              <caption className="sr-only">{t('budget.monthly.recap.accounts.caption')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('budget.monthly.recap.accounts.account')}</th>
                  <th scope="col">
                    <SkeletonBlock className={styles.skeletonAmount} />
                  </th>
                  <th scope="col">
                    <SkeletonBlock className={styles.skeletonAmount} />
                  </th>
                  <th scope="col">{t('budget.monthly.recap.accounts.share')}</th>
                </tr>
              </thead>
              <SkeletonTableRows labels={accountColumns} rows={4} />
            </table>
          </div>
        </section>
      </div>
    </aside>
  );
}

export function MonthlyRecapSkeleton({ status }: { status: string }) {
  return <MonthlyRecapPlaceholder status={status} />;
}
