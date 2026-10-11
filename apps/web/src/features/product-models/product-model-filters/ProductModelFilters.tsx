import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import styles from './ProductModelFilters.module.css';

interface ProductModelFiltersProps {
  includeArchived: boolean;
  onIncludeArchivedChange: (checked: boolean) => void;
}

/** Archived models leave the list unless asked for; the toggle sits behind one header button. */
export function ProductModelFilters({
  includeArchived,
  onIncludeArchivedChange,
}: ProductModelFiltersProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const trigger = useRef<HTMLButtonElement>(null);

  return (
    <>
      <button
        aria-label={t('productModels.filters.open')}
        className={`icon-button ${styles.trigger}`}
        onClick={() => setOpen(true)}
        ref={trigger}
        title={t('productModels.filters.open')}
        type="button"
      >
        <Icon name="filter" />
        {includeArchived ? (
          <span className={styles.badge} data-testid="product-model-filters-count">
            1
          </span>
        ) : null}
      </button>

      {open ? (
        <Modal
          close={() => setOpen(false)}
          returnFocus={trigger}
          title={t('productModels.filters.title')}
        >
          <div className={styles.fields}>
            <label>
              <input
                checked={includeArchived}
                onChange={(event) => onIncludeArchivedChange(event.target.checked)}
                type="checkbox"
              />
              <span>{t('productModels.includeArchived')}</span>
            </label>
          </div>
        </Modal>
      ) : null}
    </>
  );
}
