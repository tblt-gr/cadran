import type {
  Account,
  TransactionNature,
  TransactionSource,
  TransactionState,
} from '@cadran/api-client';
import { useEffect, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { AmountRangeFilter } from './amount-range-filter/AmountRangeFilter';
import {
  hasAppliedAmountBounds,
  type PeriodPreset,
  type TransactionAxis,
  type TransactionFilterState,
} from './filterState';
import styles from './TransactionFilters.module.css';

const PERIOD_PRESETS: PeriodPreset[] = ['all', 'thisMonth', 'lastMonth', 'thisYear', 'custom'];
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

interface Chip {
  key: string;
  label: string;
  onClear: () => void;
}

function toggle<T>(values: T[], value: T): T[] {
  return values.includes(value)
    ? values.filter((candidate) => candidate !== value)
    : [...values, value];
}

/**
 * The transaction filter bar: period, accounts, states, natures, categories, analytic axes,
 * a signed amount range, source, categorisation and free text — each combinable, reflected as
 * a chip the user can clear on its own. Collapses into a toggled sheet at 360px; above that
 * width the panel stays open and the toggle is hidden by `TransactionFilters.module.css`.
 */
export function TransactionFilters({
  accounts,
  filters,
  onChange,
  onReset,
  revision,
}: TransactionFiltersProps) {
  const { t } = useTranslation();
  const panelId = useId();
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

  const chips: Chip[] = [];
  if (filters.period !== 'all') {
    chips.push({
      key: 'period',
      label: t(`transactions.filters.period.${filters.period}`),
      onClear: () => set('period', 'all'),
    });
  }
  for (const id of filters.accountId) {
    const account = accounts.find((candidate) => candidate.id === id);
    chips.push({
      key: `account-${id}`,
      label: account?.label ?? id,
      onClear: () =>
        set(
          'accountId',
          filters.accountId.filter((candidate) => candidate !== id),
        ),
    });
  }
  for (const value of filters.state) {
    chips.push({
      key: `state-${value}`,
      label: t(`transactions.states.${value}`),
      onClear: () =>
        set(
          'state',
          filters.state.filter((candidate) => candidate !== value),
        ),
    });
  }
  for (const value of filters.nature) {
    chips.push({
      key: `nature-${value}`,
      label: t(`transactions.natures.${value}`),
      onClear: () =>
        set(
          'nature',
          filters.nature.filter((candidate) => candidate !== value),
        ),
    });
  }
  for (const id of filters.categoryId) {
    const label = categoryLabels[id] ?? id;
    chips.push({
      key: `category-${id}`,
      label,
      onClear: () =>
        set(
          'categoryId',
          filters.categoryId.filter((candidate) => candidate !== id),
        ),
    });
  }
  for (const value of filters.axis) {
    chips.push({
      key: `axis-${value}`,
      label: t(`categories.axes.${value}`),
      onClear: () =>
        set(
          'axis',
          filters.axis.filter((candidate) => candidate !== value),
        ),
    });
  }
  for (const value of filters.source) {
    chips.push({
      key: `source-${value}`,
      label: t(`transactions.sources.${value}`),
      onClear: () =>
        set(
          'source',
          filters.source.filter((candidate) => candidate !== value),
        ),
    });
  }
  if (hasAppliedAmountBounds(filters)) {
    chips.push({
      key: 'amount',
      label: t('transactions.filters.amountChip', {
        min: filters.minAmount || '…',
        max: filters.maxAmount || '…',
      }),
      onClear: () => onChange({ ...filters, minAmount: '', maxAmount: '', assetCode: '' }),
    });
  }
  if (queue) {
    chips.push({
      key: 'categorization',
      label: t('transactions.filters.categorizationQueue'),
      onClear: () => set('categorization', 'ANY'),
    });
  }
  if (filters.q) {
    chips.push({
      key: 'q',
      label: t('transactions.filters.searchChip', { query: filters.q }),
      onClear: () => {
        setRawQuery('');
        set('q', '');
      },
    });
  }
  if (filters.includeVoided && !queue) {
    chips.push({
      key: 'includeVoided',
      label: t('transactions.includeVoided'),
      onClear: () => set('includeVoided', false),
    });
  }

  const hasActiveFilters = chips.length > 0;

  return (
    <div className={styles.bar}>
      <button
        aria-controls={panelId}
        aria-expanded={open}
        className={`secondary-action ${styles.toggle}`}
        onClick={() => setOpen((current) => !current)}
        type="button"
      >
        {t('transactions.filters.toggle')}
      </button>

      <div className={styles.panel} data-open={open} id={panelId}>
        <fieldset className={styles.field}>
          <legend>{t('transactions.filters.periodLabel')}</legend>
          <div className={styles.periodChoices}>
            {PERIOD_PRESETS.filter((preset) => preset !== 'all').map((preset) => (
              <label className={styles.radio} key={preset}>
                <input
                  checked={filters.period === preset}
                  name="period"
                  onChange={() => set('period', preset)}
                  type="radio"
                  value={preset}
                />
                <span>{t(`transactions.filters.period.${preset}`)}</span>
              </label>
            ))}
          </div>
          {filters.period === 'custom' ? (
            <div className={styles.customPeriod}>
              <label>
                <span>{t('transactions.filters.from')}</span>
                <input
                  onChange={(event) => set('from', event.target.value)}
                  type="date"
                  value={filters.from}
                />
              </label>
              <label>
                <span>{t('transactions.filters.to')}</span>
                <input
                  onChange={(event) => set('to', event.target.value)}
                  type="date"
                  value={filters.to}
                />
              </label>
            </div>
          ) : null}
        </fieldset>

        <label className={styles.field}>
          <span>{t('transactions.filters.accountLabel')}</span>
          <select
            multiple
            onChange={(event) =>
              set(
                'accountId',
                Array.from(event.target.selectedOptions, (option) => option.value),
              )
            }
            value={filters.accountId}
          >
            {accounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.label}
              </option>
            ))}
          </select>
        </label>

        <fieldset className={styles.field}>
          <legend>{t('transactions.fields.state')}</legend>
          {STATES.map((value) => (
            <label className={styles.checkbox} key={value}>
              <input
                checked={filters.state.includes(value)}
                onChange={() => set('state', toggle(filters.state, value))}
                type="checkbox"
              />
              <span>{t(`transactions.states.${value}`)}</span>
            </label>
          ))}
        </fieldset>

        <fieldset className={styles.field}>
          <legend>{t('transactions.fields.nature')}</legend>
          {NATURES.map((value) => (
            <label className={styles.checkbox} key={value}>
              <input
                checked={filters.nature.includes(value)}
                onChange={() => set('nature', toggle(filters.nature, value))}
                type="checkbox"
              />
              <span>{t(`transactions.natures.${value}`)}</span>
            </label>
          ))}
        </fieldset>

        <div className={styles.field}>
          <CategoryPicker
            label={t('transactions.filters.categoryLabel')}
            onChange={(categoryId, pickedCategory) => {
              if (categoryId && !filters.categoryId.includes(categoryId)) {
                set('categoryId', [...filters.categoryId, categoryId]);
                if (pickedCategory) {
                  setCategoryLabels((current) => ({
                    ...current,
                    [categoryId]: pickedCategory.label,
                  }));
                }
              }
            }}
            placeholder={t('transactions.filters.addCategory')}
            value=""
          />
          <label className={styles.checkbox}>
            <input
              checked={filters.includeDescendants}
              onChange={(event) => set('includeDescendants', event.target.checked)}
              type="checkbox"
            />
            <span>{t('transactions.filters.includeDescendants')}</span>
          </label>
        </div>

        <fieldset className={styles.field}>
          <legend>{t('transactions.filters.axisLabel')}</legend>
          {AXES.map((value) => (
            <label className={styles.checkbox} key={value}>
              <input
                checked={filters.axis.includes(value)}
                onChange={() => set('axis', toggle(filters.axis, value))}
                type="checkbox"
              />
              <span>{t(`categories.axes.${value}`)}</span>
            </label>
          ))}
        </fieldset>

        <AmountRangeFilter
          assetCode={filters.assetCode}
          assetCodes={assetCodes}
          maxAmount={filters.maxAmount}
          minAmount={filters.minAmount}
          onChange={(range) => onChange({ ...filters, ...range })}
          revision={revision}
        />

        <fieldset className={styles.field}>
          <legend>{t('transactions.filters.sourceLabel')}</legend>
          {SOURCES.map((value) => (
            <label className={styles.checkbox} key={value}>
              <input
                checked={filters.source.includes(value)}
                onChange={() => set('source', toggle(filters.source, value))}
                type="checkbox"
              />
              <span>{t(`transactions.sources.${value}`)}</span>
            </label>
          ))}
        </fieldset>

        <label className={styles.field}>
          <span>{t('transactions.filters.search')}</span>
          <input
            maxLength={80}
            onChange={(event) => setRawQuery(event.target.value)}
            type="search"
            value={rawQuery}
          />
        </label>

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

      {hasActiveFilters ? (
        <div
          aria-label={t('transactions.filters.activeLabel')}
          className={styles.chips}
          role="group"
        >
          {chips.map((chip) => (
            <button className={styles.chip} key={chip.key} onClick={chip.onClear} type="button">
              {chip.label}
              <span aria-hidden="true"> ×</span>
              <span className="sr-only">
                {t('transactions.filters.clearChip', { label: chip.label })}
              </span>
            </button>
          ))}
          <button className="secondary-action" onClick={onReset} type="button">
            {t('transactions.filters.reset')}
          </button>
        </div>
      ) : null}
    </div>
  );
}
