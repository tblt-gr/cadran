import type { PortfolioAnnualPerformance } from '@cadran/api-client';
import { useId } from 'react';
import { CurveChart, type CurvePoint } from '@/components/ui/charts/curve-chart/CurveChart';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonChartArea } from '@/components/ui/skeleton/Skeleton';
import { formatAmount } from '@/lib/decimal';
import {
  emptyMonthLabel,
  monthAndYear,
  monthName,
} from '@/features/portfolio/performance/performance-tab/monthLabels';
import styles from './PerformanceValueChart.module.css';

function curvePoints(annual: PortfolioAnnualPerformance): CurvePoint[] {
  return annual.months.map((line) => {
    const label = monthAndYear(line.month);
    const value = 'endValue' in line ? line.endValue : null;
    const reason =
      line.status === 'BEFORE_OPENING' || line.status === 'FUTURE'
        ? emptyMonthLabel[line.status]
        : 'Non calculable';
    const shown = value === null ? null : formatAmount(value, annual.assetCode, 'fr');
    return {
      axisLabel: monthName(line.month),
      geometryValue: value,
      hitLabel: shown === null ? label : `${label}, ${shown}`,
      key: line.month,
      tooltipTitle: label,
      value: shown === null ? <EmptyValue label={reason} /> : shown,
    };
  });
}

/** The account value at each month end of the chosen year; the month table is its accessible equivalent. */
export function PerformanceValueChart({
  annual,
  year,
}: {
  annual: PortfolioAnnualPerformance | null;
  year: number;
}) {
  const titleId = useId();
  const descriptionId = useId();
  return (
    <section className={`card ${styles.card}`}>
      <figure aria-describedby={descriptionId} aria-labelledby={titleId} className={styles.figure}>
        <h3 id={titleId}>{`Évolution de la valeur ${year}`}</h3>
        <p className={styles.description} id={descriptionId}>
          Valeur du portefeuille à la fin de chaque mois de l'année, versements compris.
        </p>
        {annual ? (
          <CurveChart
            label={`Évolution de la valeur ${year}`}
            points={curvePoints(annual)}
            timeAxisLabel="Mois"
            valueAxisLabel="Valeur"
          />
        ) : (
          <SkeletonChartArea />
        )}
      </figure>
    </section>
  );
}
