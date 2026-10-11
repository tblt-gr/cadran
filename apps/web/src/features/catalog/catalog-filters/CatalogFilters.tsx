import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { BusinessDateField } from '@/components/ui/business-date-field/BusinessDateField';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import styles from './CatalogFilters.module.css';

interface CatalogFiltersProps {
  asOf: string;
  disabled?: boolean;
  onChange: (value: string) => void;
  today: string;
}

/** The business date lives behind one header button; a non-default date counts as an active filter. */
export function CatalogFilters({ asOf, disabled = false, onChange, today }: CatalogFiltersProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const trigger = useRef<HTMLButtonElement>(null);
  const activeCount = Number(asOf !== today);

  return (
    <>
      <HeaderActions>
        <button
          aria-label={t('catalog.filters.open')}
          className={`icon-button ${styles.trigger}`}
          disabled={disabled}
          onClick={() => setOpen(true)}
          ref={trigger}
          title={t('catalog.filters.open')}
          type="button"
        >
          <Icon name="filter" />
          {activeCount > 0 ? (
            <span className={styles.badge} data-testid="catalog-filters-count">
              {activeCount}
            </span>
          ) : null}
        </button>
      </HeaderActions>

      {open ? (
        <Modal
          close={() => setOpen(false)}
          returnFocus={trigger}
          title={t('catalog.filters.title')}
        >
          <BusinessDateField onChange={onChange} today={today} value={asOf} />
        </Modal>
      ) : null}
    </>
  );
}
