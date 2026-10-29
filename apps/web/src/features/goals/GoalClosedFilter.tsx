import { useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import styles from './GoalClosedFilter.module.css';

interface Props {
  includeClosed: boolean;
  onIncludeClosedChange: (checked: boolean) => void;
}

/** Closed goals leave the list unless asked for, behind one header button like the account filters. */
export function GoalClosedFilter({ includeClosed, onIncludeClosedChange }: Props) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const countId = useId();
  const trigger = useRef<HTMLButtonElement>(null);

  return (
    <>
      <HeaderActions>
        <button
          aria-describedby={includeClosed ? countId : undefined}
          aria-expanded={open}
          aria-haspopup="dialog"
          aria-label={t('goals.filtersOpen')}
          className={`icon-button ${styles.trigger}`}
          onClick={() => setOpen(true)}
          ref={trigger}
          title={t('goals.filtersOpen')}
          type="button"
        >
          <Icon name="filter" />
          {includeClosed ? (
            <>
              <span aria-hidden="true" className={styles.badge}>
                1
              </span>
              <span className="sr-only" id={countId}>
                {t('goals.filtersActive')}
              </span>
            </>
          ) : null}
        </button>
      </HeaderActions>
      {open ? (
        <Modal close={() => setOpen(false)} returnFocus={trigger} title={t('goals.filtersTitle')}>
          <label className={styles.field}>
            <input
              checked={includeClosed}
              onChange={(event) => onIncludeClosedChange(event.target.checked)}
              type="checkbox"
            />
            <span>{t('goals.includeClosed')}</span>
          </label>
        </Modal>
      ) : null}
    </>
  );
}
