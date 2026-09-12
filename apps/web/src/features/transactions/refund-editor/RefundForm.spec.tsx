import type { Account, RefundableTransaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { formatAmount } from '@/lib/decimal';
import { RefundForm } from './RefundForm';

const api = vi.hoisted(() => ({
  listAssets: vi.fn(),
  listCategories: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

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
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

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
          submitErrorDetail={null}
        />
      </QueryClientProvider>,
    );

    const expectedRemaining = formatAmount('10.00', 'EUR', 'fr').replace(/\s+/g, ' ');
    expect(screen.getAllByText(expectedRemaining).length).toBeGreaterThan(0);
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

  it('blocks submission when the amount changes but the shown allocation is left untouched', async () => {
    api.listAssets.mockImplementation(() =>
      success({ items: [{ code: 'EUR', displayPrecision: 2 }], page: 1, perPage: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    const splitProposal: RefundableTransaction = {
      ...proposal,
      proposedSplits: [
        {
          categoryId: '00000000-0000-7000-8000-0000000000c1',
          amount: { value: '10.00', assetCode: 'EUR' },
        },
      ],
    };
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={queryClient}>
        <RefundForm
          accounts={[account]}
          onSubmit={vi.fn()}
          pending={false}
          proposal={splitProposal}
          submitError={null}
          submitErrorDetail={null}
        />
      </QueryClientProvider>,
    );

    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '5.00' } });
    fireEvent.change(screen.getByLabelText('Date comptable'), {
      target: { value: '2026-03-14' },
    });
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Remboursement' } });

    expect(
      await screen.findByText(
        'La répartition affichée correspond au solde intégral : ajustez-la à la main pour ce montant réduit.',
      ),
    ).toBeTruthy();
    expect(
      (screen.getByRole('button', { name: 'Créer le remboursement' }) as HTMLButtonElement)
        .disabled,
    ).toBe(true);
  });
});
