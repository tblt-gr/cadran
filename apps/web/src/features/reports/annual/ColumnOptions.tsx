import { useTranslation } from 'react-i18next';
import { ColumnHeader } from './ColumnHeader';
import type { CatalogueEntry } from './useColumnCatalogue';
import styles from './ColumnPickerModal.module.css';

interface ColumnOptionsProps {
  entries: CatalogueEntry[];
  full: boolean;
  prefix: string;
  selected: string[];
  onToggle: (id: string) => void;
}

/** One checkbox per selectable column; unchecked boxes lock once the column limit is reached. */
export function ColumnOptions({ entries, full, prefix, selected, onToggle }: ColumnOptionsProps) {
  const { t } = useTranslation();

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
                aria-label={entry.path}
                checked={checked}
                disabled={!checked && full}
                onChange={() => onToggle(id)}
                type="checkbox"
              />
              <span>
                <ColumnHeader
                  inlineParent
                  column={{
                    id,
                    institution: entry.institution,
                    label: entry.label,
                    parentLabel: entry.parentLabel,
                  }}
                />
              </span>
            </label>
          );
        })}
      </div>
    </>
  );
}
