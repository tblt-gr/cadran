import type {
  PortfolioTakeoverError,
  PortfolioTakeoverLineInput,
  PortfolioTakeoverRequest,
} from '@cadran/api-client';
import { isCanonicalUnsignedDecimal, isZeroDecimal } from '@/lib/decimal';

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
  const value = raw.trim().replace(',', '.');
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
  if (values.takenOverOn === '') errors.date = 'Saisissez la date de reprise.';

  const lines: PortfolioTakeoverLineInput[] = [];
  values.lines.forEach((line, index) => {
    const lineErrors: Partial<Record<TakeoverLineField, string>> = {};
    const quantity = typed(line.quantity);
    const price = typed(line.price);
    const costRaw = line.costBasis.trim();
    const costBasis = costRaw === '' ? null : typed(costRaw);
    if (!line.instrument) lineErrors.instrument = 'Choisissez un instrument.';
    if (quantity === null || isZeroDecimal(quantity))
      lineErrors.quantity = 'Saisissez une quantité strictement positive.';
    if (price === null || isZeroDecimal(price))
      lineErrors.price = 'Saisissez un cours strictement positif.';
    if (costRaw !== '' && costBasis === null) lineErrors.costBasis = 'Saisissez un montant valide.';
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
  if (cashRaw !== '' && cash === null) errors.cash = 'Saisissez un montant valide.';

  const complete =
    errors.date === undefined && errors.cash === undefined && lines.length === values.lines.length;
  return {
    request: complete ? { takenOverOn: values.takenOverOn, lines, cash } : null,
    errors,
  };
}

const lineTexts: Record<string, string> = {
  unknown_instrument: 'Cet instrument est introuvable.',
  instrument_inactive: 'Cet instrument est inactif.',
  instrument_currency_mismatch: 'La devise de l’instrument diffère de celle du compte.',
  duplicate_instrument: 'Cet instrument figure déjà dans une autre ligne.',
  invalid_decimal: 'Saisissez un nombre valide.',
  precision_exceeded: 'Ce montant dépasse la précision autorisée.',
  not_positive: 'Cette valeur doit être strictement positive.',
  negative: 'Cette valeur ne peut pas être négative.',
  quantity_precision_exceeded: 'La quantité dépasse la précision de l’instrument.',
  price_precision_exceeded: 'Le cours dépasse la précision de l’instrument.',
  price_date_after_takeover: 'La date du cours doit précéder la date de reprise ou l’égaler.',
  value_too_large: 'Cette valeur est trop grande.',
};
const requestTexts: Record<string, string> = {
  invalid_date: 'La date est invalide.',
  before_account_opening: 'La date précède l’ouverture du compte.',
  in_the_future: 'La date ne peut pas être dans le futur.',
  lines_count: 'Saisissez entre 1 et 200 lignes.',
  negative: 'Les espèces ne peuvent pas être négatives.',
  invalid_decimal: 'Saisissez un montant valide.',
  precision_exceeded: 'Ce montant dépasse la précision autorisée.',
  value_too_large: 'Cette valeur est trop grande.',
};
const fallback = 'Cette valeur est refusée.';
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
        row[field] ??= lineTexts[error.code] ?? fallback;
        continue;
      }
    }
    const text = requestTexts[error.code] ?? fallback;
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
