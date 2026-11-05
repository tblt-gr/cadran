import type {
  ListTransactionsData,
  TransactionDirection,
  TransactionNature,
  TransactionSource,
  TransactionState,
} from '@cadran/api-client';
import { compareDecimals, isCanonicalDecimal } from '@/lib/decimal';

export type TransactionAxis =
  'DISCRETIONARY' | 'ESSENTIAL' | 'FIXED' | 'PERSONAL' | 'PROFESSIONAL' | 'VARIABLE';

/**
 * `all` carries no date bound; it is the default and is not one of the labelled presets.
 * `month` carries one calendar month in {@link TransactionFilterState.month}.
 */
export type PeriodPreset = 'all' | 'month' | 'thisYear' | 'custom';

const STATES: readonly TransactionState[] = ['PENDING', 'BOOKED', 'VOIDED', 'REJECTED'];
const NATURES: readonly TransactionNature[] = [
  'INCOME',
  'EXPENSE',
  'TRANSFER',
  'REFUND',
  'FEE',
  'ADJUSTMENT',
];
const AXES: readonly TransactionAxis[] = [
  'DISCRETIONARY',
  'ESSENTIAL',
  'FIXED',
  'PERSONAL',
  'PROFESSIONAL',
  'VARIABLE',
];
const SOURCES: readonly TransactionSource[] = ['MANUAL', 'IMPORT', 'PROVIDER'];
const PERIODS: readonly PeriodPreset[] = ['all', 'month', 'thisYear', 'custom'];
const DIRECTIONS: readonly TransactionDirection[] = ['INFLOW', 'OUTFLOW'];
const MONTH = /^\d{4}-(0[1-9]|1[0-2])$/;

// Mirrors the API's list query validation, so a hand-edited or outdated URL is cleaned on
// load instead of turning every request into a 400 the retry button cannot get out of.
const MAX_MULTI_VALUE_FILTER = 20;
const MAX_TEXT_QUERY_LENGTH = 80;
const MAX_AMOUNT_INTEGER_DIGITS = 26;
const MAX_AMOUNT_SCALE = 24;
const IDENTIFIER = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const ASSET_CODE = /^[A-Z][A-Z0-9]{1,11}$/;

export interface TransactionFilterState {
  period: PeriodPreset;
  /** `YYYY-MM`; only meaningful, and only sent, when `period` is `month`. */
  month: string;
  /** Only meaningful, and only sent, when `period` is `custom`. */
  from: string;
  to: string;
  accountId: string[];
  state: TransactionState[];
  nature: TransactionNature[];
  categoryId: string[];
  includeDescendants: boolean;
  axis: TransactionAxis[];
  /** Empty means both directions. */
  direction: '' | TransactionDirection;
  /** Unsigned: the API compares the absolute value of the amount. */
  minAmount: string;
  maxAmount: string;
  assetCode: string;
  source: TransactionSource[];
  categorization: 'ANY' | 'NONE';
  q: string;
  includeVoided: boolean;
}

export const DEFAULT_FILTERS: TransactionFilterState = {
  period: 'all',
  month: '',
  from: '',
  to: '',
  accountId: [],
  state: [],
  nature: [],
  categoryId: [],
  includeDescendants: false,
  axis: [],
  direction: '',
  minAmount: '',
  maxAmount: '',
  assetCode: '',
  source: [],
  categorization: 'ANY',
  q: '',
  includeVoided: false,
};

function keepKnown<T extends string>(values: string[], known: readonly T[]): T[] {
  return values.filter((value): value is T => (known as readonly string[]).includes(value));
}

function isIsoDate(value: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(value);
}

function keepIdentifiers(values: string[]): string[] {
  return [...new Set(values.filter((value) => IDENTIFIER.test(value)))].slice(
    0,
    MAX_MULTI_VALUE_FILTER,
  );
}

function pad(value: number): string {
  return String(value).padStart(2, '0');
}

function toIsoDate(date: Date): string {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** The `YYYY-MM` of the calendar month `today` falls in. */
export function currentMonth(today: Date): string {
  return `${today.getFullYear()}-${pad(today.getMonth() + 1)}`;
}

/** The month `offset` months before (negative) or after `month`, as `YYYY-MM`. */
export function shiftMonth(month: string, offset: number): string {
  const [year, index] = month.split('-').map(Number);

  return currentMonth(new Date(year, index - 1 + offset, 1));
}

/** An unsigned amount bound the API accepts: canonical, `>= 0`, within `NUMERIC(50,24)` precision. */
export function isValidAmountBound(value: string): boolean {
  if (value.startsWith('-') || !isCanonicalDecimal(value)) {
    return false;
  }
  const [integer, fraction = ''] = value.split('.');

  return integer.length <= MAX_AMOUNT_INTEGER_DIGITS && fraction.length <= MAX_AMOUNT_SCALE;
}

/** Amount bounds only reach the API together with the asset code they are expressed in. */
export function hasAppliedAmountBounds(filters: TransactionFilterState): boolean {
  return Boolean(filters.assetCode && (filters.minAmount || filters.maxAmount));
}

/** Bookmarks written before the single-month period carried `thisMonth` or `lastMonth`. */
export function hasLegacyPeriod(params: URLSearchParams): boolean {
  const period = params.get('period');

  return period === 'thisMonth' || period === 'lastMonth';
}

/**
 * Pure decode of a filter set previously produced by {@link encodeFilters}. Unknown or malformed
 * values fall back to their default rather than throwing; the legacy `thisMonth` and `lastMonth`
 * periods become the equivalent `month`, relative to `today`.
 */
export function decodeFilters(
  params: URLSearchParams,
  today: Date = new Date(),
): TransactionFilterState {
  const period = params.get('period');
  const resolvedPeriod: PeriodPreset =
    period === 'thisMonth' || period === 'lastMonth'
      ? 'month'
      : period && (PERIODS as readonly string[]).includes(period)
        ? (period as PeriodPreset)
        : // A bare `month`, as the budget links to an account's operations, names that month.
          period === null && MONTH.test(params.get('month') ?? '')
          ? 'month'
          : 'all';
  const requestedMonth = params.get('month') ?? '';
  const month =
    period === 'thisMonth'
      ? currentMonth(today)
      : period === 'lastMonth'
        ? shiftMonth(currentMonth(today), -1)
        : MONTH.test(requestedMonth)
          ? requestedMonth
          : currentMonth(today);
  const direction = params.get('direction');
  const from = params.get('from') ?? '';
  const to = params.get('to') ?? '';
  const categorization = params.get('categorization');
  const minAmount = params.get('minAmount') ?? '';
  const maxAmount = params.get('maxAmount') ?? '';
  const assetCode = params.get('assetCode') ?? '';

  return {
    period: resolvedPeriod,
    month: resolvedPeriod === 'month' ? month : '',
    from: resolvedPeriod === 'custom' && isIsoDate(from) ? from : '',
    to: resolvedPeriod === 'custom' && isIsoDate(to) ? to : '',
    accountId: keepIdentifiers(params.getAll('accountId')),
    state: keepKnown(params.getAll('state'), STATES),
    nature: keepKnown(params.getAll('nature'), NATURES),
    categoryId: keepIdentifiers(params.getAll('categoryId')),
    includeDescendants: params.get('includeDescendants') === '1',
    axis: keepKnown(params.getAll('axis'), AXES),
    direction: (DIRECTIONS as readonly string[]).includes(direction ?? '')
      ? (direction as TransactionDirection)
      : '',
    minAmount: isValidAmountBound(minAmount) ? minAmount : '',
    maxAmount: isValidAmountBound(maxAmount) ? maxAmount : '',
    assetCode: ASSET_CODE.test(assetCode) ? assetCode : '',
    source: keepKnown(params.getAll('source'), SOURCES),
    categorization: categorization === 'NONE' ? 'NONE' : 'ANY',
    q: Array.from(params.get('q') ?? '')
      .slice(0, MAX_TEXT_QUERY_LENGTH)
      .join(''),
    includeVoided: params.get('includeVoided') === '1',
  };
}

/**
 * True when the filter set holds anything the filter panel edits: everything except the search
 * text, the to-categorise tab and the single-month period, which the page shows outside the
 * panel. The panel opens by itself on load only in that case.
 */
export function hasPanelFilters(filters: TransactionFilterState): boolean {
  return (
    encodeFilters({
      ...filters,
      categorization: 'ANY',
      month: '',
      period: filters.period === 'month' ? 'all' : filters.period,
      q: '',
    }).size > 0
  );
}

/** Pure encode of a filter set into a query string, omitting every value equal to its default. */
export function encodeFilters(filters: TransactionFilterState): URLSearchParams {
  const params = new URLSearchParams();

  if (filters.period !== 'all') {
    params.set('period', filters.period);
  }
  if (filters.period === 'month' && filters.month) {
    params.set('month', filters.month);
  }
  if (filters.period === 'custom' && filters.from) {
    params.set('from', filters.from);
  }
  if (filters.period === 'custom' && filters.to) {
    params.set('to', filters.to);
  }
  for (const id of filters.accountId) params.append('accountId', id);
  for (const value of filters.state) params.append('state', value);
  for (const value of filters.nature) params.append('nature', value);
  for (const id of filters.categoryId) params.append('categoryId', id);
  if (filters.includeDescendants) params.set('includeDescendants', '1');
  for (const value of filters.axis) params.append('axis', value);
  if (filters.direction) params.set('direction', filters.direction);
  if (filters.minAmount) params.set('minAmount', filters.minAmount);
  if (filters.maxAmount) params.set('maxAmount', filters.maxAmount);
  if (filters.assetCode) params.set('assetCode', filters.assetCode);
  for (const value of filters.source) params.append('source', value);
  if (filters.categorization === 'NONE') params.set('categorization', 'NONE');
  if (filters.q) params.set('q', filters.q);
  if (filters.includeVoided) params.set('includeVoided', '1');

  return params;
}

/** Resolves a period preset to an inclusive `[from, to]` bound, or `[null, null]` for `all`, as of `today`. */
export function resolvePeriod(
  filters: Pick<TransactionFilterState, 'period' | 'month' | 'from' | 'to'>,
  today: Date,
): { from: string | null; to: string | null } {
  switch (filters.period) {
    case 'all':
      return { from: null, to: null };
    case 'custom':
      return { from: filters.from || null, to: filters.to || null };
    case 'month': {
      const [year, index] = filters.month.split('-').map(Number);

      return {
        from: toIsoDate(new Date(year, index - 1, 1)),
        to: toIsoDate(new Date(year, index, 0)),
      };
    }
    case 'thisYear':
      return { from: toIsoDate(new Date(today.getFullYear(), 0, 1)), to: toIsoDate(today) };
  }
}

/**
 * A combination the server would run but that can never return a row, detected here so the
 * screen shows a dedicated state instead of firing the request: the "to categorise" queue
 * (`categorization: NONE`) holds transactions with no split at all, so a category or axis
 * filter — which only ever matches through a split — excludes every row in it, and the queue
 * never lists a voided movement, so asking it for voided rows only is empty as well.
 */
export function isImpossibleCombination(filters: TransactionFilterState): boolean {
  if (filters.period === 'custom' && filters.from && filters.to && filters.from > filters.to) {
    return true;
  }
  if (
    filters.categorization === 'NONE' &&
    (filters.categoryId.length > 0 || filters.axis.length > 0)
  ) {
    return true;
  }
  if (
    filters.categorization === 'NONE' &&
    filters.state.length > 0 &&
    filters.state.every((state) => state === 'VOIDED')
  ) {
    return true;
  }
  if (
    filters.minAmount &&
    filters.maxAmount &&
    filters.assetCode &&
    isValidAmountBound(filters.minAmount) &&
    isValidAmountBound(filters.maxAmount) &&
    compareDecimals(filters.minAmount, filters.maxAmount) > 0
  ) {
    return true;
  }

  return false;
}

/**
 * Normalises a user-typed amount before it reaches filter state or the API. Bounds are compared
 * without their sign, so a typed `-` is dropped; a French comma decimal separator becomes the
 * dot the API's canonical decimal pattern requires, so typing one never surfaces as a raw 400
 * error. Only the first comma is treated as a decimal separator; anything else in the input is
 * left for {@link isValidAmountBound} to reject.
 */
export function normalizeAmountInput(raw: string): string {
  return raw.replaceAll('-', '').replace(',', '.');
}

/**
 * Maps the filter set to the generated client's query shape, dropping every filter left at its
 * default and every value the API would refuse: amount bounds without their asset code, and
 * `includeVoided` in the "to categorise" queue, which never lists voided movements.
 */
export function toListTransactionsQuery(
  filters: TransactionFilterState,
  today: Date,
): NonNullable<ListTransactionsData['query']> {
  const { from, to } = resolvePeriod(filters, today);
  const amountBounds = hasAppliedAmountBounds(filters);
  const queue = filters.categorization === 'NONE';

  return {
    from: from ?? undefined,
    to: to ?? undefined,
    accountId: filters.accountId.length > 0 ? filters.accountId : undefined,
    state: filters.state.length > 0 ? filters.state : undefined,
    nature: filters.nature.length > 0 ? filters.nature : undefined,
    categoryId: filters.categoryId.length > 0 ? filters.categoryId : undefined,
    includeDescendants: filters.includeDescendants || undefined,
    axis: filters.axis.length > 0 ? filters.axis : undefined,
    direction: filters.direction || undefined,
    minAmount: (amountBounds && filters.minAmount) || undefined,
    maxAmount: (amountBounds && filters.maxAmount) || undefined,
    assetCode: amountBounds ? filters.assetCode : undefined,
    source: filters.source.length > 0 ? filters.source : undefined,
    q: filters.q || undefined,
    includeVoided: (!queue && filters.includeVoided) || undefined,
    categorization: queue ? 'NONE' : undefined,
  };
}
