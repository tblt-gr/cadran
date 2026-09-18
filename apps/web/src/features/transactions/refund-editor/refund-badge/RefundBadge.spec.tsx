import type { Transaction } from '@cadran/api-client';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { RefundBadge } from './RefundBadge';

const transaction: Transaction = {
  id: '00000000-0000-7000-8000-0000000000f1',
  accountId: '00000000-0000-7000-8000-0000000000a1',
  amount: { value: '-42.90', assetCode: 'EUR' },
  originalAmount: null,
  exchangeRate: null,
  nature: 'EXPENSE',
  state: 'BOOKED',
  source: 'MANUAL',
  sourceRef: null,
  bookedOn: '2026-03-14',
  valueOn: null,
  authorizedOn: null,
  rawLabel: 'CB CARREFOUR 1234',
  counterparty: 'Carrefour',
  note: null,
  paymentMethod: 'CARD',
  mcc: null,
  maskedCard: null,
  bankReference: null,
  splits: [],
  version: 1,
  createdAt: '2026-03-14T09:12:04+01:00',
  updatedAt: '2026-03-14T09:12:04+01:00',
  voidedAt: null,
  transferId: null,
  refundOriginalId: null,
  refundOriginalLabel: null,
  refundedAmount: null,
  reviewReason: null,
  reconciliationCandidateIds: [],
  reconciledIntoId: null,
};

describe('RefundBadge', () => {
  afterEach(() => {
    cleanup();
  });

  it('renders nothing when the transaction is neither a refund nor refunded', () => {
    const { container } = render(<RefundBadge transaction={transaction} />);

    expect(container.textContent).toBe('');
  });

  it('names the original on a refund row', () => {
    render(
      <RefundBadge
        transaction={{ ...transaction, nature: 'REFUND', refundOriginalLabel: 'CB CARREFOUR 1234' }}
      />,
    );

    expect(screen.getByText('Remboursement de « CB CARREFOUR 1234 »')).toBeDefined();
  });

  it('shows the refunded share on the original row', () => {
    render(
      <RefundBadge
        transaction={{
          ...transaction,
          refundedAmount: { value: '30.00', assetCode: 'EUR' },
        }}
      />,
    );

    expect(screen.getByText(/Remboursé/)).toBeDefined();
  });
});
