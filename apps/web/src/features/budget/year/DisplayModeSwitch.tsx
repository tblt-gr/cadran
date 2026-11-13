import { useTranslation } from 'react-i18next';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import type { MultiYearDisplay } from './yearViewSearch';

/** Compact or detailed multi-year table, on the same track as the other view switches. */
export function DisplayModeSwitch({
  onChange,
  value,
}: {
  onChange: (display: MultiYearDisplay) => void;
  value: MultiYearDisplay;
}) {
  const { t } = useTranslation();

  return (
    <SegmentedControl
      label={t('reports.multiYear.displayMode')}
      onChange={onChange}
      options={[
        { value: 'compact', label: t('reports.multiYear.compact') },
        { value: 'detailed', label: t('reports.multiYear.detailed') },
      ]}
      value={value}
    />
  );
}
