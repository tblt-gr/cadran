import type { Account, RefundableTransaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { RefundForm } from './RefundForm';

const account = {
  id: '00000000-0000-7000-8000-0000000000a1',
  label: 'Compte courant',
  assetCode: 'EUR',
  openedOn: '2026-01-01',
  status: 'ACTIVE',
} as Account;

const proposal: RefundableTransaction = {
  originalId: '00000000-0000-7000-8000-0000000000t1',
  originalAmount: { value: '-10.00', assetCode: 'EUR' },
  refunded: { value: '0', assetCode: 'EUR' },
  refundable: { value: '10.00', assetCode: 'EUR' },
  proposedSplits: [],
};

describe('RefundForm', () => {
  it('shows the server balance before the amount and blocks a larger client-side amount', () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={queryClient}>
        <RefundForm
          accounts={[account]}
          onSubmit={vi.fn()}
          pending={false}
          proposal={proposal}
          submitError={null}
        />
      </QueryClientProvider>,
    );

    expect(document.body.contains(screen.getByText('10.00 EUR'))).toBe(true);
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '10.01' } });

    expect(
      document.body.contains(
        screen.getByText('Le montant ne peut pas dépasser le solde remboursable affiché.'),
      ),
    ).toBe(true);
    expect(
      (screen.getByRole('button', { name: 'Créer le remboursement' }) as HTMLButtonElement)
        .disabled,
    ).toBe(true);
  });
});
