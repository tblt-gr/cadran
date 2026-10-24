import { useTranslation } from 'react-i18next';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import { sortChartPeriods, type ChartPeriod } from '@/lib/chartPeriodRange';

interface ChartPeriodControlProps<P extends ChartPeriod> {
  /** Accessible name of the group, for instance "Période du graphique". */
  label: string;
  disabled?: boolean;
  onChange: (period: P) => void;
  /** Presets this chart supports; display order is canonical, not the caller's. */
  periods: readonly P[];
  value: P;
}

/** Controlled preset selector shared by every configurable chart; features map presets to their API and URL. */
export function ChartPeriodControl<P extends ChartPeriod>({
  label,
  disabled = false,
  onChange,
  periods,
  value,
}: ChartPeriodControlProps<P>) {
  const { t } = useTranslation();
  const options = (sortChartPeriods(periods) as P[]).map((period) => ({
    value: period,
    label: t(`chartPeriod.periods.${period}`),
    description: period === 'YTD' ? t('chartPeriod.descriptions.YTD') : undefined,
  }));
  return (
    <SegmentedControl
      disabled={disabled}
      label={label}
      onChange={onChange}
      options={options}
      value={value}
    />
  );
}
