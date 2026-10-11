import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import styles from './InstrumentFilters.module.css';

export type InstrumentStatusFilter = 'active' | 'inactive' | 'all';

const STATUSES: InstrumentStatusFilter[] = ['active', 'inactive', 'all'];

interface InstrumentFiltersProps {
  onStatusChange: (status: InstrumentStatusFilter) => void;
  status: InstrumentStatusFilter;
}

/** Status filter behind one header button; "active" is the default and shows no badge. */
export function InstrumentFilters({ onStatusChange, status }: InstrumentFiltersProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const trigger = useRef<HTMLButtonElement>(null);

  return (
    <>
      <HeaderActions>
        <button
          aria-label={t('portfolio.instruments.filters.open')}
          className={`icon-button ${styles.trigger}`}
          onClick={() => setOpen(true)}
          ref={trigger}
          title={t('portfolio.instruments.filters.open')}
          type="button"
        >
          <Icon name="filter" />
          {status !== 'active' ? (
            <span className={styles.badge} data-testid="instrument-filters-count">
              1
            </span>
          ) : null}
        </button>
      </HeaderActions>

      {open ? (
        <Modal
          close={() => setOpen(false)}
          returnFocus={trigger}
          title={t('portfolio.instruments.filters.title')}
        >
          <fieldset className={styles.fields}>
            <legend>{t('portfolio.instruments.filters.status')}</legend>
            {STATUSES.map((value) => (
              <label key={value}>
                <input
                  checked={status === value}
                  name="instrument-status"
                  onChange={() => onStatusChange(value)}
                  type="radio"
                />
                <span>{t(`portfolio.instruments.filters.statuses.${value}`)}</span>
              </label>
            ))}
          </fieldset>
        </Modal>
      ) : null}
    </>
  );
}
