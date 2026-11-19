import i18n from '@/i18n';
import type { ParseKeys } from 'i18next';
import type {
  DecimalAmount,
  PortfolioValuationDetail,
  PortfolioValuationDraft,
  PortfolioValuationRequest,
} from '@cadran/api-client';
import { isCanonicalDecimal, isCanonicalUnsignedDecimal, isZeroDecimal } from '@/lib/decimal';
import { normalizeDecimalInput } from '@/lib/decimalInput';

export interface PriceRowValues {
  instrumentId: string;
  /** Typed price, kept as text: it never goes through Number. */
  price: string;
  priceDate: string;
  /** The `Prix inconnu` checkbox: the line is sent with a null price. */
  unknown: boolean;
}

export interface ValuationFormValues {
  detail: PortfolioValuationDetail;
  totalValue: string;
  observedCash: string;
  observedTotal: string;
  note: string;
  rows: PriceRowValues[];
}

/** Errors keyed by JSON pointer, the same keys the server uses for its own refusals. */
type ValuationFormErrors = Record<string, string>;

type Result =
  | { request: PortfolioValuationRequest; errors: null }
  | { request: null; errors: ValuationFormErrors };

const CONTROL_CHARACTERS = /[\p{Cc}\p{Cf}]/u;
const vv = (key: string) => i18n.t(`portfolio.valuations.validation.${key}` as ParseKeys);

export function valuesFromDraft(draft: PortfolioValuationDraft): ValuationFormValues {
  return {
    detail: draft.suggestedDetail,
    totalValue: '',
    observedCash: '',
    observedTotal: '',
    note: '',
    rows: draft.positions.map((position) => ({
      instrumentId: position.instrument.id,
      price: position.lastPrice ?? '',
      priceDate: position.lastPriceDate ?? '',
      unknown: false,
    })),
  };
}

const normalize = (raw: string) => normalizeDecimalInput(raw);
const amount = (value: string, assetCode: string): DecimalAmount => ({ value, assetCode });

/** Turns the typed values into a record body; the server computes every figure. */
export function buildValuationRequest(
  values: ValuationFormValues,
  valuedOn: string,
  assetCode: string,
  version: number | null,
): Result {
  const errors: ValuationFormErrors = {};
  const request: PortfolioValuationRequest = { valuedOn, detail: values.detail };

  function optionalAmount(
    pointer: string,
    raw: string,
    signed: boolean,
  ): DecimalAmount | undefined {
    const value = normalize(raw);
    if (value === '') return undefined;
    if (!(signed ? isCanonicalDecimal(value) : isCanonicalUnsignedDecimal(value))) {
      errors[pointer] = vv('decimal');
      return undefined;
    }
    return amount(value, assetCode);
  }

  if (values.detail === 'TOTAL') {
    const total = normalize(values.totalValue);
    if (total === '') errors['/totalValue'] = vv('required');
    else if (!isCanonicalUnsignedDecimal(total)) errors['/totalValue'] = vv('decimal');
    else request.totalValue = amount(total, assetCode);
  } else {
    request.prices = values.rows.map((row, index) => {
      if (row.unknown) return { instrumentId: row.instrumentId, price: null, priceDate: null };
      const price = normalize(row.price);
      if (price === '') {
        errors[`/prices/${index}/price`] = vv('priceMissing');
      } else if (!isCanonicalUnsignedDecimal(price)) {
        errors[`/prices/${index}/price`] = vv('decimal');
      } else if (isZeroDecimal(price)) {
        errors[`/prices/${index}/price`] = vv('pricePositive');
      }
      if (row.priceDate === '') {
        errors[`/prices/${index}/priceDate`] = vv('priceDateRequired');
      } else if (row.priceDate > valuedOn) {
        errors[`/prices/${index}/priceDate`] = vv('priceDateAfter');
      }
      return { instrumentId: row.instrumentId, price, priceDate: row.priceDate };
    });
  }

  const cash = optionalAmount('/observedCash', values.observedCash, true);
  if (cash) request.observedCash = cash;
  if (values.detail === 'POSITIONS') {
    const total = optionalAmount('/observedTotal', values.observedTotal, false);
    if (total) request.observedTotal = total;
  }

  const note = values.note.trim();
  if (note !== '') {
    if (note.length > 200 || CONTROL_CHARACTERS.test(note)) {
      errors['/note'] = vv('noteLength');
    } else {
      request.note = note;
    }
  }
  if (version !== null) request.version = version;

  return Object.keys(errors).length > 0 ? { request: null, errors } : { request, errors: null };
}
