import { DonutPlot } from '@/components/ui/charts/donut-chart/DonutPlot';
import { AllocationLegend } from '@/components/ui/charts/allocation-legend/AllocationLegend';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { compareDecimals, decimalRatioForGeometry } from '@/lib/decimal';
import { formatRatioPercentage, ratioToPercent } from '@/lib/formatRatioPercentage';
import {
  allocationLabels,
  allocationEmpty,
  money,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import styles from './LifeInsuranceAllocationCard.module.css';
export function LifeInsuranceAllocationCard({ summary }: { summary: Summary | null }) {
  const allocation = summary?.allocation;
  const kinds = Object.keys(allocationLabels) as Summary['allocation']['items'][number]['kind'][];
  const items = allocation?.items ?? kinds.map((kind) => ({ kind, value: null, share: null }));
  const charted =
    items.length > 0 &&
    items.every(
      (item) =>
        item.share !== null &&
        compareDecimals(item.share, '0') >= 0 &&
        compareDecimals(item.share, '1') <= 0,
    );
  const amount = (value: string | null) => money(value, summary!.assetCode);
  return (
    <section
      className={`card ${styles.card}`}
      aria-label="Répartition du contrat"
      aria-busy={!summary}
    >
      <h3>Répartition</h3>
      {allocation && allocation.status !== 'AVAILABLE' ? (
        <p>{allocationEmpty[allocation.status]}</p>
      ) : (
        <>
          {!summary || charted ? (
            <div className={styles.chart}>
              {!summary ? (
                <SkeletonBlock
                  className={styles.plotSkeleton}
                  data-testid="allocation-donut-skeleton"
                />
              ) : (
                <DonutPlot
                  label="Répartition du contrat"
                  totalText={summary.valuation ? amount(summary.valuation.totalValue) : null}
                  slices={items.map((item) => ({
                    key: item.kind,
                    caption: allocationLabels[item.kind],
                    amountText: amount(item.value),
                    size: decimalRatioForGeometry(item.share!, '1'),
                  }))}
                />
              )}
              <AllocationLegend
                label="Légende de la répartition"
                items={items.map((item) => ({
                  key: item.kind,
                  label: allocationLabels[item.kind],
                  amount: summary ? (
                    amount(item.value)
                  ) : (
                    <SkeletonBlock className={styles.valueSkeleton} />
                  ),
                  share: summary ? (
                    formatRatioPercentage(item.share!, 'fr')
                  ) : (
                    <SkeletonBlock className={styles.valueSkeleton} />
                  ),
                  barPercent: summary ? ratioToPercent(item.share!) : null,
                }))}
              />
            </div>
          ) : (
            <p>Parts non calculables ou signées : consultez les valeurs du tableau.</p>
          )}
        </>
      )}
      {!summary || items.length > 0 ? (
        <div
          className={styles.scroll}
          role="region"
          aria-label="Répartition détaillée"
          tabIndex={0}
        >
          <table>
            <caption className="sr-only">Répartition du contrat</caption>
            <thead>
              <tr>
                <th scope="col">Libellé</th>
                <th scope="col">Valeur</th>
                <th scope="col">Part</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.kind}>
                  <th scope="row">{allocationLabels[item.kind]}</th>
                  <td className="money">
                    {summary ? (
                      amount(item.value)
                    ) : (
                      <SkeletonBlock className={styles.valueSkeleton} />
                    )}
                  </td>
                  <td className="money">
                    {!summary ? (
                      <SkeletonBlock className={styles.valueSkeleton} />
                    ) : item.share === null ? (
                      'Non calculable'
                    ) : (
                      formatRatioPercentage(item.share, 'fr')
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </section>
  );
}
