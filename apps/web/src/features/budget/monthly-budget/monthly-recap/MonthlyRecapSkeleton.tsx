import { useTranslation } from 'react-i18next';
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

/** The recap column's shape without any announcement, so a page skeleton can embed it silently. */
export function MonthlyRecapPlaceholder() {
  const { t } = useTranslation();
  return (
    <aside className={styles.recap}>
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
      <section className={`card ${totalsStyles.panel}`}>
        <header className={totalsStyles.heading}>
          <div className={totalsStyles.headingTitle}>
            <h3>{t('budget.monthly.recap.totals.title')}</h3>
            <button className="secondary-action" disabled type="button">
              {t('budget.monthly.recap.preferences.open')}
            </button>
          </div>
        </header>
        <ul className={totalsStyles.rows}>
          {TOTAL_LABELS.map((label) => (
            <li className={totalsStyles.row} key={label}>
              <div>
                <span className={totalsStyles.label}>{t(label)}</span>
                <SkeletonBlock className={styles.skeletonAmount} />
              </div>
            </li>
          ))}
        </ul>
        <h4>{t('budget.monthly.recap.totals.axes')}</h4>
      </section>
      <section className={`card ${accountStyles.panel}`}>
        <h3>{t('budget.monthly.recap.accounts.title')}</h3>
        <div className={accountStyles.tableScroll}>
          <table>
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
            <SkeletonTableRows columns={4} rows={4} />
          </table>
        </div>
      </section>
    </aside>
  );
}

export function MonthlyRecapSkeleton({ status }: { status: string }) {
  return (
    <div aria-busy="true" data-testid="monthly-recap-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <MonthlyRecapPlaceholder />
    </div>
  );
}
