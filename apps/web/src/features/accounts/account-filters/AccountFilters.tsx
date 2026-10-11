import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import styles from './AccountFilters.module.css';

interface AccountFiltersProps {
  includeArchived: boolean;
  includeClosed: boolean;
  onIncludeArchivedChange: (checked: boolean) => void;
  onIncludeClosedChange: (checked: boolean) => void;
}

/**
 * Closed and archived accounts leave the working set unless asked for. The
 * filters live behind one header button that carries the active-filter count.
 */
export function AccountFilters({
  includeArchived,
  includeClosed,
  onIncludeArchivedChange,
  onIncludeClosedChange,
}: AccountFiltersProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const trigger = useRef<HTMLButtonElement>(null);
  const activeCount = Number(includeClosed) + Number(includeArchived);

  return (
    <>
      <HeaderActions>
        <button
          aria-label={t('accounts.filters.open')}
          className={`icon-button ${styles.trigger}`}
          onClick={() => setOpen(true)}
          ref={trigger}
          title={t('accounts.filters.open')}
          type="button"
        >
          <Icon name="filter" />
          {activeCount > 0 ? (
            <span className={styles.badge} data-testid="account-filters-count">
              {activeCount}
            </span>
          ) : null}
        </button>
      </HeaderActions>

      {open ? (
        <Modal
          close={() => setOpen(false)}
          returnFocus={trigger}
          title={t('accounts.filters.title')}
        >
          <div className={styles.fields}>
            <label>
              <input
                checked={includeClosed}
                onChange={(event) => onIncludeClosedChange(event.target.checked)}
                type="checkbox"
              />
              <span>{t('accounts.includeClosed')}</span>
            </label>
            <label>
              <input
                checked={includeArchived}
                onChange={(event) => onIncludeArchivedChange(event.target.checked)}
                type="checkbox"
              />
              <span>{t('accounts.includeArchived')}</span>
            </label>
          </div>
        </Modal>
      ) : null}
    </>
  );
}
