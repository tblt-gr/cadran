import type { MonthlyRecapNetWorth } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { formatSharePercent } from '@/lib/formatSharePercent';
import styles from './WealthCard.module.css';

interface WealthCardProps {
  netWorth: MonthlyRecapNetWorth;
  provisional: boolean;
}

/**
 * N−1, N, the signed difference and the change rate. Every figure carries its
 * own reason and none of them is ever replaced by a displayed zero: a zero or
 * negative prior net worth, a missing or stale valuation, or mixed assets each
 * state explicitly why the figure that depends on them is absent.
 */
export function WealthCard({ netWorth, provisional }: WealthCardProps) {
  const { t } = useTranslation();

  return (
    <section aria-labelledby="monthly-recap-wealth-title" className={`card ${styles.panel}`}>
      <h3 id="monthly-recap-wealth-title">{t('budget.monthly.recap.wealth.title')}</h3>
      {provisional ? (
        <p className={styles.provisional}>{t('budget.monthly.recap.provisional')}</p>
      ) : null}
      <dl className={styles.figures}>
        <div>
          <dt>{t('budget.monthly.recap.wealth.previous')}</dt>
          <dd>
            <NetWorthFigure amount={netWorth.previous} reason={netWorth.previousReason} />
          </dd>
        </div>
        <div>
          <dt>{t('budget.monthly.recap.wealth.current')}</dt>
          <dd>
            <NetWorthFigure amount={netWorth.current} reason={netWorth.currentReason} />
          </dd>
        </div>
        <div>
          <dt>{t('budget.monthly.recap.wealth.difference')}</dt>
          <dd>
            <NetWorthFigure amount={netWorth.difference} reason={netWorth.differenceReason} />
          </dd>
        </div>
        <div>
          <dt>{t('budget.monthly.recap.wealth.changePercent')}</dt>
          <dd>
            {netWorth.changePercentDisplay === null ? (
              <span className={styles.unavailable}>
                {t('states.notCalculable.label')}
                {netWorth.changeReason ? (
                  <small>{t(`dashboard.netWorth.rateReasons.${netWorth.changeReason}`)}</small>
                ) : null}
              </span>
            ) : (
              formatSharePercent(netWorth.changePercentDisplay)
            )}
          </dd>
        </div>
      </dl>
      {netWorth.previousMissingValuationCount > 0 || netWorth.previousStaleValuationCount > 0 ? (
        <p className={styles.quality} role="status">
          {netWorth.previousMissingValuationCount > 0
            ? t('budget.monthly.recap.wealth.previousMissingCount', {
                count: netWorth.previousMissingValuationCount,
              })
            : null}
          {netWorth.previousStaleValuationCount > 0
            ? `${netWorth.previousMissingValuationCount > 0 ? ' ' : ''}${
                netWorth.previousStalestAgeDays === null
                  ? t('budget.monthly.recap.wealth.previousStaleCount', {
                      count: netWorth.previousStaleValuationCount,
                    })
                  : t('budget.monthly.recap.wealth.previousStaleCountWithAge', {
                      count: netWorth.previousStaleValuationCount,
                      days: netWorth.previousStalestAgeDays,
                    })
              }`
            : null}
        </p>
      ) : null}
      {netWorth.missingValuationCount > 0 || netWorth.staleValuationCount > 0 ? (
        <p className={styles.quality} role="status">
          {netWorth.missingValuationCount > 0
            ? t('budget.monthly.recap.wealth.missingCount', {
                count: netWorth.missingValuationCount,
              })
            : null}
          {netWorth.staleValuationCount > 0
            ? `${netWorth.missingValuationCount > 0 ? ' ' : ''}${t(
                'budget.monthly.recap.wealth.staleCount',
                { count: netWorth.staleValuationCount },
              )}`
            : null}
        </p>
      ) : null}
    </section>
  );
}
