import { useTranslation } from 'react-i18next';
import { ChartPeriodControl } from '@/components/ui/chart-period-control/ChartPeriodControl';
import type { NetWorthPeriod } from './useNetWorthPeriod';

interface Props {
  period: NetWorthPeriod;
  onChange?: (period: NetWorthPeriod) => void;
  disabled?: boolean;
}

const PERIODS: readonly NetWorthPeriod[] = ['ALL', 'LAST_12_MONTHS', 'YTD'];

/** The server resolves these presets' calendar; this adapter only picks the supported set. */
export function NetWorthPeriodControl({ period, onChange, disabled = false }: Props) {
  const { t } = useTranslation();
  return (
    <ChartPeriodControl
      disabled={disabled}
      label={t('dashboard.chart.periodLabel')}
      onChange={(next) => onChange?.(next)}
      periods={PERIODS}
      value={period}
    />
  );
}
