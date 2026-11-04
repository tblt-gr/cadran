import type {
  Account,
  TransactionNature,
  TransactionSource,
  TransactionState,
} from '@cadran/api-client';
import { useEffect, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { ActiveFilterChips } from './active-filter-chips/ActiveFilterChips';
import { AccountMultiSelect } from './account-multi-select/AccountMultiSelect';
import { AmountRangeFilter } from './amount-range-filter/AmountRangeFilter';
import { CategoryFilterField } from './category-filter-field/CategoryFilterField';
import { CheckboxFilterGroup } from './checkbox-filter-group/CheckboxFilterGroup';
import { DirectionFilter } from './direction-filter/DirectionFilter';
import { hasPanelFilters, type TransactionAxis, type TransactionFilterState } from './filterState';
import { PeriodFilter } from './period-filter/PeriodFilter';
import { useFilterChipLabels } from './useFilterChipLabels';
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
  /** Renders the search and the toggle inert while the list is first loading. */
  disabled?: boolean;
  filters: TransactionFilterState;
  onChange: (next: TransactionFilterState) => void;
  onReset: () => void;
  /** Changes when the filters are replaced from outside the bar, discarding typed drafts. */
  revision: number;
}

/**
 * The inline transaction filter bar: an always-visible search (label, counterparty or amount),
 * a `Filtres` toggle unfolding the panel in the page, and one removable chip per applied
 * filter that stays visible whether the panel is open or not. The panel starts open only when
 * the URL already holds a filter it edits.
 */
export function TransactionFilters({
  accounts,
  disabled = false,
  filters,
  onChange,
  onReset,
  revision,
}: TransactionFiltersProps) {
  const { i18n, t } = useTranslation();
  const panelId = useId();
  const [expanded, setExpanded] = useState(() => hasPanelFilters(filters));
  const [rawQuery, setRawQuery] = useState(filters.q);
  const debouncedQuery = useDebouncedValue(rawQuery, 300);
  // The picker returns a full category on selection, so a category picked in this session is
  // named at once; one restored from the URL is resolved by the workspace-wide lookup.
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

  const labels = useFilterChipLabels({ accounts, categoryLabels, filters });
  const chips = useFilterChips({
    ...labels,
    filters,
    language: i18n.language,
    onChange,
    onClearQuery: () => {
      setRawQuery('');
      set('q', '');
    },
    t,
  });
  const activeCount = chips.filter((chip) => chip.key !== 'q').length;

  return (
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
          aria-controls={panelId}
          aria-expanded={expanded}
          className={`secondary-action ${styles.toggle}`}
          data-active={activeCount > 0}
          disabled={disabled}
          onClick={() => setExpanded((open) => !open)}
          type="button"
        >
          {activeCount > 0
            ? t('transactions.filters.toggleWithCount', { count: activeCount })
            : t('transactions.filters.toggle')}
        </button>
      </div>

      <div className={styles.panel} hidden={!expanded} id={panelId}>
        <PeriodFilter
          from={filters.from}
          month={filters.month}
          onChange={(next) => onChange({ ...filters, ...next })}
          period={filters.period}
          to={filters.to}
        />

        <AccountMultiSelect
          accounts={accounts}
          label={t('transactions.filters.accountLabel')}
          onChange={(next) => set('accountId', next)}
          value={filters.accountId}
        />

        <DirectionFilter onChange={(next) => set('direction', next)} value={filters.direction} />

        <AmountRangeFilter
          assetCode={filters.assetCode}
          assetCodes={assetCodes}
          maxAmount={filters.maxAmount}
          minAmount={filters.minAmount}
          onChange={(range) => onChange({ ...filters, ...range })}
          revision={revision}
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
          labelFor={(value) => t(`transactions.natures.${value}`)}
          legend={t('transactions.fields.nature')}
          onChange={(next) => set('nature', next)}
          options={NATURES}
          value={filters.nature}
        />

        <CheckboxFilterGroup
          labelFor={(value) => t(`transactions.states.${value}`)}
          legend={t('transactions.fields.state')}
          onChange={(next) => set('state', next)}
          options={STATES}
          value={filters.state}
        />

        <CheckboxFilterGroup
          labelFor={(value) => t(`transactions.sources.${value}`)}
          legend={t('transactions.filters.sourceLabel')}
          onChange={(next) => set('source', next)}
          options={SOURCES}
          value={filters.source}
        />

        <CheckboxFilterGroup
          labelFor={(value) => t(`categories.axes.${value}`)}
          legend={t('transactions.filters.axisLabel')}
          onChange={(next) => set('axis', next)}
          options={AXES}
          value={filters.axis}
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

      <ActiveFilterChips chips={chips} onReset={onReset} />
    </section>
  );
}
