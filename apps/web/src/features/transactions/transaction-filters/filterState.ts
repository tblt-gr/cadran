import type {
  ListTransactionsData,
  TransactionNature,
  TransactionSource,
  TransactionState,
} from '@cadran/api-client';
import { compareDecimals, isCanonicalDecimal } from '@/lib/decimal';

export type TransactionAxis =
  'DISCRETIONARY' | 'ESSENTIAL' | 'FIXED' | 'PERSONAL' | 'PROFESSIONAL' | 'VARIABLE';

/** `all` carries no date bound; it is the default and is not one of the labelled presets. */
export type PeriodPreset = 'all' | 'thisMonth' | 'lastMonth' | 'thisYear' | 'custom';

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
const PERIODS: readonly PeriodPreset[] = ['all', 'thisMonth', 'lastMonth', 'thisYear', 'custom'];

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
  /** Only meaningful, and only sent, when `period` is `custom`. */
  from: string;
  to: string;
  accountId: string[];
  state: TransactionState[];
  nature: TransactionNature[];
  categoryId: string[];
  includeDescendants: boolean;
  axis: TransactionAxis[];
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
  from: '',
  to: '',
  accountId: [],
  state: [],
  nature: [],
  categoryId: [],
  includeDescendants: false,
  axis: [],
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

/** A signed amount bound the API accepts: canonical, within `NUMERIC(50,24)` precision. */
export function isValidAmountBound(value: string): boolean {
  if (!isCanonicalDecimal(value)) {
    return false;
  }
  const [integer, fraction = ''] = value.replace('-', '').split('.');

  return integer.length <= MAX_AMOUNT_INTEGER_DIGITS && fraction.length <= MAX_AMOUNT_SCALE;
}

/** Amount bounds only reach the API together with the asset code they are expressed in. */
export function hasAppliedAmountBounds(filters: TransactionFilterState): boolean {
  return Boolean(filters.assetCode && (filters.minAmount || filters.maxAmount));
}

/** Pure decode of a filter set previously produced by {@link encodeFilters}. Unknown or malformed values fall back to their default rather than throwing. */
export function decodeFilters(params: URLSearchParams): TransactionFilterState {
  const period = params.get('period');
  const resolvedPeriod: PeriodPreset =
    period && (PERIODS as readonly string[]).includes(period) ? (period as PeriodPreset) : 'all';
  const from = params.get('from') ?? '';
  const to = params.get('to') ?? '';
  const categorization = params.get('categorization');
  const minAmount = params.get('minAmount') ?? '';
  const maxAmount = params.get('maxAmount') ?? '';
  const assetCode = params.get('assetCode') ?? '';

  return {
    period: resolvedPeriod,
    from: resolvedPeriod === 'custom' && isIsoDate(from) ? from : '',
    to: resolvedPeriod === 'custom' && isIsoDate(to) ? to : '',
    accountId: keepIdentifiers(params.getAll('accountId')),
    state: keepKnown(params.getAll('state'), STATES),
    nature: keepKnown(params.getAll('nature'), NATURES),
    categoryId: keepIdentifiers(params.getAll('categoryId')),
    includeDescendants: params.get('includeDescendants') === '1',
    axis: keepKnown(params.getAll('axis'), AXES),
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

/** Pure encode of a filter set into a query string, omitting every value equal to its default. */
export function encodeFilters(filters: TransactionFilterState): URLSearchParams {
  const params = new URLSearchParams();

  if (filters.period !== 'all') {
    params.set('period', filters.period);
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
  if (filters.minAmount) params.set('minAmount', filters.minAmount);
  if (filters.maxAmount) params.set('maxAmount', filters.maxAmount);
  if (filters.assetCode) params.set('assetCode', filters.assetCode);
  for (const value of filters.source) params.append('source', value);
  if (filters.categorization === 'NONE') params.set('categorization', 'NONE');
  if (filters.q) params.set('q', filters.q);
  if (filters.includeVoided) params.set('includeVoided', '1');

  return params;
}

function pad(value: number): string {
  return String(value).padStart(2, '0');
}

function toIsoDate(date: Date): string {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** Resolves a period preset to an inclusive `[from, to]` bound, or `[null, null]` for `all`, as of `today`. */
export function resolvePeriod(
  filters: Pick<TransactionFilterState, 'period' | 'from' | 'to'>,
  today: Date,
): { from: string | null; to: string | null } {
  switch (filters.period) {
    case 'all':
      return { from: null, to: null };
    case 'custom':
      return { from: filters.from || null, to: filters.to || null };
    case 'thisMonth':
      return {
        from: toIsoDate(new Date(today.getFullYear(), today.getMonth(), 1)),
        to: toIsoDate(today),
      };
    case 'lastMonth': {
      const start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
      const end = new Date(today.getFullYear(), today.getMonth(), 0);
      return { from: toIsoDate(start), to: toIsoDate(end) };
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
 * Normalises a user-typed signed amount before it reaches filter state or the API: a French
 * comma decimal separator becomes the dot the API's canonical decimal pattern requires, so
 * typing one never surfaces as a raw 400 error. Only the first comma is treated as a decimal
 * separator; anything else in the input is left for {@link isCanonicalDecimal} to reject.
 */
export function normalizeAmountInput(raw: string): string {
  return raw.replace(',', '.');
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
    minAmount: (amountBounds && filters.minAmount) || undefined,
    maxAmount: (amountBounds && filters.maxAmount) || undefined,
    assetCode: amountBounds ? filters.assetCode : undefined,
    source: filters.source.length > 0 ? filters.source : undefined,
    q: filters.q || undefined,
    includeVoided: (!queue && filters.includeVoided) || undefined,
    categorization: queue ? 'NONE' : undefined,
  };
}
