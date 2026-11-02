import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
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
    expect(stateSelect().disabled).toBe(true);
    expect(stateSelect().value).toBe('PENDING');
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
});
