import { MetricLabelButton } from './totals-card/MetricLabelButton';
import { RECAP_KPIS } from './totals-card/recapKpis';
import { RecapSettingsButton } from './totals-card/RecapSettingsButton';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { GroupingModeToggle } from '@/features/accounts/grouping-mode/GroupingModeToggle';
import { AccountBalanceHeading } from './account-recap/AccountBalanceHeading';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import accountStyles from './account-recap/AccountRecap.module.css';
import totalsStyles from './totals-card/TotalsCard.module.css';
import { WealthCard } from './wealth-card/WealthCard';
import styles from './MonthlyRecap.module.css';

const TOTAL_LABELS = RECAP_KPIS.map((kpi) => `reports.metrics.${kpi}` as const);

/** One TotalsCard row: the metric name and its explain button are real, the value pending. */
function PendingMetricRow({ label }: { label?: string }) {
  return (
    <li className={totalsStyles.row}>
      <div>
        <MetricLabelButton disabled label={label ?? null} />
        <MoneyValue skeletonClassName={totalsStyles.amountSkeleton} value={null} />
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
          </div>
          <RecapSettingsButton disabled />
        </div>
      </header>
      <ul className={totalsStyles.rows}>
        {TOTAL_LABELS.map((label) => (
          <PendingMetricRow key={label} label={t(label)} />
        ))}
      </ul>
      <section className={totalsStyles.section}>
        <h4>{t('budget.monthly.recap.totals.axes')}</h4>
        <ul className={totalsStyles.rows}>
          <PendingMetricRow />
          <PendingMetricRow />
        </ul>
      </section>
      <section className={totalsStyles.section}>
        <h4>{t('budget.monthly.recap.totals.categories')}</h4>
        <ul className={totalsStyles.rows}>
          <PendingMetricRow />
          <PendingMetricRow />
          <PendingMetricRow />
        </ul>
      </section>
    </section>
  );
}

/**
 * Mirrors MonthlyRecap element for element. Without `status` it makes no
 * announcement, so a page skeleton can embed it silently.
 */
export function MonthlyRecapPlaceholder({
  status,
  leading,
}: {
  status?: string;
  leading?: ReactNode;
}) {
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
      <div className={styles.column}>
        <div className={styles.wealth}>
          <WealthCard netWorth={null} provisional={false} />
        </div>
        <div className={styles.totals}>
          <TotalsCardPlaceholder />
        </div>
      </div>
      <div className={styles.column}>
        <div className={styles.accounts}>
          <section className={`card ${accountStyles.panel}`}>
            <div className={accountStyles.heading}>
              <div className={accountStyles.titleGroup}>
                <h3 id="monthly-recap-accounts-title">
                  {t('budget.monthly.recap.accounts.title')}
                </h3>
              </div>
              <GroupingModeToggle disabled mode="PRIMARY" onChange={() => undefined} />
            </div>
            <div className={accountStyles.tableScroll}>
              <table>
                <caption className="sr-only">{t('budget.monthly.recap.accounts.caption')}</caption>
                <thead>
                  <tr>
                    <th scope="col">{t('budget.monthly.recap.accounts.account')}</th>
                    <th scope="col">
                      <AccountBalanceHeading date={null} />
                    </th>
                    <th scope="col">
                      <AccountBalanceHeading date={null} />
                    </th>
                    <th className={accountStyles.share} scope="col">
                      {t('budget.monthly.recap.accounts.share')}
                    </th>
                  </tr>
                </thead>
                <tbody aria-hidden="true">
                  <tr className={accountStyles.groupRow}>
                    <th data-label={accountColumns[0]} scope="rowgroup">
                      <SkeletonBlock />
                    </th>
                    <td data-label={accountColumns[1]}>
                      <SkeletonBlock />
                    </td>
                    <td data-label={accountColumns[2]}>
                      <SkeletonBlock />
                    </td>
                    <td className={accountStyles.share} data-label={accountColumns[3]}>
                      <SkeletonBlock />
                    </td>
                  </tr>
                  {Array.from({ length: 3 }, (_, index) => (
                    <tr key={index}>
                      <th data-label={accountColumns[0]} scope="row">
                        <a>
                          <SkeletonBlock />
                        </a>
                        <small className={accountStyles.institution}>
                          <SkeletonBlock />
                        </small>
                      </th>
                      <td data-label={accountColumns[1]}>
                        <span>
                          <SkeletonBlock />
                        </span>
                      </td>
                      <td data-label={accountColumns[2]}>
                        <span>
                          <SkeletonBlock />
                        </span>
                      </td>
                      <td className={accountStyles.share} data-label={accountColumns[3]}>
                        <SkeletonBlock />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </div>
        {leading ? <div className={styles.leading}>{leading}</div> : null}
      </div>
    </aside>
  );
}

export function MonthlyRecapSkeleton({ status, leading }: { status: string; leading?: ReactNode }) {
  return <MonthlyRecapPlaceholder leading={leading} status={status} />;
}
