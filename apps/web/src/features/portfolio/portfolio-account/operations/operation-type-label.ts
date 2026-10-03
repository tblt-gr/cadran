import type { PortfolioOperationType } from '@cadran/api-client';

export const operationTypes: readonly PortfolioOperationType[] = [
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
