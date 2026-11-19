import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionForm } from './TransactionForm';

vi.mock('@/features/auth/useWorkspaceTimeZone', () => ({ useWorkspaceTimeZone: () => 'UTC' }));

const account = {
  id: 'account-1',
  label: 'Compte courant',
  assetCode: 'EUR',
  openedOn: '2026-01-01',
  status: 'ACTIVE',
  isPrimary: true,
} as Account;

const HINT =
  'Date à venir : la transaction est enregistrée en attente puis comptabilisée automatiquement à cette date.';

function renderForm(onSubmit = vi.fn()) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <TransactionForm
        accounts={[account]}
        onSubmit={onSubmit}
        pending={false}
        submitError={null}
      />
    </QueryClientProvider>,
  );
  return onSubmit;
}

function setDate(value: string) {
  fireEvent.change(screen.getByLabelText(/^Date comptable/), { target: { value } });
}

function stateSelect() {
  return screen.getByRole('combobox', { name: 'État' }) as HTMLSelectElement;
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-11-10T10:00:00Z'));
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

describe('TransactionForm planned dates', () => {
  it('shows no hint for a present date and keeps the state choice free', () => {
    renderForm();
    expect(screen.queryByText(HINT)).toBeNull();
    expect(stateSelect().disabled).toBe(false);
  });

  it('shows the hint and locks the state to pending for a date later this month', () => {
    renderForm();
    setDate('2026-11-20');

    expect(screen.getByText(HINT)).toBeTruthy();
    expect(stateSelect().value).toBe('PENDING');
    const booked = within(stateSelect()).getByRole('option', {
      name: 'Comptabilisée',
    }) as HTMLOptionElement;
    expect(booked.disabled).toBe(true);
  });

  it('keeps rejection available on an existing planned row', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <TransactionForm
          accounts={[account]}
          onSubmit={vi.fn()}
          pending={false}
          submitError={null}
          transaction={
            {
              id: 'tx-1',
              accountId: 'account-1',
              state: 'PENDING',
              autoBook: true,
              splits: [],
              source: 'MANUAL',
              bookedOn: '2026-11-20',
              valueOn: '2026-11-20',
              amount: { value: '-29.99', assetCode: 'EUR' },
              assetCode: 'EUR',
              label: 'Internet',
              nature: 'EXPENSE',
              version: 1,
            } as never
          }
        />
      </QueryClientProvider>,
    );
    const reject = within(stateSelect()).getByRole('option', {
      name: 'Rejetée',
    }) as HTMLOptionElement;
    expect(stateSelect().disabled).toBe(false);
    expect(reject.disabled).toBe(false);
  });

  it('restores the user choice when the date returns to today', () => {
    renderForm();
    fireEvent.change(stateSelect(), { target: { value: 'PENDING' } });
    setDate('2026-11-20');
    setDate('2026-11-10');

    expect(screen.queryByText(HINT)).toBeNull();
    expect(stateSelect().disabled).toBe(false);
    expect(stateSelect().value).toBe('PENDING');
  });

  it('submits a pending row without any autoBook field', () => {
    const onSubmit = renderForm();
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '29.99' } });
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Internet' } });
    setDate('2026-11-30');
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    expect(onSubmit).toHaveBeenCalledTimes(1);
    const body = onSubmit.mock.calls[0]![0];
    expect(body).toMatchObject({ bookedOn: '2026-11-30', state: 'PENDING' });
    expect(body).not.toHaveProperty('autoBook');
  });

  it('refuses a date after the end of the month with the bound spelled out', () => {
    const onSubmit = renderForm();
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '29.99' } });
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Internet' } });
    setDate('2026-12-01');
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    expect(onSubmit).not.toHaveBeenCalled();
    expect(
      screen.getByText(/ou la fin du mois en cours pour une transaction en attente/),
    ).toBeTruthy();
    expect(screen.queryByText(HINT)).toBeNull();
  });

  it('defaults the payment method to card, and to transfer for an income from the budget', () => {
    renderForm();
    expect((screen.getByLabelText('Moyen de paiement') as HTMLSelectElement).value).toBe('CARD');
    cleanup();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <TransactionForm
          accounts={[account]}
          defaults={{
            bookedOn: '2026-11-10',
            category: { color: null, icon: null, id: 'cat-1', label: 'Salaire', type: 'INCOME' },
            nature: 'INCOME',
          }}
          onSubmit={vi.fn()}
          pending={false}
          submitError={null}
        />
      </QueryClientProvider>,
    );
    expect((screen.getByLabelText('Moyen de paiement') as HTMLSelectElement).value).toBe(
      'TRANSFER',
    );
  });
});

describe('TransactionForm insufficient balance', () => {
  const guarded = {
    ...account,
    kind: 'CURRENT',
    valuationMode: 'TRANSACTIONS',
    valuation: { amount: { value: '100.00', assetCode: 'EUR' } },
  } as unknown as Account;

  function renderGuarded(props: Partial<Parameters<typeof TransactionForm>[0]> = {}) {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <TransactionForm
          accounts={[guarded]}
          onSubmit={vi.fn()}
          pending={false}
          submitError={null}
          {...props}
        />
      </QueryClientProvider>,
    );
  }

  it('flags the amount as soon as it exceeds the known balance, with exact decimals', () => {
    renderGuarded();
    const amount = screen.getByLabelText('Montant');
    fireEvent.change(amount, { target: { value: '-100.00' } });
    expect(amount.getAttribute('aria-invalid')).toBeNull();

    fireEvent.change(amount, { target: { value: '-100.000000000000000000000001' } });
    expect(amount.getAttribute('aria-invalid')).toBe('true');
    expect(document.body.textContent).toContain('ce montant mettrait le compte en négatif le');
  });

  it('does not break while a negative amount is being typed', () => {
    renderGuarded();
    const amount = screen.getByLabelText('Montant');
    fireEvent.change(amount, { target: { value: '-' } });
    fireEvent.change(amount, { target: { value: '-1.' } });
    expect(screen.getByLabelText('Montant')).toBe(amount);
  });

  it('leaves a future date to the server', () => {
    renderGuarded();
    setDate('2026-11-12');
    const amount = screen.getByLabelText('Montant');
    fireEvent.change(amount, { target: { value: '-500.00' } });
    expect(amount.getAttribute('aria-invalid')).toBeNull();
  });

  it('shows the server refusal on the amount input and focuses it', () => {
    renderGuarded({ submitError: 'insufficientBalance', submitErrorDay: '2026-11-05' });
    const amount = screen.getByLabelText('Montant');
    expect(amount.getAttribute('aria-invalid')).toBe('true');
    expect(document.activeElement).toBe(amount);
    const ids = amount.getAttribute('aria-describedby')?.split(' ') ?? [];
    expect(ids.map((id) => document.getElementById(id)?.textContent).join(' ')).toContain(
      'le 5 novembre 2026',
    );
    expect(screen.queryByRole('alert')).toBeNull();
  });
});
