import type { Account, Transaction } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { RefundEditForm } from './RefundEditForm';
vi.mock('@/features/auth/useWorkspaceTimeZone', () => ({ useWorkspaceTimeZone: () => 'UTC' }));
const account = {
  id: 'account-1',
  label: 'Compte courant',
  assetCode: 'EUR',
  openedOn: '2026-01-01',
  status: 'ACTIVE',
  valuationMode: 'TRANSACTIONS',
} as Account;
const transaction = {
  id: 'transaction-1',
  accountId: account.id,
  amount: { value: '1.50', assetCode: 'EUR' },
  originalAmount: null,
  exchangeRate: null,
  nature: 'REFUND',
  state: 'BOOKED',
  source: 'MANUAL',
  sourceRef: null,
  bookedOn: '2026-09-01',
  valueOn: null,
  authorizedOn: null,
  rawLabel: 'CARTE BOULANGERIE',
  userLabel: null,
  displayLabel: 'CARTE BOULANGERIE',
  counterparty: null,
  note: null,
  paymentMethod: 'CARD',
  mcc: null,
  maskedCard: null,
  bankReference: null,
  splits: [],
  version: 7,
  createdAt: '2026-09-01T09:00:00+02:00',
  updatedAt: '2026-09-01T09:00:00+02:00',
  voidedAt: null,
  transferId: null,
  refundOriginalId: 'original',
  refundOriginalLabel: 'Dépense initiale',
  refundedAmount: null,
  reviewReason: null,
  autoBook: false,
  reconciliationCandidateIds: [],
  reconciledIntoId: null,
} as Transaction;

afterEach(cleanup);
it('edits metadata and account while retaining the refund amount and original', () => {
  const submit = vi.fn();
  render(
    <RefundEditForm
      accounts={[account, { ...account, id: 'second', label: 'Autre compte' }]}
      transaction={transaction}
      onSubmit={submit}
      pending={false}
      submitError={null}
    />,
  );
  expect(screen.getByText(/Dépense initiale/)).toBeTruthy();
  expect((screen.getByLabelText('Montant') as HTMLInputElement).disabled).toBe(true);
  fireEvent.change(screen.getByLabelText('Compte'), { target: { value: 'second' } });
  fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Correction' } });
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  expect(submit).toHaveBeenCalledWith(
    expect.objectContaining({
      accountId: 'second',
      rawLabel: 'Correction',
      nature: 'REFUND',
      amount: transaction.amount,
      splits: [],
    }),
  );
});
it('refuses dates before the account opened', () => {
  const submit = vi.fn();
  render(
    <RefundEditForm
      accounts={[account]}
      transaction={transaction}
      onSubmit={submit}
      pending={false}
      submitError={null}
    />,
  );
  fireEvent.change(screen.getByLabelText('Date comptable'), { target: { value: '2025-01-01' } });
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  expect(submit).not.toHaveBeenCalled();
  expect(screen.getByRole('alert')).toBeTruthy();
});

it('keeps the allocation visible and disabled', () => {
  const refund = {
    ...transaction,
    splits: [
      {
        id: 'split',
        categoryId: 'category',
        categoryLabel: 'Courses',
        amount: transaction.amount,
        analyticAxes: ['ESSENTIAL'],
        note: 'Initial',
        categoryColor: null,
        categoryIcon: null,
      },
    ],
  } as Transaction;
  const submit = vi.fn();
  render(
    <RefundEditForm
      accounts={[account]}
      transaction={refund}
      onSubmit={submit}
      pending={false}
      submitError={null}
    />,
  );
  expect((screen.getByLabelText('Courses') as HTMLInputElement).disabled).toBe(true);
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  expect(submit).toHaveBeenCalledWith(
    expect.objectContaining({
      splits: [
        {
          categoryId: 'category',
          amount: transaction.amount,
          analyticAxes: ['ESSENTIAL'],
          note: 'Initial',
        },
      ],
    }),
  );
});
