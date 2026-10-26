import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BulkCategoryModal } from './bulk-category-modal/BulkCategoryModal';
import type { BulkSelection } from './useBulkSelection';
import styles from './BulkSelectionToolbar.module.css';

interface BulkSelectionToolbarProps {
  /** Null while the list loads: the same chrome shows, disabled, so the layout holds. */
  bulk: BulkSelection | null;
}

/** Select-all, selection counter, the filter-wide scope offer and the bulk actions. */
export function BulkSelectionToolbar({ bulk }: BulkSelectionToolbarProps) {
  const { t } = useTranslation();
  const [modalOpen, setModalOpen] = useState(false);
  const selected = bulk?.selectedCount ?? 0;

  return (
    <div className={styles.toolbar} role="group" aria-label={t('transactions.bulk.toolbar')}>
      <div className={styles.row}>
        <label className={styles.selectAll}>
          <input
            checked={bulk?.allPageSelected ?? false}
            disabled={bulk === null || bulk.pageCount === 0}
            onChange={() => bulk?.togglePage()}
            ref={(element) => {
              if (element) {
                element.indeterminate =
                  (bulk?.somePageSelected ?? false) && !(bulk?.allPageSelected ?? false);
              }
            }}
            type="checkbox"
          />
          <span className="sr-only">{t('transactions.bulk.selectPage')}</span>
        </label>
        <p aria-live="polite" className={styles.counter}>
          {selected === 0
            ? t('transactions.bulk.none')
            : t('transactions.bulk.selected', { count: selected })}
        </p>
        <button
          className="secondary-action"
          disabled={bulk === null || selected === 0}
          onClick={() => setModalOpen(true)}
          type="button"
        >
          {t('transactions.bulk.changeCategory')}
        </button>
        <button
          className="secondary-action"
          disabled={bulk === null || selected === 0}
          onClick={() => bulk?.clear()}
          type="button"
        >
          {t('transactions.bulk.clear')}
        </button>
      </div>
      {bulk?.filterScopeSelected ? (
        <p className={styles.banner} role="status">
          {t('transactions.bulk.filterScopeSelected', { count: bulk.selectedCount })}
        </p>
      ) : bulk?.offerFilterScope && bulk.matchingCount !== null ? (
        <p className={styles.banner}>
          <span>{t('transactions.bulk.pageSelected', { count: bulk.pageCount })}</span>
          <button className="secondary-action" onClick={bulk.selectFilterScope} type="button">
            {t('transactions.bulk.selectFilterScope', { count: bulk.matchingCount })}
          </button>
        </p>
      ) : null}
      {modalOpen && bulk?.scope ? (
        <BulkCategoryModal
          close={(applied) => {
            setModalOpen(false);
            if (applied) bulk.clear();
          }}
          pageItems={bulk.pageItems}
          reloadAfterWrite={bulk.reloadAfterWrite}
          scope={bulk.scope}
        />
      ) : null}
    </div>
  );
}
