import type {
  DecimalAmount,
  PortfolioValuationDetail,
  PortfolioValuationDraft,
  PortfolioValuationRequest,
} from '@cadran/api-client';
import { isCanonicalDecimal, isCanonicalUnsignedDecimal, isZeroDecimal } from '@/lib/decimal';

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
const DECIMAL_MESSAGE = 'Saisissez un nombre décimal, sans exposant.';

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

const normalize = (raw: string) => raw.trim().replace(',', '.');
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
      errors[pointer] = DECIMAL_MESSAGE;
      return undefined;
    }
    return amount(value, assetCode);
  }

  if (values.detail === 'TOTAL') {
    const total = normalize(values.totalValue);
    if (total === '') errors['/totalValue'] = 'Ce champ est obligatoire.';
    else if (!isCanonicalUnsignedDecimal(total)) errors['/totalValue'] = DECIMAL_MESSAGE;
    else request.totalValue = amount(total, assetCode);
  } else {
    request.prices = values.rows.map((row, index) => {
      if (row.unknown) return { instrumentId: row.instrumentId, price: null, priceDate: null };
      const price = normalize(row.price);
      if (price === '') {
        errors[`/prices/${index}/price`] = 'Saisissez un prix ou cochez Prix inconnu.';
      } else if (!isCanonicalUnsignedDecimal(price)) {
        errors[`/prices/${index}/price`] = DECIMAL_MESSAGE;
      } else if (isZeroDecimal(price)) {
        errors[`/prices/${index}/price`] = 'Le prix doit être strictement positif.';
      }
      if (row.priceDate === '') {
        errors[`/prices/${index}/priceDate`] = 'La date du prix est obligatoire.';
      } else if (row.priceDate > valuedOn) {
        errors[`/prices/${index}/priceDate`] =
          'La date du prix doit être au plus la date de valorisation.';
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
      errors['/note'] = 'Au plus 200 caractères, sans caractère de contrôle.';
    } else {
      request.note = note;
    }
  }
  if (version !== null) request.version = version;

  return Object.keys(errors).length > 0 ? { request: null, errors } : { request, errors: null };
}
