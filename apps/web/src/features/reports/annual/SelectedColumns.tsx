import { useLayoutEffect, useRef, useState, type KeyboardEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { useColumnDrag } from './useColumnDrag';
import { ColumnHeader } from './ColumnHeader';
import { useAnnualColumnLabel } from './columnLabel';
import styles from './SelectedColumns.module.css';

export interface SelectedColumn {
  id: string;
  institution?: string | null;
  label: string;
  parentLabel?: string | null;
}

interface SelectedColumnsProps {
  /** The column behind an id; null while the workspace's own entries are still loading. */
  columnOf: (id: string) => SelectedColumn | null;
  onMove: (index: number, target: number) => void;
  onRemove: (id: string) => void;
  selected: readonly string[];
}

/**
 * The chosen columns in the order the report shows them, one column of rows. Each row can be moved
 * up or down and removed with plain buttons, so the order is reachable by keyboard.
 */
export function SelectedColumns({ columnOf, onMove, onRemove, selected }: SelectedColumnsProps) {
  const { t } = useTranslation();
  const labelOf = useAnnualColumnLabel();

  const [announcement, setAnnouncement] = useState('');
  const pendingFocus = useRef<HTMLButtonElement | null>(null);
  const listRef = useRef<HTMLOListElement>(null);
  function move(index: number, target: number) {
    if (target < 0 || target >= selected.length || index === target) return;
    const column = columnOf(selected[index]!);
    const focused = document.activeElement;
    if (focused instanceof HTMLButtonElement && listRef.current?.contains(focused)) {
      pendingFocus.current = focused;
    }
    onMove(index, target);
    setAnnouncement(
      t('reports.annual.picker.moved', {
        column: column ? labelOf(column) : t('reports.annual.picker.unknown'),
        position: target + 1,
        count: selected.length,
      }),
    );
  }
  const { preview, start, update, finish, cancel, active } = useColumnDrag(selected, move, listRef);
  // Moving a keyed row can detach the focused DOM node. Restore it after React commits the order;
  // at an arrow-button boundary, the same row's handle remains an enabled focus destination.
  useLayoutEffect(() => {
    const previous = pendingFocus.current;
    pendingFocus.current = null;
    if (!previous?.isConnected) return;
    const control = previous.disabled ? previous.closest('li')?.querySelector('button') : previous;
    control?.focus({ preventScroll: true });
    control?.scrollIntoView({ block: 'nearest' });
  }, [selected]);

  function keyMove(event: KeyboardEvent<HTMLButtonElement>, index: number) {
    if (event.key === 'Escape' && active()) {
      event.preventDefault();
      event.stopPropagation();
      cancel();
      return;
    }
    const target =
      event.key === 'Home'
        ? 0
        : event.key === 'End'
          ? selected.length - 1
          : event.key === 'ArrowUp'
            ? index - 1
            : event.key === 'ArrowDown'
              ? index + 1
              : null;
    if (target === null) return;
    event.preventDefault();
    cancel();
    move(index, target);
  }

  return (
    <section aria-labelledby="selected-columns-title" className={styles.section}>
      <h3 id="selected-columns-title">{t('reports.annual.picker.selected')}</h3>
      <p className={styles.help} id="selected-columns-help">
        {t('reports.annual.picker.dragHelp')}
      </p>
      <p aria-atomic="true" className="sr-only" role="status">
        {announcement}
      </p>
      {selected.length === 0 ? (
        <p>{t('reports.annual.picker.selectedNone')}</p>
      ) : (
        <ol className={styles.list} ref={listRef}>
          {selected.map((id, index) => {
            const column = columnOf(id);
            const name = column ? labelOf(column) : t('reports.annual.picker.unknown');
            return (
              <li
                className={styles.row}
                data-column-id={id}
                data-dragging={preview?.id === id || undefined}
                data-drop-before={
                  (preview &&
                    preview.target < selected.indexOf(preview.id) &&
                    index === preview.target) ||
                  undefined
                }
                data-drop-after={
                  (preview &&
                    preview.target > selected.indexOf(preview.id) &&
                    index === preview.target) ||
                  undefined
                }
                key={id}
              >
                <button
                  aria-describedby="selected-columns-help"
                  aria-label={t('reports.annual.picker.drag', { column: name })}
                  className={`icon-ghost ${styles.action} ${styles.handle}`}
                  disabled={selected.length < 2}
                  onKeyDown={(event) => keyMove(event, index)}
                  onLostPointerCapture={cancel}
                  onPointerCancel={cancel}
                  onPointerDown={(event) => start(event, id)}
                  onPointerMove={update}
                  onPointerUp={finish}
                  type="button"
                >
                  <Icon name="reorder" size={14} />
                </button>
                <span className={styles.label}>
                  {column === null ? (
                    <SkeletonBlock className={styles.skeleton} />
                  ) : (
                    <ColumnHeader column={column} inlineParent />
                  )}
                </span>
                <button
                  aria-label={t('reports.annual.picker.moveUp', { column: name })}
                  className={`icon-ghost ${styles.action}`}
                  disabled={index === 0}
                  onClick={() => move(index, index - 1)}
                  type="button"
                >
                  <Icon name="arrow-up" size={14} />
                </button>
                <button
                  aria-label={t('reports.annual.picker.moveDown', { column: name })}
                  className={`icon-ghost ${styles.action}`}
                  disabled={index === selected.length - 1}
                  onClick={() => move(index, index + 1)}
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
