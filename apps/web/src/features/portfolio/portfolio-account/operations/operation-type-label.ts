import type { PortfolioOperationType } from '@cadran/api-client';

export const operationTypes: readonly PortfolioOperationType[] = [
  'SWITCH',
  'PREMIUM',
  'PARTIAL_SURRENDER',
  'FULL_SURRENDER',
  'DEPOSIT',
  'WITHDRAWAL',
  'BUY',
  'SELL',
  'DIVIDEND',
  'INTEREST',
  'FEE',
  'TAX',
  'SECURITY_TRANSFER_IN',
  'SECURITY_TRANSFER_OUT',
  'SPLIT',
  'MERGER',
  'SPIN_OFF',
];

const labels: Record<PortfolioOperationType, string> = {
  SWITCH: 'Arbitrage',
  PREMIUM: 'Versement',
  PARTIAL_SURRENDER: 'Rachat partiel',
  FULL_SURRENDER: 'Rachat total',
  DEPOSIT: 'Versement',
  WITHDRAWAL: 'Retrait',
  BUY: 'Achat',
  SELL: 'Vente',
  DIVIDEND: 'Dividende',
  INTEREST: 'Intérêts',
  FEE: 'Frais',
  TAX: 'Taxe',
  SECURITY_TRANSFER_IN: 'Transfert de titres entrant',
  SECURITY_TRANSFER_OUT: 'Transfert de titres sortant',
  SPLIT: 'Division',
  MERGER: 'Fusion',
  SPIN_OFF: 'Scission',
};

export function operationTypeLabel(type: PortfolioOperationType): string {
  return labels[type];
}

/** Mirrors OperationTypePolicy for both creation and filtering. */
export function allowedOperationTypes(kind?: string): readonly PortfolioOperationType[] {
  return operationTypes.filter((type) =>
    kind === 'INSURANCE_CONTRACT'
      ? !['DEPOSIT', 'WITHDRAWAL', 'SECURITY_TRANSFER_IN', 'SECURITY_TRANSFER_OUT'].includes(type)
      : !['SWITCH', 'PREMIUM', 'PARTIAL_SURRENDER', 'FULL_SURRENDER'].includes(type),
  );
}
