import type { AnnualNetWorthSeries } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { CurveChart, type CurvePoint } from '@/components/ui/charts/curve-chart/CurveChart';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAnnualValue } from './annualFormat';
import { monthLabel } from './annualRowLabels';

/** Year-end net worth per month, drawn like the dashboard curve from the backend's exact strings. */
export function AnnualNetWorthChart({ netWorth }: { netWorth: AnnualNetWorthSeries }) {
  const { i18n, t } = useTranslation();
  const points: CurvePoint[] = netWorth.months.map((point) => {
    const month = monthLabel(point.month, i18n.language);
    const text =
      point.value.value === null
        ? null
        : formatAnnualValue(point.value.value, 'STOCK', netWorth.assetCode, i18n.language);

    return {
      axisLabel: month,
      geometryValue: point.value.value,
      hitLabel: text === null ? month : `${month}, ${text}`,
      key: point.month,
      tooltipTitle: month,
      value:
        text === null ? (
          <EmptyValue
            label={t('reports.annual.notCalculable')}
            reason={
              point.value.reason
                ? t(`reports.annual.reasons.${point.value.reason}`, {
                    defaultValue: point.value.reason,
                  })
                : null
            }
          />
        ) : (
          text
        ),
    };
  });

  return (
    <CurveChart
      label={t('reports.annual.charts.netWorth')}
      points={points}
      timeAxisLabel={t('reports.annual.month')}
      valueAxisLabel={t('reports.annual.indicators.endNetWorth')}
    />
  );
}
