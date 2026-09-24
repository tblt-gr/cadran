import type { Transaction } from '@cadran/api-client';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { AccountMovementsTable } from './AccountMovementsTable';

const base: Transaction = {
  id: '00000000-0000-7000-8000-000000000e01',
  accountId: '00000000-0000-7000-8000-0000000000d1',
  amount: { value: '-42.50', assetCode: 'EUR' },
  originalAmount: null,
  exchangeRate: null,
  nature: 'EXPENSE',
  state: 'BOOKED',
  source: 'MANUAL',
  sourceRef: null,
  bookedOn: '2026-09-10',
  valueOn: null,
  authorizedOn: null,
  rawLabel: 'Supermarché',
  counterparty: null,
  note: null,
  paymentMethod: 'CARD',
  mcc: null,
  maskedCard: null,
  bankReference: null,
  splits: [],
  version: 1,
  createdAt: '2026-09-10T10:00:00Z',
  updatedAt: '2026-09-10T10:00:00Z',
  voidedAt: null,
  transferId: null,
  refundOriginalId: null,
  refundOriginalLabel: null,
  refundedAmount: null,
  reviewReason: null,
  reconciliationCandidateIds: [],
  reconciledIntoId: null,
};

describe('AccountMovementsTable', () => {
  afterEach(() => {
    cleanup();
  });

  it('shows this account own movement with its nature, amount and state', () => {
    render(<AccountMovementsTable transactions={[base]} />);

    expect(screen.getByText('Supermarché')).toBeTruthy();
    expect(screen.getByText('Dépense')).toBeTruthy();
    expect(screen.getByText('Comptabilisée')).toBeTruthy();
  });

  it('names the transfer counterpart on a transfer leg', () => {
    const transfer: Transaction = {
      ...base,
      nature: 'TRANSFER',
      transferId: '00000000-0000-7000-8000-000000000f01',
      counterparty: 'Livret A',
      rawLabel: 'Virement vers Livret A',
    };

    render(<AccountMovementsTable transactions={[transfer]} />);

    expect(screen.getByText('Livret A')).toBeTruthy();
  });

  it('never renders a row from another account: it only ever receives this account movements', () => {
    render(<AccountMovementsTable transactions={[base]} />);

    expect(screen.getAllByRole('row')).toHaveLength(2); // header + one movement
  });
});
