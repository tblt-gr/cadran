import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import type { PortfolioOverviewAllocation } from '@cadran/api-client';
import { ValuationFallbackNotice } from '@/features/portfolio/valuation-fallback-notice/ValuationFallbackNotice';
import { useTranslation } from 'react-i18next';
import {
  AllocationBreakdown,
  type AllocationBreakdownSlice,
  type AllocationBreakdownStatus,
} from '@/components/ui/charts/allocation-breakdown/AllocationBreakdown';
import {
  geometrySize,
  isNegativeRatio,
} from '@/components/ui/charts/allocation-breakdown/signedRatio';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import { formatRatioPercentage, ratioToPercent } from '@/lib/formatRatioPercentage';
import { sliceCaption } from '@/features/dashboard/allocation-charts/sliceCaption';
import type { AllocationBy } from '@/features/portfolio/usePortfolioOverview';
import { reasonLabel } from '@/features/portfolio/portfolio-account/overviewLabels';
import {
  instrumentClassOptions,
  instrumentRegionOptions,
} from '@/features/investments/instruments/instrument-form/instrumentOptions';
import styles from './AllocationCard.module.css';

const choices: AllocationBy[] = ['INSTRUMENT', 'ASSET_CLASS', 'REGION', 'CURRENCY'];

/** The portfolio account allocation in the shared panel, with its valuation date and notices. */
export function AllocationCard({
  allocation,
  title,
  by,
  onByChange,
  disabled = false,
  showFallbackNotice = true,
}: {
  allocation: PortfolioOverviewAllocation | null;
  /** Defaults to the allocation heading. */
  title?: string;
  by: AllocationBy;
  onByChange: (by: AllocationBy) => void;
  disabled?: boolean;
  showFallbackNotice?: boolean;
}) {
  const { t, i18n } = useTranslation();
  const money = (value: string | null) =>
    value === null
      ? t('states.notCalculable.label')
      : formatAmount(value, allocation!.assetCode, i18n.language);
  // The server groups by code; classes and regions read with the names used on the instruments.
  const groupNames: Partial<Record<AllocationBy, ReadonlyMap<string, string>>> = {
    ASSET_CLASS: new Map(instrumentClassOptions(t)),
    REGION: new Map(instrumentRegionOptions(t)),
  };
  const label = (key: string, text: string) =>
    key === 'UNSPECIFIED'
      ? t('portfolio.allocation.unspecified')
      : ((allocation && groupNames[allocation.by]?.get(key)) ?? text);
  // A negative share (a debit cash balance) stays in the table but is left out of the donut.
  const ignored = allocation
    ? allocation.items.filter((item) => isNegativeRatio(item.share)).length
    : 0;
  const atCost = allocation?.basis === 'COST';
  const charted = new Map((allocation?.slices ?? []).map((item) => [item.key, item.share]));

  const slices: AllocationBreakdownSlice[] | null = allocation
    ? allocation.items.map((item) => {
        const text = label(item.key, item.label);
        const share = charted.get(item.key) ?? null;
        return {
          key: item.key,
          caption: sliceCaption(text, item.share === null ? null : ratioToPercent(item.share)),
          label:
            allocation.by === 'INSTRUMENT' && /^[0-9a-f-]{36}$/i.test(item.key) ? (
              <InstrumentLink instrument={{ id: item.key, name: text }} />
            ) : (
              text
            ),
          amountText: money(item.value),
          shareText: item.share === null ? null : formatRatioPercentage(item.share, i18n.language),
          size: geometrySize(share),
        };
      })
    : null;

  let status: AllocationBreakdownStatus = { kind: 'ready' };
  if (allocation && allocation.slices.length === 0 && ignored === 0) {
    status = {
      kind: 'notCalculable',
      message:
        allocation.reason === 'PARTIAL_VALUATION'
          ? t('portfolio.allocation.partial')
          : (reasonLabel(t, allocation.reason ?? 'NON_POSITIVE_TOTAL') ??
            t('allocation.notCalculable')),
    };
  }

  return (
    <AllocationBreakdown
      criteria={choices.map((value) => ({ value, label: t(`portfolio.allocation.by.${value}`) }))}
      criterion={by}
      disabled={disabled}
      donutLabel={t('portfolio.allocation.plotLabel')}
      headerExtra={
        <div className={styles.valuationDate}>
          <span>{t('portfolio.allocation.valuationUsed')}</span>
          {!allocation ? (
            <SkeletonBlock className={styles.dateSkeleton} />
          ) : allocation.valuation ? (
            <time dateTime={allocation.valuation.valuedOn}>
              {formatCalendarNumericDay(allocation.valuation.valuedOn)}
            </time>
          ) : (
            <span>{t('portfolio.allocation.noValuation')}</span>
          )}
          {atCost ? <span>{t('portfolio.allocation.atCost')}</span> : null}
        </div>
      }
      notice={
        <>
          {showFallbackNotice && allocation ? (
            <ValuationFallbackNotice
              selected={allocation.valuation}
              newerPartial={allocation.newerPartialValuation}
            />
          ) : null}
          {atCost ? <p className={styles.note}>{t('portfolio.allocation.costNote')}</p> : null}
        </>
      }
      onCriterionChange={onByChange}
      slices={slices}
      ignoredCount={ignored}
      status={status}
      title={title ?? t('allocation.title')}
      titleId="portfolio-allocation-title"
      totalText={allocation && allocation.total !== null ? money(allocation.total) : null}
    />
  );
}
