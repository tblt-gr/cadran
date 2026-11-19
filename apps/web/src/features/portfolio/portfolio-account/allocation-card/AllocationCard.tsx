import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import type { PortfolioOverviewAllocation } from '@cadran/api-client';
import { ValuationFallbackNotice } from '@/features/portfolio/valuation-fallback-notice/ValuationFallbackNotice';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import { AllocationLegend } from '@/components/ui/charts/allocation-legend/AllocationLegend';
import { DonutPlot } from '@/components/ui/charts/donut-chart/DonutPlot';
import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import {
  compareDecimals,
  decimalRatioForGeometry,
  formatAmount,
  formatCalendarNumericDay,
} from '@/lib/decimal';
import { formatRatioPercentage, ratioToPercent } from '@/lib/formatRatioPercentage';
import type { AllocationBy } from '@/features/portfolio/usePortfolioOverview';
import { reasonLabel } from '@/features/portfolio/portfolio-account/overviewLabels';
import {
  instrumentClassOptions,
  instrumentRegionOptions,
} from '@/features/investments/instruments/instrument-form/instrumentOptions';
import styles from './AllocationCard.module.css';
const choices: AllocationBy[] = ['INSTRUMENT', 'ASSET_CLASS', 'REGION', 'CURRENCY'];
/** The server groups by code; classes and regions read with the names used on the instruments. */
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
  const [tableOpen, setTableOpen] = useState(false);
  const groupNames: Partial<Record<AllocationBy, ReadonlyMap<string, string>>> = {
    ASSET_CLASS: new Map(instrumentClassOptions(t)),
    REGION: new Map(instrumentRegionOptions(t)),
  };
  const label = (key: string, text: string) =>
    key === 'UNSPECIFIED'
      ? t('portfolio.allocation.unspecified')
      : ((allocation && groupNames[allocation.by]?.get(key)) ?? text);
  // A signed allocation stays useful in the table but cannot be represented by a donut.
  const signedShares =
    allocation &&
    [...allocation.items, ...allocation.slices].some(
      (item) =>
        item.share !== null &&
        (compareDecimals(item.share, '0') < 0 || compareDecimals(item.share, '1') > 0),
    );
  const atCost = allocation?.basis === 'COST';
  // Without a chart the table is the only reading of the allocation, so it stays open.
  const charted = !allocation || (allocation.slices.length > 0 && !signedShares);
  const table = (
    <div
      className={styles.tableScroll}
      role="region"
      aria-label={t('portfolio.allocation.detailed')}
      tabIndex={0}
    >
      <table>
        <caption className="sr-only">{t('portfolio.allocation.tableCaption')}</caption>
        <thead>
          <tr>
            <th scope="col">{t('portfolio.allocation.columns.label')}</th>
            <th scope="col">{t('portfolio.allocation.columns.value')}</th>
            <th scope="col">{t('portfolio.allocation.columns.share')}</th>
          </tr>
        </thead>
        {!allocation ? (
          <SkeletonTableRows columns={3} rows={3} />
        ) : (
          <tbody>
            {allocation.items.map((item) => (
              <tr key={item.key}>
                <th scope="row">
                  {allocation.by === 'INSTRUMENT' && /^[0-9a-f-]{36}$/i.test(item.key) ? (
                    <InstrumentLink
                      instrument={{ id: item.key, name: label(item.key, item.label) }}
                    />
                  ) : (
                    label(item.key, item.label)
                  )}
                </th>
                <td className="money">{money(item.value)}</td>
                <td className="money">
                  {item.share === null
                    ? t('states.notCalculable.label')
                    : formatRatioPercentage(item.share, i18n.language)}
                </td>
              </tr>
            ))}
          </tbody>
        )}
      </table>
    </div>
  );
  return (
    <section
      className={`card ${styles.card}`}
      aria-labelledby="portfolio-allocation-title"
      aria-busy={!allocation}
      data-testid={!allocation ? 'portfolio-allocation-skeleton' : undefined}
    >
      <div className={styles.header}>
        <h3 id="portfolio-allocation-title">{title ?? t('portfolio.allocation.title')}</h3>
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
      </div>
      {showFallbackNotice && allocation ? (
        <ValuationFallbackNotice
          selected={allocation.valuation}
          newerPartial={allocation.newerPartialValuation}
        />
      ) : null}
      <SegmentedControl
        disabled={disabled}
        label={t('portfolio.allocation.groupBy')}
        onChange={onByChange}
        options={choices.map((value) => ({ value, label: t(`portfolio.allocation.by.${value}`) }))}
        value={by}
      />
      {!allocation ? (
        <SkeletonBlock className={styles.plotSkeleton} />
      ) : allocation.slices.length === 0 && !signedShares ? (
        <p className={styles.note} role="status">
          {allocation.reason === 'PARTIAL_VALUATION'
            ? t('portfolio.allocation.partial')
            : reasonLabel(t, allocation.reason ?? 'NON_POSITIVE_TOTAL')}
        </p>
      ) : signedShares ? (
        <p className={styles.note} role="status">
          {t('portfolio.allocation.chartUnavailable')}
        </p>
      ) : (
        <>
          {atCost ? <p className={styles.note}>{t('portfolio.allocation.costNote')}</p> : null}
          <div className={styles.plot}>
            <DonutPlot
              label={t('portfolio.allocation.plotLabel')}
              totalText={allocation.total === null ? null : money(allocation.total)}
              slices={allocation.slices.map((item) => ({
                key: item.key,
                caption: label(item.key, item.label),
                amountText: money(item.value),
                size: decimalRatioForGeometry(item.share!, '1'),
              }))}
            />
            <AllocationLegend
              label={t('portfolio.allocation.legend')}
              items={allocation.slices.map((item) => ({
                key: item.key,
                label:
                  allocation.by === 'INSTRUMENT' && /^[0-9a-f-]{36}$/i.test(item.key) ? (
                    <InstrumentLink
                      instrument={{ id: item.key, name: label(item.key, item.label) }}
                    />
                  ) : (
                    label(item.key, item.label)
                  ),
                amount: money(item.value),
                share: formatRatioPercentage(item.share!, i18n.language),
                barPercent: ratioToPercent(item.share!),
              }))}
            />
          </div>
        </>
      )}
      {allocation && allocation.items.length === 0 ? null : charted ? (
        <Disclosure
          meta={
            allocation
              ? t('portfolio.allocation.rows', { count: allocation.items.length })
              : undefined
          }
          onToggle={setTableOpen}
          open={tableOpen}
          title={t('portfolio.allocation.allRows')}
        >
          {table}
        </Disclosure>
      ) : (
        table
      )}
    </section>
  );
}
