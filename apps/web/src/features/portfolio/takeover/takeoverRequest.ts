import type {
  PortfolioTakeoverError,
  PortfolioTakeoverLineInput,
  PortfolioTakeoverRequest,
} from '@cadran/api-client';
import i18n from '@/i18n';
import type { ParseKeys } from 'i18next';
import { isCanonicalUnsignedDecimal, isZeroDecimal } from '@/lib/decimal';
import { normalizeDecimalInput } from '@/lib/decimalInput';

export const MAX_TAKEOVER_LINES = 200;

export interface TakeoverLineValues {
  /** Stable React key: rows are removed from the middle. */
  key: string;
  instrument: { id: string; name: string } | null;
  quantity: string;
  costBasis: string;
  price: string;
  priceDate: string;
}

export interface TakeoverFormValues {
  takenOverOn: string;
  lines: TakeoverLineValues[];
  cash: string;
}

export type TakeoverLineField = 'instrument' | 'quantity' | 'costBasis' | 'price' | 'priceDate';

export interface TakeoverFieldErrors {
  date?: string;
  cash?: string;
  form?: string;
  /** Keyed by the 0-based row index. */
  lines: Record<number, Partial<Record<TakeoverLineField, string>>>;
}

const tv = (key: string) => i18n.t(`portfolio.takeover.validation.${key}` as ParseKeys);

let sequence = 0;
export function emptyLine(priceDate: string): TakeoverLineValues {
  sequence += 1;
  return {
    key: `line-${sequence}`,
    instrument: null,
    quantity: '',
    costBasis: '',
    price: '',
    priceDate,
  };
}

/** A typed decimal, comma accepted: the exact string or null when it is not canonical. */
function typed(raw: string): string | null {
  const value = normalizeDecimalInput(raw);
  return isCanonicalUnsignedDecimal(value) ? value : null;
}

/**
 * The request the server previews and records, or null while a field is incomplete. Amounts
 * stay strings end to end; the server alone computes values and unit costs.
 */
export function buildTakeoverRequest(values: TakeoverFormValues): {
  request: PortfolioTakeoverRequest | null;
  errors: TakeoverFieldErrors;
} {
  const errors: TakeoverFieldErrors = { lines: {} };
  if (values.takenOverOn === '') errors.date = tv('date');

  const lines: PortfolioTakeoverLineInput[] = [];
  values.lines.forEach((line, index) => {
    const lineErrors: Partial<Record<TakeoverLineField, string>> = {};
    const quantity = typed(line.quantity);
    const price = typed(line.price);
    const costRaw = line.costBasis.trim();
    const costBasis = costRaw === '' ? null : typed(costRaw);
    if (!line.instrument) lineErrors.instrument = tv('instrument');
    if (quantity === null || isZeroDecimal(quantity)) lineErrors.quantity = tv('quantity');
    if (price === null || isZeroDecimal(price)) lineErrors.price = tv('price');
    if (costRaw !== '' && costBasis === null) lineErrors.costBasis = tv('amount');
    if (Object.keys(lineErrors).length > 0) {
      errors.lines[index] = lineErrors;
      return;
    }
    lines.push({
      instrumentId: line.instrument!.id,
      quantity: quantity!,
      costBasis,
      price: price!,
      priceDate:
        line.priceDate === '' || line.priceDate === values.takenOverOn ? null : line.priceDate,
    });
  });

  const cashRaw = values.cash.trim();
  const cash = cashRaw === '' ? null : typed(cashRaw);
  if (cashRaw !== '' && cash === null) errors.cash = tv('amount');

  const complete =
    errors.date === undefined && errors.cash === undefined && lines.length === values.lines.length;
  return {
    request: complete ? { takenOverOn: values.takenOverOn, lines, cash } : null,
    errors,
  };
}

const lineCodes = new Set([
  'unknown_instrument',
  'instrument_inactive',
  'instrument_currency_mismatch',
  'duplicate_instrument',
  'invalid_decimal',
  'precision_exceeded',
  'not_positive',
  'negative',
  'quantity_precision_exceeded',
  'price_precision_exceeded',
  'price_date_after_takeover',
  'value_too_large',
]);
const requestCodes = new Set([
  'invalid_date',
  'before_account_opening',
  'in_the_future',
  'lines_count',
  'invalid_decimal',
  'negative',
  'precision_exceeded',
  'value_too_large',
]);
const lineText = (code: string) => tv(lineCodes.has(code) ? `lineErrors.${code}` : 'fallback');
const requestText = (code: string) =>
  tv(requestCodes.has(code) ? `requestErrors.${code}` : 'fallback');
const lineFieldOf: Record<string, TakeoverLineField | undefined> = {
  instrumentId: 'instrument',
  quantity: 'quantity',
  costBasis: 'costBasis',
  price: 'price',
  priceDate: 'priceDate',
};

/** The server's errors (1-based lines) as messages next to the 0-based rows and fields. */
export function mapTakeoverErrors(errors: readonly PortfolioTakeoverError[]): TakeoverFieldErrors {
  const mapped: TakeoverFieldErrors = { lines: {} };
  for (const error of errors) {
    if (error.line !== null) {
      const field = lineFieldOf[error.field];
      if (field) {
        const row = (mapped.lines[error.line - 1] ??= {});
        row[field] ??= lineText(error.code);
        continue;
      }
    }
    const text = requestText(error.code);
    if (error.field === 'takenOverOn') mapped.date ??= text;
    else if (error.field === 'cash') mapped.cash ??= text;
    else mapped.form ??= text;
  }
  return mapped;
}

/** The first message wins per field, in the order given: local checks, preview, then the server. */
export function mergeTakeoverErrors(
  ...sources: Array<TakeoverFieldErrors | undefined>
): TakeoverFieldErrors {
  const merged: TakeoverFieldErrors = { lines: {} };
  for (const source of sources) {
    if (!source) continue;
    merged.date ??= source.date;
    merged.cash ??= source.cash;
    merged.form ??= source.form;
    for (const [index, fields] of Object.entries(source.lines)) {
      const row = (merged.lines[Number(index)] ??= {});
      for (const [field, text] of Object.entries(fields) as Array<[TakeoverLineField, string]>)
        row[field] ??= text;
    }
  }
  return merged;
}
