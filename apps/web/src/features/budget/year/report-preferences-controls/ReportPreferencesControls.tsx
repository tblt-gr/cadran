import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { Toast } from '@/components/ui/toast/Toast';
import { ColumnPickerModal } from '@/features/reports/annual/ColumnPickerModal';
import {
  AnnualRequestError,
  useAnnualReportPreferences,
  useSaveAnnualReportPreferences,
} from '@/features/reports/annual/useAnnualReport';
import styles from './ReportPreferencesControls.module.css';

/** Both entry points edit one versioned preference, so a toolbar save cannot open a stale draft. */
export function ReportPreferencesControls() {
  const { t } = useTranslation();
  const preferences = useAnnualReportPreferences();
  const save = useSaveAnnualReportPreferences();
  const [pickerOpen, setPickerOpen] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [attempted, setAttempted] = useState<boolean | null>(null);
  const busy = save.isPending || preferences.isFetching;
  const disabled = !preferences.data || preferences.isError || busy || pickerOpen;
  const failed =
    save.isError && !(save.error instanceof AnnualRequestError && save.error.status === 409);

  function updateMarks(goalMarks: boolean) {
    if (!preferences.data || busy || pickerOpen) return;
    setAttempted(goalMarks);
    save.mutate(
      {
        columns: preferences.data.columns,
        incompleteMonths: preferences.data.incompleteMonths,
        goalMarks,
        version: preferences.data.version,
      },
      {
        onSuccess: () => setAttempted(null),
        onError: (error) => {
          if (error instanceof AnnualRequestError && error.status === 409) {
            setAttempted(null);
            setNotice(t('reports.annual.conflict'));
          }
        },
      },
    );
  }

  return (
    <div className={styles.controls}>
      <div className={styles.buttons}>
        <button
          aria-busy={save.isPending || undefined}
          aria-pressed={preferences.data?.goalMarks === true}
          className={`secondary-action ${styles.goalMarks}`}
          disabled={disabled}
          onClick={() => updateMarks(preferences.data?.goalMarks !== true)}
          type="button"
        >
          <Icon name="goals" size={16} />
          <span>{t('reports.annual.goalMarksToggle')}</span>
        </button>
        <button
          aria-label={t('reports.annual.columns')}
          className={`icon-ghost ${styles.columns}`}
          disabled={disabled}
          onClick={() => setPickerOpen(true)}
          title={t('reports.annual.columns')}
          type="button"
        >
          <Icon name="columns" size={16} />
        </button>
      </div>
      {preferences.isError ? (
        <div className={styles.error} role="alert">
          <p>{t('reports.annual.preferencesError')}</p>
          <button
            className="secondary-action"
            disabled={busy}
            onClick={() => void preferences.refetch()}
            type="button"
          >
            {t('reports.retry')}
          </button>
        </div>
      ) : failed && attempted !== null ? (
        <div className={styles.error} role="alert">
          <p>{t('reports.annual.goalMarksError')}</p>
          <button
            className="secondary-action"
            disabled={disabled}
            onClick={() => updateMarks(attempted)}
            type="button"
          >
            {t('reports.retry')}
          </button>
        </div>
      ) : null}
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
