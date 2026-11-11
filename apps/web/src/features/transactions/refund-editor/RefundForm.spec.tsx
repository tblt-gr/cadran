import type { Account, RefundableTransaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { formatAmount } from '@/lib/decimal';
import { RefundForm } from './RefundForm';
import { RefundFormSkeleton } from './RefundFormSkeleton';

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

  it.each([
    ['active primary', { isPrimary: true, status: 'ACTIVE' }, 'primary-account'],
    ['different asset', { isPrimary: true, status: 'ACTIVE', assetCode: 'USD' }, account.id],
    ['archived primary', { isPrimary: true, status: 'ARCHIVED' }, account.id],
    ['no primary', { isPrimary: false, status: 'ACTIVE' }, account.id],
  ] as const)('selects the expected default account with %s', (_name, overrides, expected) => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <RefundForm
          accounts={[
            account,
            { ...account, id: 'primary-account', label: 'Compte principal', ...overrides },
          ]}
          onSubmit={vi.fn()}
          pending={false}
          proposal={proposal}
          submitError={null}
          submitErrorDetail={null}
        />
      </QueryClientProvider>,
    );
    const select = screen.getByRole('combobox', {
      name: 'Compte',
    }) as HTMLSelectElement;
    expect(select.value).toBe(expected);
    fireEvent.change(select, { target: { value: account.id } });
    expect(select.value).toBe(account.id);
  });

  it('keeps the essential fields and folded advanced section in the loading grid', () => {
    render(<RefundFormSkeleton />);
    const skeleton = screen.getByTestId('refund-form-skeleton');
    const advanced = screen.getByText('Champs avancés').closest('details');
    expect(advanced?.open).toBe(false);
    expect(skeleton.textContent).toContain('Montant');
    expect(skeleton.tagName).toBe('FORM');
    expect(advanced?.querySelectorAll('label')).toHaveLength(2);
    expect(skeleton.querySelectorAll('label')).toHaveLength(6);
    expect(advanced?.parentElement?.parentElement?.textContent).toContain('Montant');
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

    expect(screen.getByText('Champs avancés').closest('details')?.open).toBe(false);
    expect(screen.getByLabelText('Note').closest('details')).toBeTruthy();
    expect(screen.getByLabelText('Montant').closest('details')).toBeNull();

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
    const input = screen.getByLabelText('Montant');
    fireEvent.change(input, { target: { value: '10,01' } });
    expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(screen.getByRole('alert').textContent).toContain('solde remboursable');
  });

  it.each(['.', ','])(
    'accepts decimal separator %s without crashing or losing the draft',
    (separator) => {
      const submit = vi.fn();
      const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
      render(
        <QueryClientProvider client={queryClient}>
          <RefundForm
            accounts={[account]}
            onSubmit={submit}
            pending={false}
            proposal={proposal}
            submitError={null}
            submitErrorDetail={null}
          />
        </QueryClientProvider>,
      );
      fireEvent.change(screen.getByLabelText('Date comptable'), {
        target: { value: '2026-03-14' },
      });
      fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Remboursement' } });
      const amount = screen.getByLabelText('Montant') as HTMLInputElement;
      const save = screen.getByRole('button', {
        name: 'Créer le remboursement',
      }) as HTMLButtonElement;
      for (const value of ['', '2', `2${separator}`, `2${separator}6`]) {
        fireEvent.change(amount, { target: { value } });
        expect(amount.value).toBe(value);
        expect((screen.getByLabelText('Libellé') as HTMLInputElement).value).toBe('Remboursement');
        expect(save.disabled).toBe(value === '' || value.endsWith(separator));
      }
      fireEvent.click(save);
      expect(submit).toHaveBeenCalledWith(
        expect.objectContaining({ amount: { value: '2.6', assetCode: 'EUR' } }),
      );
    },
  );

  it('blocks submission when the amount changes but the shown allocation is left untouched', async () => {
    api.listAssets.mockImplementation(() =>
      success({ items: [{ code: 'EUR', displayPrecision: 2 }], page: 1, pageSize: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
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
      await screen.findByText('Ajustez la répartition au montant du remboursement.'),
    ).toBeTruthy();
    expect(
      (screen.getByRole('button', { name: 'Créer le remboursement' }) as HTMLButtonElement)
        .disabled,
    ).toBe(true);
    expect(screen.getByText(/la somme des lignes doit être égale/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: /^Affecter le reste à la ligne/ }));
    expect((screen.getByLabelText('Montant de la ligne 1') as HTMLInputElement).value).toBe('5.00');
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '5.' } });
    expect(
      (screen.getByRole('button', { name: 'Créer le remboursement' }) as HTMLButtonElement)
        .disabled,
    ).toBe(true);
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '5,00' } });

    expect(
      (screen.getByRole('button', { name: 'Créer le remboursement' }) as HTMLButtonElement)
        .disabled,
    ).toBe(false);
  });
});
