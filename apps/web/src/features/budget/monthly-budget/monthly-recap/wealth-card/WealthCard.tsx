import type { MonthlyRecapNetWorth } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarMonth, isZeroDecimal } from '@/lib/decimal';
import { formatSharePercent } from '@/lib/formatSharePercent';
import { StaleValuations } from './stale-valuations/StaleValuations';
import styles from './WealthCard.module.css';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';

interface WealthCardProps {
  netWorth: MonthlyRecapNetWorth | null;
  previousAsOf?: string;
  currentAsOf?: string;
  provisional: boolean;
}

function monthLabel(day: string, locale: string) {
  return formatCalendarMonth(day, locale).toLocaleLowerCase(locale);
}

/**
 * N−1, N, the signed difference and the change rate. Every figure carries its
 * own reason and none of them is ever replaced by a displayed zero: a zero or
 * negative prior net worth, a missing or stale valuation, or mixed assets each
 * state explicitly why the figure that depends on them is absent.
 */
export function WealthCard({ netWorth, provisional, previousAsOf, currentAsOf }: WealthCardProps) {
  const { i18n, t } = useTranslation();
  const tone = (value: string | null | undefined) =>
    value == null || isZeroDecimal(value)
      ? styles.neutral
      : value.startsWith('-')
        ? styles.negative
        : styles.positive;
  const sign = (value: string | null | undefined) =>
    value == null || isZeroDecimal(value) ? '' : value.startsWith('-') ? '−' : '+';
  // Keep the sign of the canonical figure even below its display precision.
  const difference = netWorth?.difference;
  const differenceShown = difference != null && netWorth?.differenceReason == null;
  const magnitude = difference
    ? {
        ...difference,
        value: difference.value.replace(/^-/, ''),
        display: difference.display
          ? { ...difference.display, value: difference.display.value.replace(/^-/, '') }
          : null,
      }
    : null;
  const pending = <SkeletonBlock className={styles.skeletonValue} />;
  const pendingMonth = <SkeletonBlock className={styles.skeletonMonth} />;

  return (
    <section aria-labelledby="monthly-recap-wealth-title" className={`card ${styles.panel}`}>
      <header className={styles.heading}>
        <h3 id="monthly-recap-wealth-title">{t('budget.monthly.recap.wealth.title')}</h3>
        {provisional ? (
          <span
            aria-label={t('budget.monthly.recap.provisional')}
            className={styles.provisional}
            role="img"
            title={t('budget.monthly.recap.provisional')}
          >
            <Icon name="clock" size={14} />
          </span>
        ) : null}
      </header>
      <dl className={styles.figures}>
        <div>
          <dt>
            {t('budget.monthly.recap.wealth.previous')}{' '}
            {previousAsOf ? monthLabel(previousAsOf, i18n.language) : pendingMonth}
          </dt>
          <dd>
            {netWorth ? (
              <NetWorthFigure amount={netWorth.previous} reason={netWorth.previousReason} />
            ) : (
              pending
            )}
          </dd>
        </div>
        <div>
          <dt>
            {t('budget.monthly.recap.wealth.current')}{' '}
            {currentAsOf ? monthLabel(currentAsOf, i18n.language) : pendingMonth}
          </dt>
          <dd>
            {netWorth ? (
              <NetWorthFigure amount={netWorth.current} reason={netWorth.currentReason} />
            ) : (
              pending
            )}
          </dd>
        </div>
        <div>
          <dt>{t('budget.monthly.recap.wealth.difference')}</dt>
          <dd className={tone(differenceShown ? difference.value : null)}>
            {differenceShown ? sign(difference.value) : ''}
            {netWorth ? (
              <NetWorthFigure amount={magnitude} reason={netWorth.differenceReason} />
            ) : (
              pending
            )}
          </dd>
        </div>
        <div>
          <dt>{t('budget.monthly.recap.wealth.changePercent')}</dt>
          <dd
            className={tone(
              netWorth?.changePercentDisplay == null
                ? null
                : (netWorth.changePercent ?? netWorth.changePercentDisplay),
            )}
          >
            {netWorth === null ? (
              pending
            ) : netWorth.changePercentDisplay === null ? (
              <EmptyValue
                label={t('states.notCalculable.label')}
                reason={
                  netWorth.changeReason
                    ? t(`dashboard.netWorth.rateReasons.${netWorth.changeReason}`)
                    : null
                }
              />
            ) : (
              `${sign(netWorth.changePercent ?? netWorth.changePercentDisplay)}${formatSharePercent(netWorth.changePercentDisplay.replace(/^-/, ''))}`
            )}
          </dd>
        </div>
      </dl>
      {netWorth &&
      (netWorth.previousMissingValuationCount > 0 || netWorth.previousStaleValuationCount > 0) ? (
        <p className={styles.quality} role="status">
          {netWorth.previousMissingValuationCount > 0
            ? t('budget.monthly.recap.wealth.previousMissingCount', {
                count: netWorth.previousMissingValuationCount,
              })
            : null}
          {netWorth.previousMissingValuationCount > 0 && netWorth.previousStaleValuationCount > 0
            ? ' '
            : null}
          {netWorth.previousStaleValuationCount > 0 ? (
            <StaleValuations
              period={previousAsOf ? monthLabel(previousAsOf, i18n.language) : null}
              rows={netWorth.previousStaleValuations}
              sentence={
                netWorth.previousStalestAgeDays === null
                  ? t('budget.monthly.recap.wealth.previousStaleCount', {
                      count: netWorth.previousStaleValuationCount,
                    })
                  : t('budget.monthly.recap.wealth.previousStaleCountWithAge', {
                      count: netWorth.previousStaleValuationCount,
                      days: netWorth.previousStalestAgeDays,
                    })
              }
            />
          ) : null}
        </p>
      ) : null}
      {netWorth && (netWorth.missingValuationCount > 0 || netWorth.staleValuationCount > 0) ? (
        <p className={styles.quality} role="status">
          {netWorth.missingValuationCount > 0
            ? t('budget.monthly.recap.wealth.missingCount', {
                count: netWorth.missingValuationCount,
              })
            : null}
          {netWorth.missingValuationCount > 0 && netWorth.staleValuationCount > 0 ? ' ' : null}
          {netWorth.staleValuationCount > 0 ? (
            <StaleValuations
              period={currentAsOf ? monthLabel(currentAsOf, i18n.language) : null}
              rows={netWorth.staleValuations}
              sentence={t('budget.monthly.recap.wealth.staleCount', {
                count: netWorth.staleValuationCount,
              })}
            />
          ) : null}
        </p>
      ) : null}
    </section>
  );
}
