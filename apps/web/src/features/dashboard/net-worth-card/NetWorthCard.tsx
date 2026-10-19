import type { NetWorth, NetWorthPoint } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { FreshnessBadge } from '@/features/dashboard/freshness-badge/FreshnessBadge';
import { NetWorthDelta } from '@/features/dashboard/net-worth-delta/NetWorthDelta';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { WealthChart } from '@/features/dashboard/wealth-chart/WealthChart';
import { WealthChartSkeleton } from '@/features/dashboard/wealth-chart/WealthChartSkeleton';
import { formatCalendarDay } from '@/lib/decimal';
import { NetWorthPeriodControl } from '@/features/dashboard/net-worth-period/NetWorthPeriodControl';
import type { NetWorthPeriod } from '@/features/dashboard/net-worth-period/useNetWorthPeriod';
import styles from './NetWorthCard.module.css';

interface NetWorthCardProps {
  historyPending: boolean;
  historyFetching: boolean;
  period: NetWorthPeriod;
  onPeriodChange: (period: NetWorthPeriod) => void;
  truncated: boolean;
  historyPoints: NetWorthPoint[] | null;
  netWorth: NetWorth;
}

/**
 * The headline figure, what moved since the compared day, how fresh the
 * sources are, and the curve behind it. The card computes nothing: the whole
 * aggregate arrives from the backend already signed, summed and rounded.
 */
export function NetWorthCard({
  historyPending,
  historyFetching,
  historyPoints,
  netWorth,
  period,
  onPeriodChange,
  truncated,
}: NetWorthCardProps) {
  const { i18n, t } = useTranslation();

  return (
    <section className={`card ${styles.card}`} aria-labelledby="net-worth-title">
      <div className={styles.header}>
        <div>
          <h2 id="net-worth-title">{t('dashboard.netWorth.title')}</h2>
          <NetWorthFigure
            amount={netWorth.total}
            className={`money ${styles.amount}`}
            reason={netWorth.reason}
          />
          <NetWorthDelta delta={netWorth.delta} />
        </div>
        <div className={styles.context}>
          <FreshnessBadge netWorth={netWorth} />
          <p className={styles.asOf} data-visual-mask>
            {t('dashboard.netWorth.asOf', {
              date: formatCalendarDay(netWorth.asOf, i18n.language),
            })}
          </p>
        </div>
      </div>
      <div className={styles.history} aria-busy={historyFetching}>
        <NetWorthPeriodControl
          period={period}
          onChange={onPeriodChange}
          disabled={historyPoints === null && historyPending}
        />
        {historyPoints === null && historyPending ? (
          <div aria-busy="true" className={styles.historyPending}>
            <SkeletonStatus>{t('dashboard.chart.loading')}</SkeletonStatus>
            <WealthChartSkeleton />
          </div>
        ) : historyPoints === null ? (
          <p className={styles.historyUnavailable} role="status">
            {t('dashboard.chart.unavailable')}
          </p>
        ) : (
          <WealthChart points={historyPoints} />
        )}
        <p className={styles.notice}>{truncated ? t('dashboard.chart.truncated') : null}</p>
      </div>
    </section>
  );
}
