import { useTranslation } from 'react-i18next';
import { DonutPlot } from '@/components/ui/charts/donut-chart/DonutPlot';
import { AllocationLegend } from '@/components/ui/charts/allocation-legend/AllocationLegend';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { compareDecimals, decimalRatioForGeometry } from '@/lib/decimal';
import { formatRatioPercentage, ratioToPercent } from '@/lib/formatRatioPercentage';
import {
  allocationKinds,
  useLifeInsuranceMoney,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import styles from './LifeInsuranceAllocationCard.module.css';
export function LifeInsuranceAllocationCard({ summary }: { summary: Summary | null }) {
  const { t, i18n } = useTranslation();
  const money = useLifeInsuranceMoney();
  const allocation = summary?.allocation;
  const kinds: readonly Summary['allocation']['items'][number]['kind'][] = allocationKinds;
  const kindLabel = (kind: (typeof allocationKinds)[number]) =>
    t(`portfolio.lifeInsurance.allocation.kinds.${kind}`);
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
      aria-label={t('portfolio.lifeInsurance.allocation.label')}
      aria-busy={!summary}
    >
      <h3>{t('portfolio.lifeInsurance.allocation.title')}</h3>
      {allocation && allocation.status !== 'AVAILABLE' ? (
        <p>{t(`portfolio.lifeInsurance.allocation.empty.${allocation.status}`)}</p>
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
                  label={t('portfolio.lifeInsurance.allocation.label')}
                  totalText={summary.valuation ? amount(summary.valuation.totalValue) : null}
                  slices={items.map((item) => ({
                    key: item.kind,
                    caption: kindLabel(item.kind),
                    amountText: amount(item.value),
                    size: decimalRatioForGeometry(item.share!, '1'),
                  }))}
                />
              )}
              <AllocationLegend
                label={t('portfolio.lifeInsurance.allocation.legend')}
                items={items.map((item) => ({
                  key: item.kind,
                  label: kindLabel(item.kind),
                  amount: summary ? (
                    amount(item.value)
                  ) : (
                    <SkeletonBlock className={styles.valueSkeleton} />
                  ),
                  share: summary ? (
                    formatRatioPercentage(item.share!, i18n.language)
                  ) : (
                    <SkeletonBlock className={styles.valueSkeleton} />
                  ),
                  barPercent: summary ? ratioToPercent(item.share!) : null,
                }))}
              />
            </div>
          ) : (
            <p>{t('portfolio.lifeInsurance.allocation.unchartable')}</p>
          )}
        </>
      )}
      {!summary || items.length > 0 ? (
        <div
          className={styles.scroll}
          role="region"
          aria-label={t('portfolio.lifeInsurance.allocation.detailed')}
          tabIndex={0}
        >
          <table>
            <caption className="sr-only">{t('portfolio.lifeInsurance.allocation.label')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('portfolio.lifeInsurance.allocation.columns.label')}</th>
                <th scope="col">{t('portfolio.lifeInsurance.allocation.columns.value')}</th>
                <th scope="col">{t('portfolio.lifeInsurance.allocation.columns.share')}</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.kind}>
                  <th scope="row">{kindLabel(item.kind)}</th>
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
                      t('states.notCalculable.label')
                    ) : (
                      formatRatioPercentage(item.share, i18n.language)
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
