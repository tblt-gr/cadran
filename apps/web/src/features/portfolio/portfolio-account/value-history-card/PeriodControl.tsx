import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import { HISTORY_PERIODS, type HistoryPeriod } from './valueHistoryRange';

export function PeriodControl({
  disabled = false,
  onChange,
  value,
}: {
  disabled?: boolean;
  onChange: (period: HistoryPeriod) => void;
  value: HistoryPeriod;
}) {
  return (
    <SegmentedControl
      disabled={disabled}
      label="Période"
      onChange={onChange}
      options={HISTORY_PERIODS}
      value={value}
    />
  );
}
