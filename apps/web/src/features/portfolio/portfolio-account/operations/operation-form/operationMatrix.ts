import type { PortfolioOperationType } from '@cadran/api-client';

export type FieldKey =
  | 'interestBasis'
  | 'rateYear'
  | 'legs'
  | 'withheldIncomeTax'
  | 'withheldSocialContributions'
  | 'instrument'
  | 'quantity'
  | 'unitPrice'
  | 'grossAmount'
  | 'fees'
  | 'taxes'
  | 'costBasis'
  | 'counterInstrument'
  | 'counterQuantity'
  | 'ratio'
  | 'costAllocationRatio'
  | 'linkedTransaction';

type Requirement = 'R' | 'O';

/** Mirrors the server type matrix: a missing key is a forbidden field. */
export const operationMatrix: Record<
  PortfolioOperationType,
  Partial<Record<FieldKey, Requirement>>
> = {
  EURO_FUND_INTEREST: {
    instrument: 'R',
    grossAmount: 'R',
    taxes: 'O',
    interestBasis: 'R',
    rateYear: 'R',
  },
  PROFIT_SHARING: {
    instrument: 'R',
    grossAmount: 'R',
    taxes: 'O',
    interestBasis: 'R',
    rateYear: 'R',
  },
  MANAGEMENT_FEE: { instrument: 'O', fees: 'R', quantity: 'O', unitPrice: 'O' },
  SOCIAL_CONTRIBUTION: { instrument: 'R', taxes: 'R', rateYear: 'R' },
  SWITCH: { legs: 'R', fees: 'O' },
  PREMIUM: { grossAmount: 'R', fees: 'O', legs: 'O', linkedTransaction: 'O' },
  PARTIAL_SURRENDER: {
    grossAmount: 'R',
    fees: 'O',
    legs: 'O',
    withheldIncomeTax: 'O',
    withheldSocialContributions: 'O',
    linkedTransaction: 'O',
  },
  FULL_SURRENDER: {
    grossAmount: 'R',
    fees: 'O',
    legs: 'O',
    withheldIncomeTax: 'O',
    withheldSocialContributions: 'O',
    linkedTransaction: 'O',
  },
  DEPOSIT: { grossAmount: 'R', linkedTransaction: 'O' },
  WITHDRAWAL: { grossAmount: 'R', linkedTransaction: 'O' },
  BUY: { instrument: 'R', quantity: 'R', unitPrice: 'R', grossAmount: 'R', fees: 'O', taxes: 'O' },
  SELL: { instrument: 'R', quantity: 'R', unitPrice: 'R', grossAmount: 'R', fees: 'O', taxes: 'O' },
  DIVIDEND: { instrument: 'R', quantity: 'O', grossAmount: 'R', fees: 'O', taxes: 'O' },
  INTEREST: { instrument: 'O', grossAmount: 'R', fees: 'O', taxes: 'O' },
  FEE: { instrument: 'O', fees: 'R' },
  TAX: { instrument: 'O', taxes: 'R' },
  SECURITY_TRANSFER_IN: { instrument: 'R', quantity: 'R', costBasis: 'O' },
  SECURITY_TRANSFER_OUT: { instrument: 'R', quantity: 'R' },
  SPLIT: { instrument: 'R', ratio: 'R' },
  MERGER: {
    instrument: 'R',
    grossAmount: 'O',
    counterInstrument: 'R',
    counterQuantity: 'R',
  },
  SPIN_OFF: {
    instrument: 'R',
    counterInstrument: 'R',
    counterQuantity: 'R',
    costAllocationRatio: 'R',
  },
};

export function hasField(type: PortfolioOperationType, field: FieldKey): boolean {
  return operationMatrix[type][field] !== undefined;
}
