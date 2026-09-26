import type { AnnualReportPreferences } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { AXIS_IDS, INDICATOR_COLUMN_IDS, MAX_ANNUAL_COLUMNS } from './annualColumns';
import { AnnualRequestError, useSaveAnnualReportPreferences } from './useAnnualReport';
import { useColumnCatalogue, type CatalogueEntry } from './useColumnCatalogue';
import styles from './ColumnPickerModal.module.css';

interface ColumnPickerModalProps {
  close: () => void;
  onConflict: () => void;
  preferences: AnnualReportPreferences;
}

export function ColumnPickerModal({ close, onConflict, preferences }: ColumnPickerModalProps) {
  const { t } = useTranslation();
  const save = useSaveAnnualReportPreferences();
  const catalogue = useColumnCatalogue(true);
  const [selected, setSelected] = useState<string[]>(preferences.columns);
  const full = selected.length >= MAX_ANNUAL_COLUMNS;
  const failed =
    save.isError && !(save.error instanceof AnnualRequestError && save.error.status === 409);

  function toggle(id: string) {
    setSelected((current) =>
      current.includes(id) ? current.filter((item) => item !== id) : [...current, id],
    );
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    save.mutate(
      {
        columns: selected,
        incompleteMonths: preferences.incompleteMonths,
        version: preferences.version,
      },
      {
        onSuccess: close,
        onError: (error) => {
          if (error instanceof AnnualRequestError && error.status === 409) {
            onConflict();
            close();
          }
        },
      },
    );
  }
  function group(legend: string, prefix: string, entries: CatalogueEntry[]) {
    return (
      <fieldset key={legend}>
        <legend>{legend}</legend>
        {entries.length === 0 ? <p>{t('reports.annual.picker.none')}</p> : null}
        <div className={styles.options}>
          {entries.map((entry) => {
            const id = `${prefix}${entry.id}`;
            const checked = selected.includes(id);
            return (
              <label key={id}>
                <input
                  checked={checked}
                  disabled={!checked && full}
                  onChange={() => toggle(id)}
                  type="checkbox"
                />
                {entry.label}
              </label>
            );
          })}
        </div>
      </fieldset>
    );
  }

  return (
    <Modal
      close={close}
      eyebrow={t('reports.annual.title')}
      title={t('reports.annual.picker.title')}
    >
      <form className={styles.form} onSubmit={submit}>
        <p aria-live="polite">{`${selected.length} / ${MAX_ANNUAL_COLUMNS}`}</p>
        {failed ? <p role="alert">{t('reports.annual.picker.error')}</p> : null}
        {group(
          t('reports.annual.picker.indicators'),
          '',
          INDICATOR_COLUMN_IDS.map((id) => ({ id, label: t(`reports.annual.indicators.${id}`) })),
        )}
        {group(
          t('reports.annual.picker.axes'),
          'axis:',
          AXIS_IDS.map((id) => ({ id, label: t(`categories.axes.${id}`) })),
        )}
        {catalogue.isPending ? (
          <p role="status">{t('reports.annual.picker.loading')}</p>
        ) : catalogue.isError ? (
          <div role="alert">
            <p>{t('reports.annual.picker.catalogueError')}</p>
            <button
              className="secondary-action"
              onClick={() => void catalogue.refetch()}
              type="button"
            >
              {t('reports.retry')}
            </button>
          </div>
        ) : (
          <>
            {group(t('reports.annual.picker.categories'), 'category:', catalogue.data.categories)}
            {group(t('reports.annual.picker.groups'), 'group:', catalogue.data.groups)}
            {group(t('reports.annual.picker.accounts'), 'account:', catalogue.data.accounts)}
          </>
        )}
        <div className={styles.actions}>
          <button className="secondary-action" onClick={close} type="button">
            {t('actions.cancel')}
          </button>
          <button
            className="primary-action"
            disabled={save.isPending || selected.length === 0}
            type="submit"
          >
            {t('reports.annual.picker.save')}
          </button>
        </div>
      </form>
    </Modal>
  );
}
