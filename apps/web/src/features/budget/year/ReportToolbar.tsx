import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { YearRangeSelector } from '@/features/reports/multi-year/YearRangeSelector';
import { ReportPreferencesControls } from '@/features/budget/year/report-preferences-controls/ReportPreferencesControls';
import { ReportViewSwitch } from './ReportViewSwitch';
import type { YearView } from './yearViewSearch';
import styles from './ReportToolbar.module.css';

/** What only the multi-year view adds to the row: its year range. */
interface MultiYearControls {
  expanded: boolean;
  maxYear: number;
  minYear: number;
  onExpandedChange: (expanded: boolean) => void;
  onRangeChange: (range: { from: number; to: number }) => void;
  /** Null while the default bounds are still loading: the selector waits for them. */
  range: { from: number; to: number } | null;
  /** Whether the change against the previous year shows under each yearly figure. */
  showChanges: boolean;
  onShowChangesChange: (shown: boolean) => void;
}

interface ReportToolbarProps {
  multi?: MultiYearControls;
  onViewChange: (view: YearView) => void;
  /** The multi-year view needs at least two years of data. */
  multiYearAvailable: boolean;
  view: YearView;
}

/**
 * One quiet row: the annual or multi-year switch, the year range of the
 * multi-year view, then the column picker (stored as a workspace preference).
 */
export function ReportToolbar({
  multi,
  multiYearAvailable,
  onViewChange,
  view,
}: ReportToolbarProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.toolbar}>
      <ReportViewSwitch
        multiYearAvailable={multiYearAvailable}
        onViewChange={onViewChange}
        view={view}
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
          <>
            <button
              aria-label={t(
                multi.expanded ? 'reports.multiYear.collapseAll' : 'reports.multiYear.expandAll',
              )}
              className="icon-ghost"
              onClick={() => multi.onExpandedChange(!multi.expanded)}
              title={t(
                multi.expanded ? 'reports.multiYear.collapseAll' : 'reports.multiYear.expandAll',
              )}
              type="button"
            >
              <Icon name={multi.expanded ? 'chevrons-up' : 'chevrons-down'} size={16} />
            </button>
            <button
              aria-label={t('reports.multiYear.changesToggle')}
              aria-pressed={multi.showChanges}
              className={`icon-ghost ${styles.changes}`}
              onClick={() => multi.onShowChangesChange(!multi.showChanges)}
              title={t('reports.multiYear.changesToggle')}
              type="button"
            >
              <Icon name="chart-line" size={16} />
            </button>
          </>
        ) : null}
        <ReportPreferencesControls />
      </div>
    </div>
  );
}
