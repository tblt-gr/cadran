import type { Holdings } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DonutPlot } from '@/components/ui/charts/donut-chart/DonutPlot';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { decimalRatioForGeometry } from '@/lib/decimal';
import { sliceCaption } from '@/features/dashboard/allocation-charts/sliceCaption';
import { formatRatioPercentage, ratioToPercent } from '@/lib/formatRatioPercentage';
import { holdingsMoney } from '@/features/investments/holdingsFormat';
import { sliceLabel, type AllocationCriterion } from './allocationLabels';
import styles from './HoldingsAllocationCard.module.css';

const criterionNameKeys = {
  byAssetClass: 'investments.allocation.criterionName.assetClass',
  byRegion: 'investments.allocation.criterionName.region',
  byInstrument: 'investments.allocation.criterionName.instrument',
  byAccount: 'investments.allocation.criterionName.account',
} as const;

/** Share of the securities by asset class, region or instrument: a donut and its legend table. */
export function HoldingsAllocationCard({
  holdings,
  view,
}: {
  holdings: Holdings | null;
  /** The positions view on the page: by account, the allocation starts by account too. */
  view?: 'asset' | 'account';
}) {
  const { t } = useTranslation();
  const [chosen, setChosen] = useState<AllocationCriterion | null>(null);
  // Without any line the asset views only hold the unsplit slice: the accounts are what to show.
  const criterion: AllocationCriterion =
    chosen ??
    (view === 'account' || (holdings && holdings.items.length === 0)
      ? 'byAccount'
      : 'byAssetClass');
  const slices = holdings?.allocation[criterion] ?? [];
  const label = (slice: { key: string; label: string }) =>
    sliceLabel(criterion, slice, t('investments.allocation.unspecified'));
  const money = (value: string) => holdingsMoney(value, holdings!.assetCode);
  const charted = slices.length > 0 && slices.every((slice) => slice.share !== null);
  const criterionName = t(criterionNameKeys[criterion]);

  return (
    <section
      aria-busy={!holdings}
      aria-labelledby="holdings-allocation-title"
      className={`card ${styles.card}`}
    >
      <h3 id="holdings-allocation-title">{t('investments.allocation.title')}</h3>
      <SegmentedControl
        disabled={!holdings}
        label={t('investments.allocation.criterion')}
        onChange={setChosen}
        options={[
          { value: 'byAssetClass', label: t('investments.allocation.assetClass') },
          { value: 'byRegion', label: t('investments.allocation.region') },
          { value: 'byInstrument', label: t('investments.allocation.instrument') },
          { value: 'byAccount', label: t('investments.allocation.account') },
        ]}
        value={criterion}
      />
      <div className={styles.body}>
        {!holdings ? (
          <SkeletonBlock className={styles.plotSkeleton} />
        ) : charted ? (
          <DonutPlot
            label={t('investments.allocation.donut', { criterion: criterionName })}
            slices={slices.map((slice) => ({
              key: slice.key,
              caption: sliceCaption(
                label(slice),
                slice.share === null ? null : ratioToPercent(slice.share),
              ),
              amountText: money(slice.value),
              size: decimalRatioForGeometry(slice.share!, '1'),
              // Accounts valued as a total stand for no category: a neutral fill, no colour meaning.
              fill: slice.key === 'UNSPLIT' ? 'var(--text-muted)' : undefined,
            }))}
            totalText={
              holdings.totals.securitiesValue === null
                ? null
                : money(holdings.totals.securitiesValue)
            }
          />
        ) : (
          <p className={styles.note} role="status">
            {t('investments.allocation.notCalculable')}
          </p>
        )}
        <div className={styles.tableScroll}>
          <table>
            <caption className="sr-only">{t('investments.allocation.caption')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('investments.allocation.label')}</th>
                <th scope="col">{t('investments.allocation.amount')}</th>
                <th scope="col">{t('investments.allocation.share')}</th>
              </tr>
            </thead>
            {!holdings ? (
              <SkeletonTableRows columns={3} rows={3} />
            ) : (
              <tbody>
                {slices.map((slice) => (
                  <tr key={slice.key}>
                    <th scope="row">{label(slice)}</th>
                    <td className="money">{money(slice.value)}</td>
                    <td className="money">
                      {slice.share === null ? (
                        <EmptyValue label={t('investments.summary.notCalculable')} />
                      ) : (
                        formatRatioPercentage(slice.share, 'fr')
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            )}
          </table>
        </div>
      </div>
    </section>
  );
}
