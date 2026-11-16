import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { AnnualReportPreferences } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { Tabs } from '@/components/ui/tabs/Tabs';
import { CatalogueColumnOptions, type CatalogueData } from './CatalogueColumnOptions';
import { ColumnOptions } from './ColumnOptions';
import { SelectedColumns, type SelectedColumn } from './SelectedColumns';
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
  const [goalMarks, setGoalMarks] = useState(preferences.goalMarks);
  const full = selected.length >= MAX_ANNUAL_COLUMNS;
  const failed =
    save.isError && !(save.error instanceof AnnualRequestError && save.error.status === 409);

  function toggle(id: string) {
    setSelected((current) =>
      current.includes(id) ? current.filter((item) => item !== id) : [...current, id],
    );
  }
  function move(index: number, target: number) {
    setSelected((current) => {
      if (target < 0 || target >= current.length) return current;
      const next = [...current];
      const [id] = next.splice(index, 1);
      next.splice(target, 0, id!);
      return next;
    });
  }
  /** The column behind a selected id; null while the workspace entries load. */
  function columnOf(id: string): SelectedColumn | null {
    const [type = '', reference = ''] = id.split(':', 2);
    if (!id.includes(':') || type === 'axis') return { id, label: reference || id };
    if (!catalogue.data) {
      return catalogue.isError ? { id, label: t('reports.annual.picker.unknown') } : null;
    }
    const entries =
      type === 'category'
        ? catalogue.data.categories
        : type === 'account'
          ? catalogue.data.accounts
          : catalogue.data.groups;
    const entry = entries.find((candidate) => candidate.id === reference);
    return entry
      ? {
          id,
          institution: entry.institution,
          label: entry.label,
          parentLabel: entry.parentLabel,
        }
      : { id, label: t('reports.annual.picker.unknown') };
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    save.mutate(
      {
        columns: selected,
        incompleteMonths: preferences.incompleteMonths,
        goalMarks,
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
  const columnOptions = (prefix: string, entries: CatalogueEntry[]) => (
    <ColumnOptions
      entries={entries}
      full={full}
      onToggle={toggle}
      prefix={prefix}
      selected={selected}
    />
  );
  const catalogueOptions = (
    prefix: string,
    pick: (data: CatalogueData) => CatalogueEntry[],
    tree = false,
  ) => (
    <CatalogueColumnOptions
      catalogue={catalogue}
      full={full}
      onToggle={toggle}
      pick={pick}
      prefix={prefix}
      selected={selected}
      tree={tree}
    />
  );

  return (
    <Modal close={close} intent="settings" title={t('reports.annual.picker.title')} wide>
      <ModalForm className={styles.form} onSubmit={submit}>
        <p aria-live="polite">{`${selected.length} / ${MAX_ANNUAL_COLUMNS}`}</p>
        {failed ? <p role="alert">{t('reports.annual.picker.error')}</p> : null}
        <div className={styles.layout}>
          <div className={styles.choices}>
            <Tabs
              label={t('reports.annual.picker.title')}
              tabs={[
                {
                  id: 'indicators',
                  label: t('reports.annual.picker.indicators'),
                  content: columnOptions(
                    '',
                    INDICATOR_COLUMN_IDS.map((id) => ({
                      id,
                      label: t(`reports.annual.indicators.${id}`),
                    })),
                  ),
                },
                {
                  id: 'axes',
                  label: t('reports.annual.picker.axes'),
                  content: columnOptions(
                    'axis:',
                    AXIS_IDS.map((id) => ({ id, label: t(`categories.axes.${id}`) })),
                  ),
                },
                {
                  id: 'categories',
                  label: t('reports.annual.picker.categories'),
                  content: catalogueOptions('category:', (data) => data.categories, true),
                },
                {
                  id: 'accounts',
                  label: t('reports.annual.picker.accounts'),
                  content: catalogueOptions('account:', (data) => data.accounts),
                },
                {
                  id: 'groups',
                  label: t('reports.annual.picker.groups'),
                  content: catalogueOptions('group:', (data) => data.groups),
                },
              ]}
            />
            <label className={styles.goalMarks}>
              <input
                checked={goalMarks}
                onChange={(event) => setGoalMarks(event.target.checked)}
                type="checkbox"
              />
              <span>{t('reports.annual.picker.goalMarks')}</span>
            </label>
          </div>
          <SelectedColumns
            columnOf={columnOf}
            onMove={move}
            onRemove={toggle}
            selected={selected}
          />
        </div>
        <ModalActions>
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
        </ModalActions>
      </ModalForm>
    </Modal>
  );
}
