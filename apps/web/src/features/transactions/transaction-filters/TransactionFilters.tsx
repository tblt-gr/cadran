import type {
  Account,
  TransactionNature,
  TransactionSource,
  TransactionState,
} from '@cadran/api-client';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { ActiveFilterChips } from './active-filter-chips/ActiveFilterChips';
import { AccountMultiSelect } from './account-multi-select/AccountMultiSelect';
import { AmountRangeFilter } from './amount-range-filter/AmountRangeFilter';
import { CategoryFilterField } from './category-filter-field/CategoryFilterField';
import { CheckboxFilterGroup } from './checkbox-filter-group/CheckboxFilterGroup';
import type { TransactionAxis, TransactionFilterState } from './filterState';
import { PeriodFilter } from './period-filter/PeriodFilter';
import { useFilterChips } from './useFilterChips';
import styles from './TransactionFilters.module.css';

const STATES: TransactionState[] = ['PENDING', 'BOOKED', 'VOIDED', 'REJECTED'];
const NATURES: TransactionNature[] = [
  'INCOME',
  'EXPENSE',
  'TRANSFER',
  'REFUND',
  'FEE',
  'ADJUSTMENT',
];
const AXES: TransactionAxis[] = [
  'DISCRETIONARY',
  'ESSENTIAL',
  'FIXED',
  'PERSONAL',
  'PROFESSIONAL',
  'VARIABLE',
];
const SOURCES: TransactionSource[] = ['MANUAL', 'IMPORT', 'PROVIDER'];

interface TransactionFiltersProps {
  accounts: Account[];
  filters: TransactionFilterState;
  onChange: (next: TransactionFilterState) => void;
  onReset: () => void;
  /** Changes when the filters are replaced from outside the bar, discarding typed drafts. */
  revision: number;
}

/**
 * The transaction filters: one header button (with the active filter count) opens a modal that
 * holds the free text search, period, accounts, states, natures, categories, analytic axes, a
 * signed amount range and source. Every applied filter, search included, reflects as a chip
 * under the header that the user can clear on its own.
 */
export function TransactionFilters({
  accounts,
  filters,
  onChange,
  onReset,
  revision,
}: TransactionFiltersProps) {
  const { t } = useTranslation();
  const trigger = useRef<HTMLButtonElement>(null);
  const [open, setOpen] = useState(false);
  const [rawQuery, setRawQuery] = useState(filters.q);
  const debouncedQuery = useDebouncedValue(rawQuery, 300);
  // The picker only ever returns a full category on selection (a server search result, not a
  // by-id lookup), so a category restored from the URL has no label yet and the chip falls back
  // to showing its id until the user picks it again from this session.
  const [categoryLabels, setCategoryLabels] = useState<Record<string, string>>({});

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
  const queue = filters.categorization === 'NONE';

  function set<K extends keyof TransactionFilterState>(key: K, value: TransactionFilterState[K]) {
    onChange({ ...filters, [key]: value });
  }

  const chips = useFilterChips({
    accounts,
    categoryLabels,
    filters,
    onChange,
    onClearQuery: () => {
      setRawQuery('');
      set('q', '');
    },
    queue,
    t,
  });
  const activeCount = chips.length;

  return (
    <>
      <HeaderActions>
        <button
          aria-haspopup="dialog"
          aria-label={
            activeCount > 0
              ? t('transactions.filters.toggleWithCount', { count: activeCount })
              : t('transactions.filters.toggle')
          }
          className={`icon-button ${styles.trigger}`}
          data-active={activeCount > 0}
          onClick={() => setOpen(true)}
          ref={trigger}
          title={t('transactions.filters.toggle')}
          type="button"
        >
          <Icon name="rules" size={18} />
          {activeCount > 0 ? (
            <span aria-hidden="true" className={styles.badge}>
              {activeCount}
            </span>
          ) : null}
        </button>
      </HeaderActions>

      {open ? (
        <Modal
          close={() => setOpen(false)}
          returnFocus={trigger}
          title={t('transactions.filters.title')}
        >
          <div className={styles.panel}>
            <label className={styles.search}>
              <Icon name="search" size={16} />
              <span className="sr-only">{t('transactions.filters.search')}</span>
              <input
                maxLength={80}
                onChange={(event) => setRawQuery(event.target.value)}
                placeholder={t('transactions.filters.search')}
                type="search"
                value={rawQuery}
              />
            </label>

            <PeriodFilter
              from={filters.from}
              onChange={(period, from, to) => onChange({ ...filters, period, from, to })}
              period={filters.period}
              to={filters.to}
            />

            <AccountMultiSelect
              accounts={accounts}
              label={t('transactions.filters.accountLabel')}
              onChange={(next) => set('accountId', next)}
              value={filters.accountId}
            />

            <CheckboxFilterGroup
              labelFor={(value) => t(`transactions.states.${value}`)}
              legend={t('transactions.fields.state')}
              onChange={(next) => set('state', next)}
              options={STATES}
              value={filters.state}
            />

            <CheckboxFilterGroup
              labelFor={(value) => t(`transactions.natures.${value}`)}
              legend={t('transactions.fields.nature')}
              onChange={(next) => set('nature', next)}
              options={NATURES}
              value={filters.nature}
            />

            <CategoryFilterField
              categoryIds={filters.categoryId}
              includeDescendants={filters.includeDescendants}
              onAddCategory={(categoryId, category) => {
                set('categoryId', [...filters.categoryId, categoryId]);
                if (category) {
                  setCategoryLabels((current) => ({ ...current, [categoryId]: category.label }));
                }
              }}
              onIncludeDescendantsChange={(value) => set('includeDescendants', value)}
            />

            <CheckboxFilterGroup
              labelFor={(value) => t(`categories.axes.${value}`)}
              legend={t('transactions.filters.axisLabel')}
              onChange={(next) => set('axis', next)}
              options={AXES}
              value={filters.axis}
            />

            <AmountRangeFilter
              assetCode={filters.assetCode}
              assetCodes={assetCodes}
              maxAmount={filters.maxAmount}
              minAmount={filters.minAmount}
              onChange={(range) => onChange({ ...filters, ...range })}
              revision={revision}
            />

            <CheckboxFilterGroup
              labelFor={(value) => t(`transactions.sources.${value}`)}
              legend={t('transactions.filters.sourceLabel')}
              onChange={(next) => set('source', next)}
              options={SOURCES}
              value={filters.source}
            />

            <label className={styles.checkbox}>
              <input
                checked={filters.includeVoided && !queue}
                disabled={queue}
                onChange={(event) => set('includeVoided', event.target.checked)}
                type="checkbox"
              />
              <span>{t('transactions.includeVoided')}</span>
            </label>
          </div>
        </Modal>
      ) : null}

      <ActiveFilterChips chips={chips} onReset={onReset} />
    </>
  );
}
