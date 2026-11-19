import type { PortfolioValuationHistoryPoint } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { CurveChart, type CurvePoint } from '@/components/ui/charts/curve-chart/CurveChart';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import {
  detailLabel,
  exactAmount,
  missingReason,
  pointWarnings,
  priceQualityLabel,
} from './valueHistoryLabels';

function tooltipDetail(t: TFunction, point: PortfolioValuationHistoryPoint): string {
  const quality = priceQualityLabel(t, point.priceQuality);
  return [
    t('portfolio.account.history.tooltipEntry', { detail: detailLabel(t, point.detail) }),
    ...(quality === null ? [] : [t('portfolio.account.history.tooltipPrice', { quality })]),
    ...pointWarnings(t, point),
  ].join(' · ');
}

/** `exact` keeps every stored digit (account page); `display` rounds to two decimals (cards). */
export type HistoryAmountFormat = 'exact' | 'display';

/**
 * One point per valuation: the server strings are placed as is and a valuation without total
 * breaks the line instead of falling to zero.
 */
export function ValueHistoryChart({
  assetCode,
  locale,
  points,
  amountFormat = 'exact',
}: {
  amountFormat?: HistoryAmountFormat;
  assetCode: string;
  locale: string;
  points: PortfolioValuationHistoryPoint[];
}) {
  const { t } = useTranslation();
  const curvePoints: CurvePoint[] = points.map((point) => {
    const date = formatCalendarNumericDay(point.valuedOn);
    const total =
      point.totalValue === null
        ? null
        : amountFormat === 'display'
          ? formatAmount(point.totalValue.value, point.totalValue.assetCode, locale)
          : exactAmount(point.totalValue, locale);
    return {
      axisLabel: date,
      geometryValue: point.totalValue?.value ?? null,
      hitLabel: total === null ? date : `${date}, ${total}`,
      key: point.valuationId,
      tooltipTitle: <time dateTime={point.valuedOn}>{date}</time>,
      tooltipDetail: tooltipDetail(t, point),
      value:
        total === null ? (
          <EmptyValue label={missingReason(t, point)} />
        ) : (
          <MoneyValue value={total} />
        ),
    };
  });

  return (
    <CurveChart
      label={t('portfolio.account.history.chartLabel')}
      points={curvePoints}
      timeAxisLabel={t('portfolio.account.history.timeAxis')}
      valueAxisLabel={t('portfolio.account.history.valueAxis', { assetCode })}
    />
  );
}
