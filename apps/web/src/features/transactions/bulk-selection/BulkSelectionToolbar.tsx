import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BulkEditModal } from './bulk-edit-modal/BulkEditModal';
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
   * The host places the pen itself (`BulkSelectionPen`, on its filter row) and this toolbar
   * only renders the selection controls, wherever the host puts them.
   */
  inline?: boolean;
}

/** The mode switch: "Sélectionner" to enter, "Quitter" to leave. */
export function BulkSelectionPen({
  active,
  bulk,
  onToggleActive,
  inline = false,
}: Omit<BulkSelectionToolbarProps, 'inline'> & { inline?: boolean }) {
  const { t } = useTranslation();
  return (
    <button
      aria-label={t(active ? 'transactions.bulk.exit' : 'transactions.bulk.selectMode')}
      aria-pressed={active}
      className={
        inline
          ? `secondary-action ${styles.pen} ${styles.penInline}`
          : `secondary-action ${styles.pen}`
      }
      disabled={!active && bulk === null}
      onClick={onToggleActive}
      title={t(active ? 'transactions.bulk.exit' : 'transactions.bulk.selectMode')}
      type="button"
    >
      <span>{t(active ? 'transactions.bulk.exitShort' : 'transactions.bulk.selectShort')}</span>
    </button>
  );
}

/**
 * Bulk edit mode. On its own row the pen stays at the right and the selection controls sit
 * beside it. Inline, the host renders the pen and this toolbar is only the controls' line.
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
      {!bulk.filterScopeSelected ? (
        <span className={styles.scope}>
          {t('transactions.bulk.loadedScope', { count: bulk.pageCount })}
        </span>
      ) : null}
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
        {t('transactions.bulk.editAction')}
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
      <BulkEditModal
        close={(applied) => {
          setModalOpen(false);
          if (applied) bulk.clear();
        }}
        pageItems={bulk.pageItems}
        reloadAfterWrite={bulk.reloadAfterWrite}
        scope={bulk.scope}
      />
    ) : null;
  const pen = <BulkSelectionPen active={active} bulk={bulk} onToggleActive={onToggleActive} />;

  if (inline) {
    return (
      <>
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
