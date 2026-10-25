import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import { Toast } from '@/components/ui/toast/Toast';
import { ColumnPickerModal } from '@/features/reports/annual/ColumnPickerModal';
import {
  AnnualRequestError,
  useAnnualReportPreferences,
  useSaveAnnualReportPreferences,
} from '@/features/reports/annual/useAnnualReport';
import type { YearView } from './yearViewSearch';
import styles from './ReportToolbar.module.css';

interface ReportToolbarProps {
  onViewChange: (view: YearView) => void;
  /** The multi-year view needs at least two years of data. */
  multiYearAvailable: boolean;
  view: YearView;
}

/**
 * Switch between the annual and the multi-year view, next to the report's own settings:
 * the column picker and the incomplete-month switch, both stored as workspace preferences.
 */
export function ReportToolbar({ multiYearAvailable, onViewChange, view }: ReportToolbarProps) {
  const { t } = useTranslation();
  const preferences = useAnnualReportPreferences();
  const save = useSaveAnnualReportPreferences();
  const [pickerOpen, setPickerOpen] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const includeIncomplete = preferences.data?.incompleteMonths === 'include';
  const incompleteLabel = t('reports.annual.incompleteMonths', {
    state: t(includeIncomplete ? 'reports.annual.included' : 'reports.annual.excluded'),
  });

  function toggleIncomplete() {
    if (!preferences.data) return;
    save.mutate(
      {
        columns: preferences.data.columns,
        incompleteMonths: includeIncomplete ? 'exclude' : 'include',
        version: preferences.data.version,
      },
      {
        onError: (error) => {
          if (error instanceof AnnualRequestError && error.status === 409) {
            setNotice(t('reports.annual.conflict'));
          }
        },
      },
    );
  }

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
      />
      <div className={styles.actions}>
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
        <button
          aria-label={incompleteLabel}
          aria-pressed={includeIncomplete}
          className={`icon-ghost ${styles.button}`}
          disabled={save.isPending || !preferences.data}
          onClick={toggleIncomplete}
          title={incompleteLabel}
          type="button"
        >
          <Icon name={includeIncomplete ? 'eye' : 'eye-off'} size={16} />
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
