import type { AnnualReportPreferences } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { Tabs } from '@/components/ui/tabs/Tabs';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { AXIS_IDS, INDICATOR_COLUMN_IDS, MAX_ANNUAL_COLUMNS } from './annualColumns';
import { AnnualRequestError, useSaveAnnualReportPreferences } from './useAnnualReport';
import { useColumnCatalogue, type CatalogueEntry } from './useColumnCatalogue';
import styles from './ColumnPickerModal.module.css';

interface CatalogueData {
  accounts: CatalogueEntry[];
  categories: CatalogueEntry[];
  groups: CatalogueEntry[];
}

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
  function options(prefix: string, entries: CatalogueEntry[]) {
    return (
      <>
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
      </>
    );
  }

  /** The workspace's own entries: placeholders while they load, a retry when they failed. */
  function catalogueOptions(prefix: string, pick: (data: CatalogueData) => CatalogueEntry[]) {
    if (catalogue.isPending) {
      return (
        <div aria-busy="true">
          <SkeletonStatus>{t('reports.annual.picker.loading')}</SkeletonStatus>
          <div aria-hidden="true" className={styles.options}>
            {Array.from({ length: 4 }, (_, index) => (
              <SkeletonBlock className={styles.skeletonOption} key={index} />
            ))}
          </div>
        </div>
      );
    }
    if (catalogue.isError) {
      return (
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
      );
    }
    return options(prefix, pick(catalogue.data));
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
        <Tabs
          label={t('reports.annual.picker.title')}
          tabs={[
            {
              id: 'indicators',
              label: t('reports.annual.picker.indicators'),
              content: options(
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
              content: options(
                'axis:',
                AXIS_IDS.map((id) => ({ id, label: t(`categories.axes.${id}`) })),
              ),
            },
            {
              id: 'categories',
              label: t('reports.annual.picker.categories'),
              content: catalogueOptions('category:', (data) => data.categories),
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
