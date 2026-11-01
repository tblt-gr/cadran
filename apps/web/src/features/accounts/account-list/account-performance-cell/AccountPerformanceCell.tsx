import type { AccountOverviewRow, PortfolioOverviewAccountCard } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Sparkline } from '@/components/ui/sparkline/Sparkline';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';
import { UnrealizedGain } from '@/features/portfolio/unrealized-gain/UnrealizedGain';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { monthName, monthYear, signedRatioPercentage } from './performanceText';
import styles from './AccountPerformanceCell.module.css';

/** What the portfolios read says about one row; `none` when no row needs it. */
export type PortfolioJoin =
  | { status: 'none' }
  | { status: 'pending' }
  | { status: 'error' }
  | { status: 'ready'; item: PortfolioOverviewAccountCard | undefined; truncated?: boolean };

interface AccountPerformanceCellProps {
  account: AccountOverviewRow;
  portfolio: PortfolioJoin;
}

function Link({ children, href }: { children: React.ReactNode; href: string }) {
  return (
    <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
      {children}
    </a>
  );
}

/**
 * Unrealized gain, 12-month return trend and due month-end of an investment row. Every figure is
 * a server string; a figure that cannot be computed is worded, never zero.
 */
export function AccountPerformanceCell({ account, portfolio }: AccountPerformanceCellProps) {
  const { i18n, t } = useTranslation();
  const locale = i18n.language;

  if (!valuedByPortfolio(account)) {
    return <EmptyValue label={t('accounts.performance.notApplicable')} quiet />;
  }

  if (portfolio.status === 'pending') {
    return (
      <div className={styles.cell}>
        <SkeletonBlock className={styles.gainSkeleton} />
        <div className={styles.trend}>
          <SkeletonBlock className={styles.sparklineSkeleton} />
          <SkeletonBlock className={styles.captionSkeleton} />
        </div>
      </div>
    );
  }

  if (portfolio.status === 'ready' && portfolio.item === undefined && portfolio.truncated) {
    return <span>{t('accounts.performance.truncated')}</span>;
  }

  if (portfolio.status !== 'ready' || portfolio.item === undefined) {
    return <span>{t('accounts.performance.unavailable')}</span>;
  }

  const { dueMonths, returnTrend: trend, unrealizedGain: gain } = portfolio.item;
  const base = `/portfolios/${account.id}`;
  const performanceHref = `${base}?tab=performance`;
  const rate =
    trend.cumulativeReturn === null ? null : signedRatioPercentage(trend.cumulativeReturn, locale);

  return (
    <div className={styles.cell}>
      <p className={styles.gain}>
        <UnrealizedGain
          assetCode={account.assetCode}
          performance={gain.performance}
          reason={gain.reason}
          value={gain.value}
        />
      </p>
      <div className={styles.trend}>
        {rate !== null && trend.from !== null ? (
          <>
            <Sparkline
              label={t('accounts.performance.sparkline', {
                from: monthYear(trend.from, locale),
                rate,
                to: monthYear(trend.to, locale),
              })}
              points={trend.months.map((month) => ({ label: month.month, value: month.index }))}
            />
            <Link href={performanceHref}>
              {trend.from === trend.months[0].month
                ? t('accounts.performance.captionWindow', { rate })
                : t('accounts.performance.captionSince', {
                    rate,
                    since: monthYear(trend.from, locale),
                  })}
            </Link>
          </>
        ) : (
          <Link href={performanceHref}>
            {trend.reason === 'MISSING_MONTH' || trend.reason === 'NON_POSITIVE_GROWTH_FACTOR'
              ? t(`accounts.performance.reasons.${trend.reason}`, {
                  month: monthYear(trend.reasonMonth ?? trend.to, locale),
                })
              : t('accounts.performance.reasons.NO_HISTORY')}
          </Link>
        )}
      </div>
      {dueMonths.length > 0 ? (
        <Link href={`${base}?tab=valuations`}>
          <StatusBadge icon="alert" tone="warning">
            {t('accounts.performance.dueMonth', { month: monthName(dueMonths[0]!, locale) })}
          </StatusBadge>
        </Link>
      ) : null}
    </div>
  );
}
