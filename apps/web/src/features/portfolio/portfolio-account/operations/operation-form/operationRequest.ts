import type {
  DecimalAmount,
  PortfolioOperation,
  PortfolioOperationRequest,
  PortfolioOperationType,
} from '@cadran/api-client';
import { compareDecimals, isCanonicalUnsignedDecimal, isZeroDecimal } from '@/lib/decimal';
import { hasField, operationMatrix, type FieldKey } from './operationMatrix';

export interface PickedInstrument {
  id: string;
  name: string;
  instrumentType?: string;
}

export interface SupportLegValue {
  key: string;
  instrument: PickedInstrument | null;
  quantity: string;
  unitPrice: string;
  amount: string;
}

export interface OperationFormValues {
  legs: SupportLegValue[];
  withheldIncomeTax: string;
  withheldSocialContributions: string;
  type: PortfolioOperationType;
  tradeDate: string;
  settlementDate: string;
  instrument: PickedInstrument | null;
  quantity: string;
  unitPrice: string;
  grossAmount: string;
  fees: string;
  taxes: string;
  costBasis: string;
  counterInstrument: PickedInstrument | null;
  counterQuantity: string;
  ratioNumerator: string;
  ratioDenominator: string;
  costAllocationRatio: string;
  reference: string;
  note: string;
  linkedTransactionId: string;
  reason: string;
}

/** Keys are matrix fields plus the always-present ones. */
export type OperationFieldErrors = Partial<
  Record<
    | `legs.${number}.${string}`
    | FieldKey
    | 'tradeDate'
    | 'settlementDate'
    | 'reference'
    | 'note'
    | 'reason',
    string
  >
>;

export function emptyValues(tradeDate: string): OperationFormValues {
  return {
    legs: [],
    withheldIncomeTax: '',
    withheldSocialContributions: '',
    type: 'DEPOSIT',
    tradeDate,
    settlementDate: '',
    instrument: null,
    quantity: '',
    unitPrice: '',
    grossAmount: '',
    fees: '',
    taxes: '',
    costBasis: '',
    counterInstrument: null,
    counterQuantity: '',
    ratioNumerator: '',
    ratioDenominator: '',
    costAllocationRatio: '',
    reference: '',
    note: '',
    linkedTransactionId: '',
    reason: '',
  };
}

/** Prefills the form with every stored field of an operation. */
export function valuesFromOperation(operation: PortfolioOperation): OperationFormValues {
  return {
    legs: (operation.legs ?? []).map((leg) => ({
      key: String(leg.index),
      instrument: leg.instrument,
      quantity: leg.quantity,
      unitPrice: leg.unitPrice,
      amount: leg.amount.value,
    })),
    withheldIncomeTax: operation.withheldIncomeTax?.value ?? '',
    withheldSocialContributions: operation.withheldSocialContributions?.value ?? '',
    type: operation.type,
    tradeDate: operation.tradeDate,
    settlementDate: operation.settlementDate ?? '',
    instrument: operation.instrument
      ? { id: operation.instrument.id, name: operation.instrument.name }
      : null,
    quantity: operation.quantity ?? '',
    unitPrice: operation.unitPrice ?? '',
    grossAmount: operation.grossAmount?.value ?? '',
    fees: operation.fees && !isZeroDecimal(operation.fees.value) ? operation.fees.value : '',
    taxes: operation.taxes && !isZeroDecimal(operation.taxes.value) ? operation.taxes.value : '',
    costBasis: operation.costBasis?.value ?? '',
    counterInstrument: operation.counterInstrument
      ? { id: operation.counterInstrument.id, name: operation.counterInstrument.name }
      : null,
    counterQuantity: operation.counterQuantity ?? '',
    ratioNumerator: operation.ratio?.numerator ?? '',
    ratioDenominator: operation.ratio?.denominator ?? '',
    costAllocationRatio: operation.costAllocationRatio ?? '',
    reference: operation.reference ?? '',
    note: operation.note ?? '',
    linkedTransactionId: operation.linkedTransactionId ?? '',
    reason: '',
  };
}

const CONTROL_CHARACTERS = /[\p{Cc}\p{Cf}]/u;
const DECIMAL_MESSAGE = 'Saisissez un nombre décimal positif, sans exposant ni signe.';
const POSITIVE_MESSAGE = 'La valeur doit être strictement positive.';

/** A decimal typed by the user, with a comma accepted, or null when it is not canonical. */
function normalize(raw: string): string {
  return raw.trim().replace(',', '.');
}

function amount(value: string, assetCode: string): DecimalAmount {
  return { value, assetCode };
}

type Result =
  | { request: PortfolioOperationRequest; errors: null }
  | { request: null; errors: OperationFieldErrors };

/**
 * Turns the typed values into a create body. Only the fields of the type matrix
 * are read, decimals stay strings, and the cash effect is never part of it.
 */
export function buildOperationRequest(values: OperationFormValues, assetCode: string): Result {
  const errors: OperationFieldErrors = {};
  const request: PortfolioOperationRequest = { type: values.type, tradeDate: values.tradeDate };
  const matrix = operationMatrix[values.type];

  if (values.tradeDate === '') errors.tradeDate = 'La date d’opération est obligatoire.';
  if (values.settlementDate !== '') {
    if (values.settlementDate < values.tradeDate) {
      errors.settlementDate = 'La date de règlement ne peut précéder la date d’opération.';
    } else {
      request.settlementDate = values.settlementDate;
    }
  }

  function decimal(field: FieldKey, raw: string, allowZero = false): string | undefined {
    const requirement = matrix[field];
    if (requirement === undefined) return undefined;
    const value = normalize(raw);
    if (value === '') {
      if (requirement === 'R') errors[field] = 'Ce champ est obligatoire.';
      return undefined;
    }
    if (!isCanonicalUnsignedDecimal(value)) {
      errors[field] = DECIMAL_MESSAGE;
      return undefined;
    }
    if (!allowZero && isZeroDecimal(value)) {
      errors[field] = POSITIVE_MESSAGE;
      return undefined;
    }
    return value;
  }
  function picked(field: 'instrument' | 'counterInstrument', value: PickedInstrument | null) {
    if (matrix[field] === 'R' && value === null) errors[field] = 'Choisissez un instrument.';
    return matrix[field] !== undefined ? (value?.id ?? undefined) : undefined;
  }

  const instrumentId = picked('instrument', values.instrument);
  if (instrumentId) request.instrumentId = instrumentId;
  const counterInstrumentId = picked('counterInstrument', values.counterInstrument);
  if (counterInstrumentId) request.counterInstrumentId = counterInstrumentId;
  if (counterInstrumentId && counterInstrumentId === instrumentId) {
    errors.counterInstrument = 'L’instrument reçu doit différer de l’instrument d’origine.';
  }

  const quantity = decimal('quantity', values.quantity);
  if (quantity) request.quantity = quantity;
  const unitPrice = decimal('unitPrice', values.unitPrice);
  if (unitPrice) request.unitPrice = unitPrice;
  const gross = decimal('grossAmount', values.grossAmount);
  if (gross) request.grossAmount = amount(gross, assetCode);
  const fees = decimal('fees', values.fees, values.type !== 'FEE');
  if (fees) request.fees = amount(fees, assetCode);
  const taxes = decimal('taxes', values.taxes, values.type !== 'TAX');
  if (taxes) request.taxes = amount(taxes, assetCode);
  for (const field of ['withheldIncomeTax', 'withheldSocialContributions'] as const) {
    const value = decimal(field, values[field], true);
    if (value) request[field] = amount(value, assetCode);
  }
  if (hasField(values.type, 'legs')) {
    if (values.legs.length > 20) errors.legs = '20 supports au maximum.';
    request.legs = values.legs.map((leg, index) => {
      const prefix = `legs.${index + 1}` as const;
      const validate = (field: 'quantity' | 'unitPrice' | 'amount') => {
        const value = normalize(leg[field]);
        if (!isCanonicalUnsignedDecimal(value) || isZeroDecimal(value))
          errors[`${prefix}.${field}`] = 'Saisissez un nombre décimal strictement positif.';
        return value;
      };
      if (!leg.instrument) errors[`${prefix}.instrument`] = 'Choisissez un support.';
      return {
        direction: values.type === 'PREMIUM' ? 'IN' : 'OUT',
        instrumentId: leg.instrument?.id ?? '',
        ...(leg.instrument?.instrumentType === 'EURO_FUND'
          ? {}
          : { quantity: validate('quantity'), unitPrice: validate('unitPrice') }),
        amount: amount(validate('amount'), assetCode),
      };
    });
  }
  const costBasis = decimal('costBasis', values.costBasis, true);
  if (costBasis) request.costBasis = amount(costBasis, assetCode);
  const counterQuantity = decimal('counterQuantity', values.counterQuantity);
  if (counterQuantity) request.counterQuantity = counterQuantity;

  if (hasField(values.type, 'costAllocationRatio')) {
    const ratio = decimal('costAllocationRatio', values.costAllocationRatio);
    if (ratio && compareDecimals(ratio, '1') >= 0) {
      errors.costAllocationRatio = 'Le ratio doit être strictement compris entre 0 et 1.';
    } else if (ratio) {
      request.costAllocationRatio = ratio;
    }
  }

  if (hasField(values.type, 'ratio')) {
    const numerator = normalize(values.ratioNumerator);
    const denominator = normalize(values.ratioDenominator);
    if (
      !isCanonicalUnsignedDecimal(numerator) ||
      !isCanonicalUnsignedDecimal(denominator) ||
      isZeroDecimal(numerator) ||
      isZeroDecimal(denominator)
    ) {
      errors.ratio = 'Saisissez deux nombres strictement positifs.';
    } else if (compareDecimals(numerator, denominator) === 0) {
      errors.ratio = 'Les deux termes du ratio doivent être différents.';
    } else {
      request.ratio = { numerator, denominator };
    }
  }

  if (hasField(values.type, 'linkedTransaction') && values.linkedTransactionId !== '') {
    request.linkedTransactionId = values.linkedTransactionId;
  }

  for (const field of ['reference', 'note', 'reason'] as const) {
    const text = values[field].trim();
    const max = field === 'reference' ? 64 : 200;
    if (text === '') continue;
    if (text.length > max || CONTROL_CHARACTERS.test(text)) {
      errors[field] = `Au plus ${max} caractères, sans caractère de contrôle.`;
    } else if (field !== 'reason') {
      request[field] = text;
    }
  }

  return Object.keys(errors).length > 0 ? { request: null, errors } : { request, errors: null };
}
