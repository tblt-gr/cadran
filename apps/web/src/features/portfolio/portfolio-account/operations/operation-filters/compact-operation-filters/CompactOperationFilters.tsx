import { useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { ModalSheet } from '@/components/ui/modal-sheet/ModalSheet';
import { ActiveFilterChips } from '@/features/transactions/transaction-filters/active-filter-chips/ActiveFilterChips';
import { FilterPanelHeading } from '@/features/transactions/transaction-filters/filter-panel/filter-panel-heading/FilterPanelHeading';
import {
  OperationFilterInputs,
  type OperationFiltersProps,
} from '@/features/portfolio/portfolio-account/operations/operation-filters/OperationFilterInputs';
import { noFilters } from '@/features/portfolio/portfolio-account/operations/operation-filters/operationFilterValues';
import { useOperationFilterChips } from '@/features/portfolio/portfolio-account/operations/operation-filters/useOperationFilterChips';
import styles from './CompactOperationFilters.module.css';

/** The phone reading of the filters: a "Filtrer" button with the active count, chips, a sheet. */
export function CompactOperationFilters(props: OperationFiltersProps) {
  const { disabled = false, onChange, value } = props;
  const { t } = useTranslation();
  const titleId = useId();
  const toggle = useRef<HTMLButtonElement>(null);
  const [open, setOpen] = useState(false);
  const chips = useOperationFilterChips(value, onChange);
  // The voided toggle sits in the page header and keeps its value through a reset.
  const reset = () => onChange({ ...noFilters, includeVoided: value.includeVoided });
  const close = () => {
    setOpen(false);
    toggle.current?.focus();
  };

  return (
    <section aria-label={t('portfolio.account.filters.label')} className={styles.bar}>
      <button
        aria-expanded={open}
        aria-haspopup="dialog"
        className={`secondary-action ${styles.toggle}`}
        disabled={disabled}
        onClick={() => setOpen(true)}
        ref={toggle}
        type="button"
      >
        <Icon name="filter" size={16} />
        {t('portfolio.account.filters.toggle')}
        {chips.length > 0 ? (
          <span
            aria-label={t('portfolio.account.filters.activeCount', { count: chips.length })}
            className={styles.count}
          >
            {chips.length}
          </span>
        ) : null}
      </button>
      <ActiveFilterChips chips={chips} onReset={reset} />
      {open ? (
        <ModalSheet ariaLabel={t('transactions.filters.panelTitle')} close={close}>
          <FilterPanelHeading
            actions={
              <button
                aria-label={t('transactions.filters.close')}
                className="icon-button"
                onClick={close}
                title={t('transactions.filters.close')}
                type="button"
              >
                <Icon name="close" size={18} />
              </button>
            }
            disabled={disabled}
            onReset={reset}
            titleId={titleId}
          />
          <OperationFilterInputs {...props} />
          <button className={`primary-action ${styles.showResults}`} onClick={close} type="button">
            {t('portfolio.account.filters.showResults')}
          </button>
        </ModalSheet>
      ) : null}
    </section>
  );
}
