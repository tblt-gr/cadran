import type { AccountValuationHistoryPoint } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { CurveChart } from '@/components/ui/charts/curve-chart/CurveChart';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { historyAmount, historyQuality, historyProvenance } from './savingsHistoryLabels';

export function SavingsHistoryChart({ points }: { points: AccountValuationHistoryPoint[] }) {
  const { t, i18n } = useTranslation();
  if (points.every((point) => point.amount === null))
    return <p role="status">{t('accounts.history.nonCalculable')}</p>;
  return (
    <CurveChart
      interpolation="step-after"
      label={t('accounts.history.title')}
      timeAxisLabel={t('accounts.history.date')}
      valueAxisLabel={t('accounts.history.balance')}
      points={points.map((point) => ({
        key: point.date,
        date: point.date,
        axisLabel: formatCalendarNumericDay(point.date),
        geometryValue: point.amount,
        hitLabel: `${formatCalendarNumericDay(point.date)}, ${historyAmount(point, i18n.language)}, ${historyQuality(point, t)}`,
        tooltipTitle: formatCalendarNumericDay(point.date),
        value: historyAmount(point, i18n.language),
        tooltipDetail: `${historyQuality(point, t)} · ${historyProvenance(point, t)}`,
      }))}
    />
  );
}
