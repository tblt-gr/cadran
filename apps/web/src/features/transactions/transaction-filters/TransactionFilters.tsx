import type { Account } from '@cadran/api-client';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { ModalSheet } from '@/components/ui/modal-sheet/ModalSheet';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useMediaQuery } from '@/hooks/use-media-query';
import { usePersistedToggle } from '@/hooks/use-persisted-toggle';
import { ActiveFilterChips } from './active-filter-chips/ActiveFilterChips';
import { FilterPanel } from './filter-panel/FilterPanel';
import type { TransactionFilterState } from './filterState';
import { useFilterChipLabels } from './useFilterChipLabels';
import { useFilterChips } from './useFilterChips';
import styles from './TransactionFilters.module.css';

/** Below this width the column would squeeze the list, so the filters open in a bottom sheet. */
const COMPACT_QUERY = '(max-width: 63.999rem)';
const HIDDEN_STORAGE_KEY = 'cadran.transactions.filters-hidden';

interface TransactionFiltersProps {
  accounts: Account[];
  /** True while the account list is loading: the account section shows skeleton rows. */
  accountsPending?: boolean;
  /** The list column: stepper, tabs and the transactions themselves. */
  children?: ReactNode;
  /** Renders every control inert while the list is first loading. */
  disabled?: boolean;
  filters: TransactionFilterState;
  onChange: (next: TransactionFilterState) => void;
  onReset: () => void;
  /** Changes when the filters are replaced from outside the panel, discarding typed drafts. */
  revision: number;
}

/**
 * Lays the transaction page out around its filters: a filter column beside the list on wide
 * screens (hidden or shown from a round button, remembered on the device), a bottom sheet on
 * narrow ones. The search and the removable chips of every applied filter stay above the list
 * whether the filters are shown or not.
 */
export function TransactionFilters({
  accounts,
  accountsPending = false,
  children,
  disabled = false,
  filters,
  onChange,
  onReset,
  revision,
}: TransactionFiltersProps) {
  const { i18n, t } = useTranslation();
  const asideId = useId();
  const titleId = useId();
  const toggle = useRef<HTMLButtonElement>(null);
  const compact = useMediaQuery(COMPACT_QUERY);
  const [hidden, setHidden] = usePersistedToggle(HIDDEN_STORAGE_KEY);
  const [sheetOpen, setSheetOpen] = useState(false);
  const [rawQuery, setRawQuery] = useState(filters.q);
  const debouncedQuery = useDebouncedValue(rawQuery, 300);

  // Keeps the field in sync when the URL changes from outside (reset, back navigation). The
  // revision matters when the committed query stays the same: a reset during the debounce
  // window must still drop the typed text, or the pending commit would bring it back.
  useEffect(() => {
    setRawQuery(filters.q);
  }, [filters.q, revision]);

  useEffect(() => {
    if (debouncedQuery !== filters.q) {
      onChange({ ...filters, q: debouncedQuery });
    }
    // Only the debounced text should trigger this: reacting to `filters`/`onChange` as well
    // would refire on every unrelated filter change and fight the effect above.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedQuery]);

  const assetCodes = [...new Set(accounts.map((account) => account.assetCode))];
  const labels = useFilterChipLabels({ accounts, filters });
  const chips = useFilterChips({
    ...labels,
    filters,
    language: i18n.language,
    onChange,
    onClearQuery: () => {
      setRawQuery('');
      onChange({ ...filters, q: '' });
    },
    t,
  });
  const activeCount = chips.filter((chip) => chip.key !== 'q').length;
  const asideOpen = !compact && !hidden;

  function panel(actions?: ReactNode, resettable = false) {
    return (
      <FilterPanel
        accounts={accountsPending ? null : accounts}
        actions={actions}
        activeCount={activeCount}
        assetCodes={assetCodes}
        disabled={disabled}
        filters={filters}
        onChange={onChange}
        onReset={resettable ? onReset : undefined}
        revision={revision}
        titleId={titleId}
      />
    );
  }

  return (
    <div className={styles.layout} data-aside={asideOpen ? 'open' : 'closed'}>
      {compact ? null : (
        <aside aria-labelledby={titleId} className={styles.aside} hidden={hidden} id={asideId}>
          {panel()}
        </aside>
      )}

      <div className={styles.main}>
        <section aria-label={t('transactions.filters.sectionLabel')} className={styles.bar}>
          <div className={styles.row}>
            <label className={styles.search}>
              <Icon name="search" size={16} />
              <span className="sr-only">{t('transactions.filters.search')}</span>
              <input
                disabled={disabled}
                maxLength={80}
                onChange={(event) => setRawQuery(event.target.value)}
                placeholder={t('transactions.filters.search')}
                type="search"
                value={rawQuery}
              />
            </label>
            <button
              aria-controls={compact ? undefined : asideId}
              aria-expanded={compact ? sheetOpen : asideOpen}
              aria-haspopup={compact ? 'dialog' : undefined}
              aria-label={
                activeCount > 0
                  ? t('transactions.filters.toggleWithCount', { count: activeCount })
                  : t('transactions.filters.toggle')
              }
              className={`icon-button ${styles.toggle}`}
              data-active={activeCount > 0}
              disabled={disabled}
              onClick={() => (compact ? setSheetOpen(true) : setHidden(!hidden))}
              ref={toggle}
              title={
                compact
                  ? t('transactions.filters.open')
                  : hidden
                    ? t('transactions.filters.show')
                    : t('transactions.filters.hide')
              }
              type="button"
            >
              <Icon name="filter" size={18} />
              {activeCount > 0 ? (
                <span aria-hidden="true" className={styles.badge}>
                  {activeCount}
                </span>
              ) : null}
            </button>
          </div>

          <ActiveFilterChips chips={chips} onReset={onReset} />
        </section>

        {children}
      </div>

      {compact && sheetOpen ? (
        <ModalSheet
          ariaLabel={t('transactions.filters.panelTitle')}
          close={() => setSheetOpen(false)}
        >
          {panel(
            <button
              aria-label={t('transactions.filters.close')}
              className="icon-button"
              onClick={() => {
                setSheetOpen(false);
                toggle.current?.focus();
              }}
              title={t('transactions.filters.close')}
              type="button"
            >
              <Icon name="close" size={18} />
            </button>,
            true,
          )}
          <button
            className={`primary-action ${styles.showResults}`}
            onClick={() => {
              setSheetOpen(false);
              toggle.current?.focus();
            }}
            type="button"
          >
            {t('transactions.filters.showResults')}
          </button>
        </ModalSheet>
      ) : null}
    </div>
  );
}
