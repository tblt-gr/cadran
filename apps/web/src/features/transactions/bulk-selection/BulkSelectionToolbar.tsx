import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { BulkCategoryModal } from './bulk-category-modal/BulkCategoryModal';
import type { BulkSelection } from './useBulkSelection';
import styles from './BulkSelectionToolbar.module.css';

interface BulkSelectionToolbarProps {
  /** Whether the bulk edit mode is on. */
  active: boolean;
  /** Null while the list loads: the pen shows as disabled chrome, nothing else. */
  bulk: BulkSelection | null;
  /** Turns the mode on or off; the host drops the selection when it goes off. */
  onToggleActive: () => void;
  /**
   * Pen stays on the filter row. Selection and the category action take the next full line.
   * The host must leave this toolbar as a direct child of that row (`display: contents`).
   */
  inline?: boolean;
}

/**
 * Bulk edit mode. On its own row the pen stays at the right and the selection controls sit
 * beside it. Inline, the pen stays on the filter row and those controls take the next line.
 */
export function BulkSelectionToolbar({
  active,
  bulk,
  onToggleActive,
  inline = false,
}: BulkSelectionToolbarProps) {
  const { t } = useTranslation();
  const [modalOpen, setModalOpen] = useState(false);
  const editing = active && bulk !== null;
  const controls = editing ? (
    <>
      <label className={styles.selectAll}>
        <input
          checked={bulk.allPageSelected}
          disabled={bulk.pageCount === 0}
          onChange={() => bulk.togglePage()}
          ref={(element) => {
            if (element) {
              element.indeterminate = bulk.somePageSelected && !bulk.allPageSelected;
            }
          }}
          type="checkbox"
        />
        <span className="sr-only">{t('transactions.bulk.selectPage')}</span>
      </label>
      <p aria-live="polite" className={styles.counter}>
        {bulk.selectedCount === 0
          ? t('transactions.bulk.none')
          : t('transactions.bulk.selected', { count: bulk.selectedCount })}
      </p>
      <button
        className={styles.action}
        disabled={bulk.selectedCount === 0}
        onClick={() => setModalOpen(true)}
        type="button"
      >
        {t('transactions.bulk.changeCategory')}
      </button>
    </>
  ) : null;
  const banner =
    editing && bulk.filterScopeSelected ? (
      <p className={styles.banner} role="status">
        {t('transactions.bulk.filterScopeSelected', { count: bulk.selectedCount })}
      </p>
    ) : editing && bulk.offerFilterScope && bulk.matchingCount !== null ? (
      <p className={styles.banner}>
        <span>{t('transactions.bulk.pageSelected', { count: bulk.pageCount })}</span>
        <button className="secondary-action" onClick={bulk.selectFilterScope} type="button">
          {t('transactions.bulk.selectFilterScope', { count: bulk.matchingCount })}
        </button>
      </p>
    ) : null;
  const modal =
    modalOpen && editing && bulk.scope ? (
      <BulkCategoryModal
        close={(applied) => {
          setModalOpen(false);
          if (applied) bulk.clear();
        }}
        pageItems={bulk.pageItems}
        reloadAfterWrite={bulk.reloadAfterWrite}
        scope={bulk.scope}
      />
    ) : null;
  const pen = (
    <button
      aria-label={t('transactions.bulk.enter')}
      aria-pressed={active}
      className={
        inline ? `icon-ghost ${styles.pen} ${styles.penInline}` : `icon-ghost ${styles.pen}`
      }
      disabled={bulk === null}
      onClick={onToggleActive}
      title={t('transactions.bulk.enter')}
      type="button"
    >
      <Icon name="edit" size={18} />
    </button>
  );

  if (inline) {
    return (
      <>
        {pen}
        {editing ? (
          <div
            aria-label={t('transactions.bulk.toolbar')}
            className={styles.below}
            data-bulk-controls
            role="group"
          >
            <div className={styles.controls}>{controls}</div>
            {banner}
          </div>
        ) : null}
        {modal}
      </>
    );
  }

  return (
    <div className={styles.toolbar} role="group" aria-label={t('transactions.bulk.toolbar')}>
      <div className={styles.row}>
        <div className={styles.controls}>{controls}</div>
        {pen}
      </div>
      {banner}
      {modal}
    </div>
  );
}
