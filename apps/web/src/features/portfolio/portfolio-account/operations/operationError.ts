import type { PortfolioOperationProblem } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import type { OperationFieldErrors } from './operation-form/operationRequest';

type ResultLike = { error?: unknown; response?: Response };

/** A refused portfolio request, classified by the problem `code` of the API. */
export class OperationRequestError extends Error {
  readonly legIndex?: number;
  readonly expectedAmount?: string;
  readonly expectedIncomingAmount?: string;
  readonly status: number;
  readonly code?: string;
  readonly type?: string;
  readonly field?: string;
  readonly expectedGrossAmount?: string;
  readonly instrumentId?: string;
  readonly tradeDate?: string;

  constructor(result: ResultLike) {
    super('Portfolio operation request failed.');
    const problem = result.error as Partial<PortfolioOperationProblem> | undefined;
    this.legIndex = problem?.legIndex;
    this.expectedAmount = problem?.expectedAmount;
    this.expectedIncomingAmount = problem?.expectedIncomingAmount;
    this.status = result.response?.status ?? 0;
    this.code = problem?.code;
    this.type = problem?.type;
    this.field = problem?.field;
    this.expectedGrossAmount = problem?.expectedGrossAmount;
    this.instrumentId = problem?.instrumentId;
    this.tradeDate = (problem as { tradeDate?: string } | undefined)?.tradeDate;
  }
}

export function operationRequestError(result: ResultLike) {
  return new OperationRequestError(result);
}

const fieldByCode: Record<string, keyof OperationFieldErrors> = {
  instrument_not_euro_fund: 'instrument',
  invalid_year: 'rateYear',
  invalid_interest_basis: 'interestBasis',
  switch_same_support: 'legs',
  unknown_leg_instrument: 'legs',
  operation_asset_mismatch: 'legs',
  duplicate_leg_instrument: 'legs',
  invalid_leg_direction: 'legs',
  premium_allocation_exceeds_net: 'grossAmount',
  surrender_exceeds_available: 'grossAmount',
  invalid_surrender_amounts: 'grossAmount',
  full_surrender_not_balanced: 'grossAmount',
  invalid_operation_quantity: 'quantity',
  quantity_precision_exceeded: 'quantity',
  price_precision_exceeded: 'unitPrice',
  instrument_inactive: 'instrument',
  instrument_currency_mismatch: 'instrument',
  unknown_instrument: 'instrument',
  invalid_corporate_action: 'counterInstrument',
  invalid_operation_date: 'tradeDate',
  invalid_settlement_date: 'settlementDate',
  operation_amount_mismatch: 'grossAmount',
  linked_transfer_mismatch: 'linkedTransaction',
  linked_transfer_taken: 'linkedTransaction',
  invalid_operation_amount: 'grossAmount',
  invalid_operation_text: 'note',
};
const fieldByName: Record<string, keyof OperationFieldErrors> = {
  interestBasis: 'interestBasis',
  rateYear: 'rateYear',
  withheldIncomeTax: 'withheldIncomeTax',
  withheldSocialContributions: 'withheldSocialContributions',
  legs: 'legs',
  instrumentId: 'instrument',
  counterInstrumentId: 'counterInstrument',
  linkedTransactionId: 'linkedTransaction',
  quantity: 'quantity',
  unitPrice: 'unitPrice',
  grossAmount: 'grossAmount',
  fees: 'fees',
  taxes: 'taxes',
  costBasis: 'costBasis',
  counterQuantity: 'counterQuantity',
  ratio: 'ratio',
  costAllocationRatio: 'costAllocationRatio',
  reference: 'reference',
  note: 'note',
  settlementDate: 'settlementDate',
  tradeDate: 'tradeDate',
};
const fieldTextCodes = [
  'instrument_not_euro_fund',
  'invalid_year',
  'invalid_interest_basis',
  'premium_allocation_exceeds_net',
  'surrender_exceeds_available',
  'invalid_surrender_amounts',
  'full_surrender_not_balanced',
  'switch_same_support',
  'unknown_leg_instrument',
  'duplicate_leg_instrument',
  'invalid_leg_direction',
  'euro_fund_price_fixed',
  'operation_asset_mismatch',
  'invalid_operation_quantity',
  'quantity_precision_exceeded',
  'price_precision_exceeded',
  'instrument_inactive',
  'instrument_currency_mismatch',
  'unknown_instrument',
  'invalid_corporate_action',
  'invalid_operation_date',
  'invalid_settlement_date',
  'linked_transfer_mismatch',
  'linked_transfer_taken',
  'invalid_operation_amount',
  'invalid_operation_text',
  'operation_field_required',
  'operation_field_not_allowed',
] as const;
const messageCodes = [
  'full_surrender_positions_remaining',
  'operations_after_full_surrender',
  'too_many_operation_legs',
  'life_insurance_contract_required',
  'life_insurance_contract_closed',
  'operation_type_not_allowed_for_account',
  'euro_fund_price_fixed',
  'account_not_portfolio',
  'portfolio_account_inactive',
  'operation_limit_reached',
  'stale_operation_version',
  'operation_voided',
  'portfolio_operation_conflict',
  'unsupported_operation_type',
  'portfolio_payload_too_large',
] as const;

function fieldText(t: TFunction, code: string): string | undefined {
  return (fieldTextCodes as readonly string[]).includes(code)
    ? t(`portfolio.account.operationError.field.${code as (typeof fieldTextCodes)[number]}`)
    : undefined;
}

function messageText(t: TFunction, code: string): string | undefined {
  return (messageCodes as readonly string[]).includes(code)
    ? t(`portfolio.account.operationError.message.${code as (typeof messageCodes)[number]}`)
    : undefined;
}

/** Message for the gross amount refusal, with the exact value the server expects. */
function mismatchMessage(t: TFunction, expected: string | undefined): string {
  return expected
    ? t('portfolio.account.operationError.grossMismatchExpected', { expected })
    : t('portfolio.account.operationError.grossMismatch');
}

export function operationFieldErrors(error: unknown, t: TFunction): OperationFieldErrors {
  if (!(error instanceof OperationRequestError) || !error.code) return {};
  if (error.code === 'switch_not_balanced')
    return {
      legs: t('portfolio.account.operationError.switchNotBalanced', {
        amount: error.expectedIncomingAmount ?? '-',
      }),
    };
  if (error.legIndex !== undefined) {
    const field =
      error.code === 'leg_amount_mismatch'
        ? 'amount'
        : error.code === 'quantity_precision_exceeded' ||
            error.code === 'invalid_operation_quantity'
          ? 'quantity'
          : error.code === 'price_precision_exceeded' || error.code === 'euro_fund_price_fixed'
            ? 'unitPrice'
            : 'row';
    const message =
      error.code === 'leg_amount_mismatch'
        ? t('portfolio.account.operationError.legAmountMismatch', {
            amount: error.expectedAmount ?? '-',
          })
        : (fieldText(t, error.code) ?? t('portfolio.account.operationError.legInvalid'));
    return { [`legs.${error.legIndex}.${field}`]: message };
  }
  if (error.code === 'full_surrender_not_balanced')
    return {
      grossAmount: t('portfolio.account.operationError.fullSurrenderExpected', {
        amount: error.expectedGrossAmount ?? '-',
      }),
    };
  if (error.code === 'operation_amount_mismatch') {
    return { grossAmount: mismatchMessage(t, error.expectedGrossAmount) };
  }
  const named = error.field ? fieldByName[error.field] : undefined;
  if (error.code === 'operation_field_required' || error.code === 'operation_field_not_allowed') {
    return named ? { [named]: fieldText(t, error.code)! } : {};
  }
  const field =
    (named && error.code === 'invalid_operation_amount' ? named : undefined) ??
    fieldByCode[error.code];
  const text = fieldText(t, error.code);
  return field && text ? { [field]: text } : {};
}

/** General message for a refused save; field-level refusals are shown next to their field. */
export function operationSaveMessage(
  error: unknown,
  t: TFunction,
  instruments: ReadonlyArray<{ id: string; name: string } | null> = [],
): string | undefined {
  if (!error) return undefined;
  if (!(error instanceof OperationRequestError) || error.status === 0) {
    return t('portfolio.account.operationError.connection');
  }
  if (error.type === '/problems/period-closed') {
    return t('portfolio.account.operationError.periodClosed');
  }
  if (error.code === 'position_would_go_negative') {
    const instrument = instruments.find((item) => item?.id === error.instrumentId)?.name;
    return t('portfolio.account.operationError.negativePosition', {
      instrument: instrument ?? t('portfolio.account.operationError.negativePositionInstrument'),
      date: error.tradeDate ?? t('portfolio.account.operationError.negativePositionDate'),
    });
  }
  const message = error.code ? messageText(t, error.code) : undefined;
  if (message) return message;
  if (error.code && Object.keys(operationFieldErrors(error, t)).length > 0) return undefined;
  return t('portfolio.account.operationError.refused');
}

export function isStaleOperationError(error: unknown): boolean {
  return (
    error instanceof OperationRequestError &&
    (error.code === 'stale_operation_version' || error.code === 'operation_voided')
  );
}
