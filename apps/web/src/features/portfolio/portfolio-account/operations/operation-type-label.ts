import type { PortfolioOperationType } from '@cadran/api-client';
import type { TFunction } from 'i18next';

/** Frequent operations first, the yearly or rare ones after: this is the order of the pickers. */
export const operationTypes: readonly PortfolioOperationType[] = [
  'PREMIUM',
  'PARTIAL_SURRENDER',
  'FULL_SURRENDER',
  'SWITCH',
  'BUY',
  'SELL',
  'DEPOSIT',
  'WITHDRAWAL',
  'DIVIDEND',
  'EURO_FUND_INTEREST',
  'PROFIT_SHARING',
  'MANAGEMENT_FEE',
  'SOCIAL_CONTRIBUTION',
  'INTEREST',
  'FEE',
  'TAX',
  'SECURITY_TRANSFER_IN',
  'SECURITY_TRANSFER_OUT',
  'SPLIT',
  'MERGER',
  'SPIN_OFF',
];

export function operationTypeLabel(t: TFunction, type: PortfolioOperationType): string {
  return t(`portfolio.account.operationType.${type}`);
}

/** Mirrors OperationTypePolicy for both creation and filtering. */
export function allowedOperationTypes(kind?: string): readonly PortfolioOperationType[] {
  return operationTypes.filter((type) =>
    kind === 'INSURANCE_CONTRACT'
      ? ![
          'DEPOSIT',
          'WITHDRAWAL',
          'SECURITY_TRANSFER_IN',
          'SECURITY_TRANSFER_OUT',
          'FEE',
          'TAX',
          'INTEREST',
        ].includes(type)
      : ![
          'SWITCH',
          'PREMIUM',
          'PARTIAL_SURRENDER',
          'FULL_SURRENDER',
          'EURO_FUND_INTEREST',
          'PROFIT_SHARING',
          'MANAGEMENT_FEE',
          'SOCIAL_CONTRIBUTION',
        ].includes(type),
  );
}
