import type { Account, Transaction } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionActionsContext } from '@/features/transactions/transaction-workflow/transactionActions';
import { TransactionRowActions } from './TransactionRowActions';

afterEach(() => {
  cleanup();
  window.history.replaceState({}, '', '/');
});

it('links and navigates a portfolio movement directly to its existing operations tab', () => {
  const transaction = {
    id: 'movement',
    accountId: 'portfolio',
    amount: { value: '-100.25', assetCode: 'EUR' },
    bookedOn: '2026-09-12',
    rawLabel: 'Opération de portefeuille',
    displayLabel: 'Opération de portefeuille',
    state: 'BOOKED',
    nature: 'EXPENSE',
    transferId: null,
    reviewReason: null,
    refundedAmount: null,
  } as Transaction;
  render(
    <TransactionActionsContext.Provider
      value={{
        accounts: [{ id: 'portfolio', valuationMode: 'PORTFOLIO' } as Account],
        closedMonths: new Set<string>(),
        duplicatingIds: new Set<string>(),
        open: vi.fn(),
      }}
    >
      <TransactionRowActions transaction={transaction} />
    </TransactionActionsContext.Provider>,
  );
  const link = screen.getByRole('link', { name: 'Ouvrir les opérations du portefeuille' });
  expect(link.getAttribute('href')).toBe('/portfolios/portfolio?tab=operations');
  fireEvent.click(link);
  expect(window.location.pathname).toBe('/portfolios/portfolio');
  expect(window.location.search).toBe('?tab=operations');
});
