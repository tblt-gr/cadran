import type { PortfolioValuationHistoryPoint } from '@cadran/api-client';
import { CurveChart, type CurvePoint } from '@/components/ui/charts/curve-chart/CurveChart';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { detailLabel, summaryQualityLabel } from '@/features/portfolio/valuations/valuationLabels';
import { exactAmount, missingReason, pointWarnings } from './valueHistoryLabels';

function tooltipDetail(point: PortfolioValuationHistoryPoint): string {
  const quality = summaryQualityLabel[point.priceQuality];
  return [
    `Saisie : ${detailLabel[point.detail]}`,
    ...(quality === null ? [] : [`Prix : ${quality}`]),
    ...pointWarnings(point),
  ].join(' · ');
}

/**
 * One point per valuation: the server strings are placed as is and a valuation without total
 * breaks the line instead of falling to zero.
 */
export function ValueHistoryChart({
  assetCode,
  locale,
  points,
}: {
  assetCode: string;
  locale: string;
  points: PortfolioValuationHistoryPoint[];
}) {
  const curvePoints: CurvePoint[] = points.map((point) => {
    const date = formatCalendarNumericDay(point.valuedOn);
    const total = point.totalValue === null ? null : exactAmount(point.totalValue, locale);
    return {
      axisLabel: date,
      geometryValue: point.totalValue?.value ?? null,
      hitLabel: total === null ? date : `${date}, ${total}`,
      key: point.valuationId,
      tooltipTitle: <time dateTime={point.valuedOn}>{date}</time>,
      tooltipDetail: tooltipDetail(point),
      value:
        total === null ? <EmptyValue label={missingReason(point)} /> : <MoneyValue value={total} />,
    };
  });

  return (
    <CurveChart
      label="Évolution de la valeur totale du portefeuille"
      points={curvePoints}
      timeAxisLabel="Date de valorisation"
      valueAxisLabel={`Valeur totale en ${assetCode}`}
    />
  );
}
