import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import { Toast } from '@/components/ui/toast/Toast';
import { ColumnPickerModal } from '@/features/reports/annual/ColumnPickerModal';
import { useAnnualReportPreferences } from '@/features/reports/annual/useAnnualReport';
import { YearRangeSelector } from '@/features/reports/multi-year/YearRangeSelector';
import { DisplayModeSwitch } from './DisplayModeSwitch';
import type { MultiYearDisplay, YearView } from './yearViewSearch';
import styles from './ReportToolbar.module.css';

/** What only the multi-year view adds to the row: its year range and its display mode. */
interface MultiYearControls {
  display: MultiYearDisplay;
  maxYear: number;
  minYear: number;
  onDisplayChange: (display: MultiYearDisplay) => void;
  onRangeChange: (range: { from: number; to: number }) => void;
  /** Null while the default bounds are still loading: the selector waits for them. */
  range: { from: number; to: number } | null;
}

interface ReportToolbarProps {
  multi?: MultiYearControls;
  onViewChange: (view: YearView) => void;
  /** The multi-year view needs at least two years of data. */
  multiYearAvailable: boolean;
  view: YearView;
}

/**
 * One quiet row: the annual or multi-year switch, the year range and display mode of the
 * multi-year view, then the column picker (stored as a workspace preference).
 */
export function ReportToolbar({
  multi,
  multiYearAvailable,
  onViewChange,
  view,
}: ReportToolbarProps) {
  const { t } = useTranslation();
  const preferences = useAnnualReportPreferences();
  const [pickerOpen, setPickerOpen] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  return (
    <div className={styles.toolbar}>
      <SegmentedControl
        label={t('budget.year.view.label')}
        onChange={onViewChange}
        options={[
          { value: 'annual', label: t('budget.year.view.annual') },
          {
            value: 'multi',
            label: t('budget.year.view.multi'),
            disabled: !multiYearAvailable && view !== 'multi',
            description: multiYearAvailable ? undefined : t('budget.year.view.multiNeedsHistory'),
          },
        ]}
        value={view}
        variant="text"
      />
      {multi?.range ? (
        <YearRangeSelector
          from={multi.range.from}
          maxYear={multi.maxYear}
          minYear={multi.minYear}
          onChange={multi.onRangeChange}
          to={multi.range.to}
        />
      ) : null}
      <div className={styles.actions}>
        {multi ? (
          <DisplayModeSwitch onChange={multi.onDisplayChange} value={multi.display} />
        ) : null}
        <button
          aria-label={t('reports.annual.columns')}
          className={`icon-ghost ${styles.button}`}
          disabled={!preferences.data}
          onClick={() => setPickerOpen(true)}
          title={t('reports.annual.columns')}
          type="button"
        >
          <Icon name="columns" size={16} />
        </button>
      </div>
      {pickerOpen && preferences.data ? (
        <ColumnPickerModal
          close={() => setPickerOpen(false)}
          onConflict={() => setNotice(t('reports.annual.conflict'))}
          preferences={preferences.data}
        />
      ) : null}
      {notice ? <Toast onDismiss={() => setNotice(null)}>{notice}</Toast> : null}
    </div>
  );
}
