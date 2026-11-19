import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { usePortfolioSummary } from '@/features/portfolio/usePortfolioOverview';
import { dueMonthLabel, monthEndPath } from '@/features/portfolio/month-end/monthEnd';
import styles from './MonthEndValuationBanner.module.css';

/**
 * Under the portfolio header: one line per month still owed a complete valuation, each with the
 * deep link that opens the valuation form on the last day of that month. Hidden when nothing is due.
 */
export function MonthEndValuationBanner({ accountId, asOf }: { accountId: string; asOf: string }) {
  const { t, i18n } = useTranslation();
  const summary = usePortfolioSummary(accountId, asOf);

  if (summary.isPending) {
    return (
      <div aria-busy="true" className={styles.banner}>
        <SkeletonStatus>{t('portfolio.monthEnd.loading')}</SkeletonStatus>
        <SkeletonBlock className={styles.skeleton} />
      </div>
    );
  }
  if (summary.isError) {
    return (
      <div className={styles.banner} role="alert">
        <p className={styles.error}>{t('portfolio.monthEnd.unavailable')}</p>
        <button className="secondary-action" onClick={() => void summary.refetch()} type="button">
          {t('actions.retry')}
        </button>
      </div>
    );
  }
  const due = summary.data.dueMonths;
  if (due.length === 0) return null;

  return (
    <ul aria-label={t('portfolio.monthEnd.label')} className={styles.banner}>
      {due.map((month) => {
        const href = monthEndPath(accountId, month);
        return (
          <li className={styles.line} key={month}>
            <StatusBadge icon="alert" tone="warning">
              {t('portfolio.monthEnd.due', { month: dueMonthLabel(month, due, i18n.language) })}
            </StatusBadge>
            <a
              className={`secondary-action ${styles.action}`}
              href={href}
              onClick={(event) => handleClientNavigation(event, href)}
            >
              {t('portfolio.monthEnd.enter')}
            </a>
          </li>
        );
      })}
    </ul>
  );
}
