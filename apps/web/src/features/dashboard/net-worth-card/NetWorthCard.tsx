import type { NetWorth, NetWorthPoint, NetWorthDelta } from '@cadran/api-client';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AmountVisibilityToggle } from '@/components/ui/amount-visibility-toggle/AmountVisibilityToggle';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { FreshnessBadge } from '@/features/dashboard/freshness-badge/FreshnessBadge';
import { PeriodNetWorthDelta } from '@/features/dashboard/period-net-worth-delta/PeriodNetWorthDelta';
import { hoverDelta } from '@/features/dashboard/net-worth-delta/hoverDelta';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { WealthChart } from '@/features/dashboard/wealth-chart/WealthChart';
import { WealthChartSkeleton } from '@/features/dashboard/wealth-chart/WealthChartSkeleton';
import { NetWorthPeriodControl } from '@/features/dashboard/net-worth-period/NetWorthPeriodControl';
import type { NetWorthPeriod } from '@/features/dashboard/net-worth-period/useNetWorthPeriod';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './NetWorthCard.module.css';

interface NetWorthCardProps {
  historyDelta: NetWorthDelta | null;
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
  historyDelta,
  historyPending,
  historyFetching,
  historyPoints,
  netWorth,
  period,
  onPeriodChange,
  truncated,
}: NetWorthCardProps) {
  const { i18n, t } = useTranslation();
  // The point under the pointer: the card shows its value, movement and day, then goes back to
  // the headline figure once the pointer leaves the curve.
  const [pointed, setPointed] = useState<NetWorthPoint | null>(null);
  // On a screen that cannot hover, a tap pins a point; a tap outside the card lets go of it.
  const [pinned, setPinned] = useState<NetWorthPoint | null>(null);
  const card = useRef<HTMLElement>(null);
  const hovered = pointed ?? pinned;
  const preview =
    hovered !== null && historyDelta !== null ? hoverDelta(hovered, historyDelta) : null;

  useEffect(() => {
    if (pinned === null) return undefined;
    const release = (event: Event) => {
      if (!card.current?.contains(event.target as Node)) setPinned(null);
    };
    const escape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setPinned(null);
    };
    document.addEventListener('pointerdown', release);
    document.addEventListener('keydown', escape);
    return () => {
      document.removeEventListener('pointerdown', release);
      document.removeEventListener('keydown', escape);
    };
  }, [pinned]);

  return (
    <section className={`card ${styles.card}`} aria-labelledby="net-worth-title" ref={card}>
      <div className={styles.header}>
        <div className={styles.titleGroup}>
          <h2 id="net-worth-title">{t('dashboard.netWorth.title')}</h2>
          {netWorth.quality === 'STALE' ? (
            <span
              aria-label={t('dashboard.netWorth.freshness.stale', {
                count: netWorth.stalestAgeDays ?? 0,
              })}
              className={styles.stale}
              role="img"
              title={t('dashboard.netWorth.freshness.stale', {
                count: netWorth.stalestAgeDays ?? 0,
              })}
              data-visual-mask
            >
              <Icon name="clock" size={14} />
            </span>
          ) : null}
        </div>
        <div className={styles.period}>
          <NetWorthPeriodControl
            period={period}
            onChange={onPeriodChange}
            disabled={historyPoints === null && historyPending}
          />
        </div>
      </div>
      <div>
        <div className={styles.figureRow}>
          <NetWorthFigure
            amount={hovered ? hovered.total : netWorth.total}
            className={`money ${styles.amount}`}
            reason={hovered ? hovered.reason : netWorth.reason}
          />
          <AmountVisibilityToggle />
        </div>
        <PeriodNetWorthDelta
          delta={preview ?? historyDelta}
          pending={historyPending}
          fetching={historyFetching}
        />
        {/* The day the figures stand for: the hovered point's, or the reference day by default. */}
        <p className={styles.asOf}>
          <time dateTime={hovered ? hovered.on : netWorth.asOf}>
            {formatCalendarDay(hovered ? hovered.on : netWorth.asOf, i18n.language)}
          </time>
        </p>
      </div>
      <div className={styles.history} aria-busy={historyFetching}>
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
          <WealthChart
            onActivePoint={setPointed}
            onSelect={setPinned}
            points={historyPoints}
            selectedOn={pinned?.on ?? null}
          />
        )}
        <p className={styles.metadata}>
          {netWorth.quality === 'STALE' ? null : <FreshnessBadge netWorth={netWorth} />}
        </p>
        <p className={styles.notice}>{truncated ? t('dashboard.chart.truncated') : null}</p>
      </div>
    </section>
  );
}
