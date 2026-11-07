import type { Holdings } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DonutPlot } from '@/components/ui/charts/donut-chart/DonutPlot';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { decimalRatioForGeometry } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { holdingsMoney } from '@/features/investments/holdingsFormat';
import { sliceLabel, type AllocationCriterion } from './allocationLabels';
import styles from './HoldingsAllocationCard.module.css';

const criterionNameKeys = {
  byAssetClass: 'investments.allocation.criterionName.assetClass',
  byRegion: 'investments.allocation.criterionName.region',
  byInstrument: 'investments.allocation.criterionName.instrument',
} as const;

/** Share of the securities by asset class, region or instrument: a donut and its legend table. */
export function HoldingsAllocationCard({ holdings }: { holdings: Holdings | null }) {
  const { t } = useTranslation();
  const [criterion, setCriterion] = useState<AllocationCriterion>('byAssetClass');
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
        onChange={setCriterion}
        options={[
          { value: 'byAssetClass', label: t('investments.allocation.assetClass') },
          { value: 'byRegion', label: t('investments.allocation.region') },
          { value: 'byInstrument', label: t('investments.allocation.instrument') },
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
              caption: label(slice),
              amountText: money(slice.value),
              size: decimalRatioForGeometry(slice.share!, '1'),
            }))}
            totalText={null}
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
