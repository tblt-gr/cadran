import type {
  Account,
  TransactionNature,
  TransactionSource,
  TransactionState,
} from '@cadran/api-client';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { AmountRangeFilter } from '@/features/transactions/transaction-filters/amount-range-filter/AmountRangeFilter';
import { CategoryCheckboxTree } from '@/features/transactions/transaction-filters/category-checkbox-tree/CategoryCheckboxTree';
import { CheckboxList } from '@/features/transactions/transaction-filters/checkbox-list/CheckboxList';
import { DirectionFilter } from '@/features/transactions/transaction-filters/direction-filter/DirectionFilter';
import { sectionCounts } from '@/features/transactions/transaction-filters/facet-count/optionCount';
import type {
  TransactionAxis,
  TransactionFilterState,
} from '@/features/transactions/transaction-filters/filterState';
import { PeriodFilter } from '@/features/transactions/transaction-filters/period-filter/PeriodFilter';
import type { TransactionFacetsState } from '@/features/transactions/transaction-filters/useTransactionFacets';
import styles from './FilterPanel.module.css';

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
/** Accounts listed before `Afficher plus`. */
const ACCOUNT_LIMIT = 6;

type Section =
  | 'period'
  | 'direction'
  | 'amount'
  | 'accounts'
  | 'categories'
  | 'natures'
  | 'states'
  | 'sources'
  | 'axes';

interface FilterPanelProps {
  /** Active accounts to choose from; `null` while they are loading. */
  accounts: Account[] | null;
  /** Controls placed beside the title, such as the close button of the mobile sheet. */
  actions?: ReactNode;
  activeCount: number;
  assetCodes: string[];
  disabled: boolean;
  /** How many transactions each option would keep; absent where the list shows no count. */
  facets?: TransactionFacetsState;
  filters: TransactionFilterState;
  onChange: (next: TransactionFilterState) => void;
  /** Set where the chip row is out of sight (the mobile sheet): the panel offers `Tout effacer`. */
  onReset?: () => void;
  revision: number;
  /** False where the list belongs to one account already: the account section is left out. */
  showAccounts?: boolean;
  titleId: string;
}

/**
 * Every filter of the transaction list, one collapsible section each, all closed until opened.
 * A section holding choices names their count beside its title.
 */
export function FilterPanel({
  accounts,
  actions,
  activeCount,
  assetCodes,
  disabled,
  facets,
  filters,
  onChange,
  onReset,
  revision,
  showAccounts = true,
  titleId,
}: FilterPanelProps) {
  const { t } = useTranslation();
  // Every section starts closed: the chips above the list already say what is applied.
  const [open, setOpen] = useState<Partial<Record<Section, boolean>>>({});
  const queue = filters.categorization === 'NONE';

  function set<K extends keyof TransactionFilterState>(key: K, value: TransactionFilterState[K]) {
    onChange({ ...filters, [key]: value });
  }

  function section(key: Section, content: ReactNode, selected = 0) {
    return (
      <Disclosure
        meta={selected > 0 ? t('transactions.filters.selectedCount', { count: selected }) : null}
        onToggle={(next) => setOpen((current) => ({ ...current, [key]: next }))}
        open={open[key] ?? false}
        title={t(`transactions.filters.sections.${key}`)}
      >
        {content}
      </Disclosure>
    );
  }

  return (
    <div className={styles.panel}>
      <div className={styles.heading}>
        <h2 id={titleId}>{t('transactions.filters.panelTitle')}</h2>
        {onReset && activeCount > 0 ? (
          <button
            aria-label={t('transactions.filters.reset')}
            className="icon-button"
            disabled={disabled}
            onClick={onReset}
            title={t('transactions.filters.reset')}
            type="button"
          >
            <Icon name="delete" size={16} />
          </button>
        ) : null}
        {actions}
      </div>

      <fieldset className={styles.sections} disabled={disabled}>
        <legend className="sr-only">{t('transactions.filters.panelTitle')}</legend>
        {section(
          'period',
          <PeriodFilter
            from={filters.from}
            month={filters.month}
            onChange={(next) => onChange({ ...filters, ...next })}
            period={filters.period}
            to={filters.to}
          />,
        )}
        {section(
          'direction',
          <DirectionFilter onChange={(next) => set('direction', next)} value={filters.direction} />,
        )}
        {section(
          'amount',
          <AmountRangeFilter
            assetCode={filters.assetCode}
            assetCodes={assetCodes}
            maxAmount={filters.maxAmount}
            minAmount={filters.minAmount}
            onChange={(range) => onChange({ ...filters, ...range })}
            revision={revision}
          />,
        )}
        {showAccounts &&
          section(
            'accounts',
            <CheckboxList
              counts={sectionCounts(facets, 'accountId')}
              emptyLabel={t('transactions.filters.accountsEmpty')}
              legend={t('transactions.filters.sections.accounts')}
              limit={ACCOUNT_LIMIT}
              onChange={(next) => set('accountId', next)}
              options={
                accounts === null
                  ? null
                  : accounts.map((account) => ({ value: account.id, label: account.label }))
              }
              value={filters.accountId}
            />,
            filters.accountId.length,
          )}
        {section(
          'categories',
          <CategoryCheckboxTree
            categoryIds={filters.categoryId}
            counts={sectionCounts(facets, 'categoryId')}
            includeDescendants={filters.includeDescendants}
            onCategoryIdsChange={(next) =>
              onChange({ ...filters, categoryId: next, categorization: 'ANY' })
            }
            onIncludeDescendantsChange={(value) => set('includeDescendants', value)}
            onUncategorizedChange={(value) =>
              onChange(
                value
                  ? { ...filters, categorization: 'NONE', categoryId: [], axis: [] }
                  : { ...filters, categorization: 'ANY' },
              )
            }
            uncategorized={queue}
          />,
          filters.categoryId.length + (queue ? 1 : 0),
        )}
        {section(
          'natures',
          <CheckboxList
            counts={sectionCounts(facets, 'nature')}
            legend={t('transactions.filters.sections.natures')}
            onChange={(next) => set('nature', next)}
            options={NATURES.map((value) => ({
              value,
              label: t(`transactions.natures.${value}`),
            }))}
            value={filters.nature}
          />,
          filters.nature.length,
        )}
        {section(
          'states',
          <CheckboxList
            counts={sectionCounts(facets, 'state')}
            legend={t('transactions.filters.sections.states')}
            onChange={(next) => set('state', next)}
            options={STATES.map((value) => ({ value, label: t(`transactions.states.${value}`) }))}
            value={filters.state}
          />,
          filters.state.length,
        )}
        {section(
          'sources',
          <CheckboxList
            counts={sectionCounts(facets, 'source')}
            legend={t('transactions.filters.sections.sources')}
            onChange={(next) => set('source', next)}
            options={SOURCES.map((value) => ({ value, label: t(`transactions.sources.${value}`) }))}
            value={filters.source}
          />,
          filters.source.length,
        )}
        {section(
          'axes',
          <CheckboxList
            counts={sectionCounts(facets, 'axis')}
            legend={t('transactions.filters.sections.axes')}
            onChange={(next) => onChange({ ...filters, axis: next, categorization: 'ANY' })}
            options={AXES.map((value) => ({ value, label: t(`categories.axes.${value}`) }))}
            value={filters.axis}
          />,
          filters.axis.length,
        )}
        <label className={styles.checkbox}>
          <input
            checked={filters.includeVoided && !queue}
            disabled={queue}
            onChange={(event) => set('includeVoided', event.target.checked)}
            type="checkbox"
          />
          <span>{t('transactions.includeVoided')}</span>
        </label>
      </fieldset>
    </div>
  );
}
