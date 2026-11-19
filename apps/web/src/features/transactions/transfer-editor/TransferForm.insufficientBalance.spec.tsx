import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransferForm } from './TransferForm';

vi.mock('@/features/auth/useWorkspaceTimeZone', () => ({ useWorkspaceTimeZone: () => 'UTC' }));

const source = {
  id: 'source',
  label: 'Courant',
  assetCode: 'EUR',
  status: 'ACTIVE',
  kind: 'CURRENT',
  valuationMode: 'TRANSACTIONS',
  valuation: { amount: { value: '100.00', assetCode: 'EUR' } },
} as unknown as Account;
const target = { ...source, id: 'target', label: 'Livret', kind: 'SAVINGS' } as Account;

function renderForm(props: Partial<Parameters<typeof TransferForm>[0]> = {}) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <TransferForm
        accounts={[source, target]}
        defaults={{ lockedSourceAccountId: 'source' }}
        onSubmit={vi.fn()}
        pending={false}
        submitError={null}
        {...props}
      />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-11-10T10:00:00Z'));
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

function describedText(input: HTMLElement): string {
  const ids = input.getAttribute('aria-describedby')?.split(' ') ?? [];

  return ids.map((id) => document.getElementById(id)?.textContent ?? '').join(' ');
}

function sourceAmount() {
  return screen.getByLabelText(/^Montant \(EUR\)/);
}

it('replaces the balance after by an error as soon as the amount exceeds the source balance', () => {
  renderForm();
  fireEvent.change(screen.getByLabelText('Compte de destination'), { target: { value: 'target' } });
  fireEvent.change(sourceAmount(), { target: { value: '100.00' } });
  expect(sourceAmount().getAttribute('aria-invalid')).toBeNull();
  expect(screen.getAllByText(/Solde après opération/).length).toBeGreaterThan(0);

  fireEvent.change(sourceAmount(), { target: { value: '100.01' } });
  expect(sourceAmount().getAttribute('aria-invalid')).toBe('true');
  expect(describedText(sourceAmount())).toBe(
    'Solde insuffisant : ce montant mettrait le compte en négatif le 10 novembre 2026.',
  );
});

it('shows the server refusal on the source amount input and focuses it', () => {
  renderForm({ submitError: 'insufficientBalance', submitErrorDay: '2026-11-05' });
  expect(sourceAmount().getAttribute('aria-invalid')).toBe('true');
  expect(document.activeElement).toBe(sourceAmount());
  expect(describedText(sourceAmount())).toContain('le 5 novembre 2026');
  expect(screen.queryByRole('alert')).toBeNull();
});
