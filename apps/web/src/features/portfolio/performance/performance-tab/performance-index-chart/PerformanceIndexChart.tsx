import type { PortfolioAnnualPerformance } from '@cadran/api-client';
import { useId } from 'react';
import { CurveChart, type CurvePoint } from '@/components/ui/charts/curve-chart/CurveChart';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonChartArea } from '@/components/ui/skeleton/Skeleton';
import { formatDecimal } from '@/lib/decimal';
import {
  emptyMonthLabel,
  monthAndYear,
  monthName,
} from '@/features/portfolio/performance/performance-tab/monthLabels';
import styles from './PerformanceIndexChart.module.css';

function curvePoints(annual: PortfolioAnnualPerformance): CurvePoint[] {
  return annual.months.map((line) => {
    const label = monthAndYear(line.month);
    const index = 'modifiedDietzIndex' in line ? line.modifiedDietzIndex : null;
    const reason =
      line.status === 'BEFORE_OPENING' || line.status === 'FUTURE'
        ? emptyMonthLabel[line.status]
        : 'Non calculable';
    const shown = index === null ? null : formatDecimal(index, 'fr');
    return {
      axisLabel: monthName(line.month),
      geometryValue: index,
      hitLabel: shown === null ? label : `${label}, ${shown}`,
      key: line.month,
      tooltipTitle: label,
      value: shown === null ? <EmptyValue label={reason} /> : shown,
    };
  });
}

/**
 * The chained Modified Dietz index, base 100, one point per month end. The server stops the
 * curve at the first month it cannot chain; the month table is its accessible equivalent.
 */
export function PerformanceIndexChart({ annual }: { annual: PortfolioAnnualPerformance | null }) {
  const titleId = useId();
  const descriptionId = useId();
  return (
    <section className={`card ${styles.card}`}>
      <figure aria-describedby={descriptionId} aria-labelledby={titleId} className={styles.figure}>
        <h3 id={titleId}>Rendement chaîné (base 100)</h3>
        <p className={styles.description} id={descriptionId}>
          Évolution de 100 € investis selon le rendement Modified Dietz, mois par mois.
        </p>
        {annual ? (
          <CurveChart
            label="Rendement chaîné (base 100)"
            points={curvePoints(annual)}
            timeAxisLabel="Mois"
            valueAxisLabel="Indice base 100"
          />
        ) : (
          <SkeletonChartArea />
        )}
      </figure>
    </section>
  );
}
