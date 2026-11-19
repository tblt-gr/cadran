import type { Transaction } from '@cadran/api-client';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { VoidTransactionDialog } from './VoidTransactionDialog';

afterEach(cleanup);

const transaction = { displayLabel: 'Salaire' } as Transaction;

it('shows the refusal of a void that would overdraw an account, with the date', () => {
  render(
    <VoidTransactionDialog
      onCancel={vi.fn()}
      onConfirm={vi.fn()}
      pending={false}
      submitError="insufficientBalance"
      submitErrorDay="2026-11-06"
      transaction={transaction}
    />,
  );

  expect(screen.getByRole('alert').textContent).toBe(
    'Solde insuffisant : ce montant mettrait le compte en négatif le 6 novembre 2026.',
  );
});
