import type { TFunction } from 'i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { CategoryLabel } from '@/features/categories/category-label/CategoryLabel';
import { formatMonthLabel } from '@/lib/monthLabel';
import type { FilterChip } from './active-filter-chips/ActiveFilterChips';
import { hasAppliedAmountBounds, type TransactionFilterState } from './filterState';

/** A label lookup: the label, `null` once resolved but absent, `undefined` while unresolved. */
/** The lookup request failed: the record may exist, so it must not read as "unknown". */
export const LABEL_UNAVAILABLE = Symbol('label-unavailable');
export type LabelLookup = (id: string) => string | null | undefined | typeof LABEL_UNAVAILABLE;

interface UseFilterChipsOptions {
  accountLabel: LabelLookup;
  categoryLabel: LabelLookup;
  /** A category chip shows the category's icon in its colour, as the category picker does. */
  categoryMark?: (id: string) => { color: string | null; icon: string | null } | null;
  filters: TransactionFilterState;
  language: string;
  onChange: (next: TransactionFilterState) => void;
  onClearQuery: () => void;
  t: TFunction;
}

/**
 * One chip per applied filter, each carrying the callback that clears just that filter. The
 * period, direction, account, state, nature, category, uncategorized, axis, source, amount and
 * free-text filters each contribute independently, so a chip's own clear never disturbs the
 * others. An
 * account or category chip never shows its identifier: a skeleton while the label is being
 * resolved, then the "unknown" wording if the workspace has no such record.
 */
export function useFilterChips({
  accountLabel,
  categoryLabel,
  categoryMark,
  filters,
  language,
  onChange,
  onClearQuery,
  t,
}: UseFilterChipsOptions): FilterChip[] {
  function set<K extends keyof TransactionFilterState>(key: K, value: TransactionFilterState[K]) {
    onChange({ ...filters, [key]: value });
  }

  const chips: FilterChip[] = [];

  function named(
    noun: string,
    resolved: string | null | undefined | typeof LABEL_UNAVAILABLE,
    unknown: string,
    unavailable: string,
  ) {
    const text = resolved === LABEL_UNAVAILABLE ? unavailable : resolved;
    return {
      label: text === undefined ? <SkeletonBlock /> : (text ?? unknown),
      removeLabel: t('transactions.filters.clearChip', { label: text || noun }),
    };
  }

  if (filters.period !== 'all') {
    const label =
      filters.period === 'month'
        ? formatMonthLabel(filters.month, language)
        : t(`transactions.filters.period.${filters.period}`);
    chips.push({
      key: 'period',
      label,
      removeLabel: t('transactions.filters.clearChip', { label }),
      onClear: () => set('period', 'all'),
    });
  }
  if (filters.direction) {
    const label = t(`transactions.filters.direction.${filters.direction}`);
    chips.push({
      key: 'direction',
      label,
      removeLabel: t('transactions.filters.clearChip', { label }),
      onClear: () => set('direction', ''),
    });
  }
  for (const id of filters.accountId) {
    chips.push({
      key: `account-${id}`,
      ...named(
        t('transactions.filters.accountNoun'),
        accountLabel(id),
        t('transactions.filters.accountUnknown'),
        t('transactions.filters.accountUnavailable'),
      ),
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
      removeLabel: t('transactions.filters.clearChip', {
        label: t(`transactions.states.${value}`),
      }),
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
      removeLabel: t('transactions.filters.clearChip', {
        label: t(`transactions.natures.${value}`),
      }),
      onClear: () =>
        set(
          'nature',
          filters.nature.filter((candidate) => candidate !== value),
        ),
    });
  }
  if (filters.categoryType) {
    const label = t(`transactions.filters.categoryTypeChip.${filters.categoryType}`);
    chips.push({
      key: 'categoryType',
      label,
      removeLabel: t('transactions.filters.clearChip', { label }),
      onClear: () => set('categoryType', ''),
    });
  }
  for (const id of filters.categoryId) {
    const chip = named(
      t('transactions.filters.categoryNoun'),
      categoryLabel(id),
      t('transactions.filters.categoryUnknown'),
      t('transactions.filters.categoryUnavailable'),
    );
    const mark = categoryMark?.(id);
    chips.push({
      key: `category-${id}`,
      removeLabel: chip.removeLabel,
      label:
        mark && typeof chip.label === 'string' ? (
          <CategoryLabel color={mark.color} icon={mark.icon}>
            {chip.label}
          </CategoryLabel>
        ) : (
          chip.label
        ),
      onClear: () =>
        set(
          'categoryId',
          filters.categoryId.filter((candidate) => candidate !== id),
        ),
    });
  }
  if (filters.categorization === 'NONE') {
    const label = t('transactions.filters.uncategorized');
    chips.push({
      key: 'categorization',
      label,
      removeLabel: t('transactions.filters.clearChip', { label }),
      onClear: () => set('categorization', 'ANY'),
    });
  }
  for (const value of filters.axis) {
    chips.push({
      key: `axis-${value}`,
      label: t(`categories.axes.${value}`),
      removeLabel: t('transactions.filters.clearChip', { label: t(`categories.axes.${value}`) }),
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
      removeLabel: t('transactions.filters.clearChip', {
        label: t(`transactions.sources.${value}`),
      }),
      onClear: () =>
        set(
          'source',
          filters.source.filter((candidate) => candidate !== value),
        ),
    });
  }
  if (hasAppliedAmountBounds(filters)) {
    const label =
      filters.minAmount && filters.maxAmount
        ? t('transactions.filters.amountChipBetween', {
            min: filters.minAmount,
            max: filters.maxAmount,
          })
        : filters.minAmount
          ? t('transactions.filters.amountChipMin', { min: filters.minAmount })
          : t('transactions.filters.amountChipMax', { max: filters.maxAmount });
    chips.push({
      key: 'amount',
      label,
      removeLabel: t('transactions.filters.clearChip', { label }),
      onClear: () => onChange({ ...filters, minAmount: '', maxAmount: '', assetCode: '' }),
    });
  }
  if (filters.q) {
    const label = t('transactions.filters.searchChip', { query: filters.q });
    chips.push({
      key: 'q',
      label,
      removeLabel: t('transactions.filters.clearChip', { label }),
      onClear: onClearQuery,
    });
  }
  if (filters.includeVoided && filters.categorization !== 'NONE') {
    chips.push({
      key: 'includeVoided',
      label: t('transactions.includeVoided'),
      removeLabel: t('transactions.filters.clearChip', { label: t('transactions.includeVoided') }),
      onClear: () => set('includeVoided', false),
    });
  }

  return chips;
}
