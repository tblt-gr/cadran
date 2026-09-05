import type { NetWorth, NetWorthPoint } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { FreshnessBadge } from '@/features/dashboard/freshness-badge/FreshnessBadge';
import { NetWorthDelta } from '@/features/dashboard/net-worth-delta/NetWorthDelta';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { WealthChart } from '@/features/dashboard/wealth-chart/WealthChart';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './NetWorthCard.module.css';

interface NetWorthCardProps {
  historyPending: boolean;
  historyPoints: NetWorthPoint[] | null;
  netWorth: NetWorth;
}

/**
 * The headline figure, what moved since the compared day, how fresh the
 * sources are, and the curve behind it. The card computes nothing: the whole
 * aggregate arrives from the backend already signed, summed and rounded.
 */
export function NetWorthCard({ historyPending, historyPoints, netWorth }: NetWorthCardProps) {
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
          <p className={styles.asOf}>
            {t('dashboard.netWorth.asOf', {
              date: formatCalendarDay(netWorth.asOf, i18n.language),
            })}
          </p>
        </div>
      </div>
      {historyPoints === null ? (
        <p className={styles.historyUnavailable} aria-busy={historyPending} role="status">
          {t(historyPending ? 'dashboard.chart.loading' : 'dashboard.chart.unavailable')}
        </p>
      ) : (
        <WealthChart points={historyPoints} />
      )}
    </section>
  );
}
