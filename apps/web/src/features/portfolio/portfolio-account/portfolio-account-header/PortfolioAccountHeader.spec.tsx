import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AppShell } from '@/components/layout/app-shell/AppShell';
import { PortfolioAccountHeader } from './PortfolioAccountHeader';

const account = {
  id: '00000000-0000-7000-8000-0000000000e1',
  label: 'PEA',
  kind: 'PORTFOLIO',
  valuationMode: 'PORTFOLIO',
  status: 'ACTIVE',
  editable: true,
  version: 1,
  valuation: { snapshotId: null, reconciliationStatus: null },
} as unknown as Account;

function stubPhone(phone: boolean) {
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: phone && query === '(max-width: 767px)',
      media: query,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    })),
  );
}

function renderHeader() {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <AppShell path="/portfolios/e1" setPath={vi.fn()}>
        <PortfolioAccountHeader
          account={account}
          accountId={account.id}
          onCreate={vi.fn()}
          onRecordValuation={vi.fn()}
          tab="account"
        />
      </AppShell>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('PortfolioAccountHeader on the Compte tab', () => {
  it('has a single three-dot menu on a phone, with the instruments inside', () => {
    stubPhone(true);
    renderHeader();

    expect(screen.getByRole('button', { name: 'Nouvelle opération' }).textContent).toBe('');
    expect(screen.queryByRole('button', { name: 'Autres actions de la page' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Gérer les instruments' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /^Actions du compte/ }));
    expect(screen.getByRole('button', { name: /Gérer les instruments/ })).toBeTruthy();
  });

  it('keeps the instruments link beside the menu on desktop', () => {
    stubPhone(false);
    renderHeader();
    expect(screen.getByRole('link', { name: 'Gérer les instruments' })).toBeTruthy();
  });
});
