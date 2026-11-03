import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import '@/i18n';
import type { Account } from '@cadran/api-client';
import { AccountDetailPageSkeleton } from './AccountDetailPageSkeleton';

afterEach(() => {
  cleanup();
  window.history.replaceState({}, '', '/');
});

function mount(account?: Pick<Account, 'kind' | 'valuationMode'>) {
  window.history.replaceState({}, '', '/accounts/a?tab=account');
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <AccountDetailPageSkeleton status="Chargement" account={account as Account | undefined} />
    </QueryClientProvider>,
  );
}

it('shows the generic balance history chrome while the account is loading', () => {
  mount();
  expect(screen.getByRole('heading', { name: 'Historique du solde' })).toBeTruthy();
  expect((screen.getByRole('button', { name: '12 mois' }) as HTMLButtonElement).disabled).toBe(
    true,
  );
});

it('mirrors the value wording and notice of a recorded-value account', () => {
  mount({ kind: 'PORTFOLIO', valuationMode: 'SNAPSHOTS' });
  expect(screen.getByRole('heading', { name: 'Historique de la valeur' })).toBeTruthy();
  expect(screen.getByText('Valeur saisie manuellement, sans détail des positions.')).toBeTruthy();
});

it('draws no history for an account valued by its portfolio', () => {
  mount({ kind: 'PORTFOLIO', valuationMode: 'PORTFOLIO' });
  expect(screen.queryByRole('heading', { name: /^Historique/ })).toBeNull();
});
