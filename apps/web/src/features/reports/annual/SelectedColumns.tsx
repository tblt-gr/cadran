import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import styles from './SelectedColumns.module.css';

interface SelectedColumnsProps {
  /** Label of a column id; null while the workspace's own entries are still loading. */
  labelOf: (id: string) => string | null;
  onMove: (index: number, offset: -1 | 1) => void;
  onRemove: (id: string) => void;
  selected: readonly string[];
}

/**
 * The chosen columns in the order the report shows them, one column of rows. Each row can be moved
 * up or down and removed with plain buttons, so the order is reachable by keyboard.
 */
export function SelectedColumns({ labelOf, onMove, onRemove, selected }: SelectedColumnsProps) {
  const { t } = useTranslation();

  return (
    <section aria-labelledby="selected-columns-title" className={styles.section}>
      <h3 id="selected-columns-title">{t('reports.annual.picker.selected')}</h3>
      {selected.length === 0 ? (
        <p>{t('reports.annual.picker.selectedNone')}</p>
      ) : (
        <ol className={styles.list}>
          {selected.map((id, index) => {
            const label = labelOf(id);
            const name = label ?? t('reports.annual.picker.unknown');
            return (
              <li className={styles.row} key={id}>
                <span className={styles.label}>
                  {label === null ? <SkeletonBlock className={styles.skeleton} /> : label}
                </span>
                <button
                  aria-label={t('reports.annual.picker.moveUp', { column: name })}
                  className={`icon-ghost ${styles.action}`}
                  disabled={index === 0}
                  onClick={() => onMove(index, -1)}
                  type="button"
                >
                  <Icon name="arrow-up" size={14} />
                </button>
                <button
                  aria-label={t('reports.annual.picker.moveDown', { column: name })}
                  className={`icon-ghost ${styles.action}`}
                  disabled={index === selected.length - 1}
                  onClick={() => onMove(index, 1)}
                  type="button"
                >
                  <Icon name="arrow-down" size={14} />
                </button>
                <button
                  aria-label={t('reports.annual.picker.remove', { column: name })}
                  className={`icon-ghost ${styles.action}`}
                  onClick={() => onRemove(id)}
                  type="button"
                >
                  <Icon name="close" size={14} />
                </button>
              </li>
            );
          })}
        </ol>
      )}
    </section>
  );
}
