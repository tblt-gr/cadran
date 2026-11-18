import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AppShell } from '@/components/layout/app-shell/AppShell';
import { AccountDetailActions } from './AccountDetailActions';

const account = {
  id: '00000000-0000-7000-8000-0000000000d1',
  label: 'Livret A',
  kind: 'SAVINGS',
  valuationMode: 'TRANSACTIONS',
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

function renderActions(tab: 'operations' | 'account', onAddTransfer = vi.fn()) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <AppShell path="/accounts/abc" setPath={vi.fn()}>
        <AccountDetailActions
          account={account}
          creatable
          onAddTransaction={vi.fn()}
          onAddTransfer={onAddTransfer}
          onOpenRules={vi.fn()}
          onReconcile={vi.fn()}
          tab={tab}
        />
      </AppShell>
    </QueryClientProvider>,
  );
  return onAddTransfer;
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('AccountDetailActions on a phone', () => {
  it('shows the primary action as an icon and moves the transfer into a menu', () => {
    stubPhone(true);
    const onAddTransfer = renderActions('operations');

    expect(screen.getByRole('button', { name: 'Nouvelle opération' }).textContent).toBe('');
    expect(screen.queryByRole('button', { name: 'Nouveau virement' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Autres actions de la page' }));
    fireEvent.click(screen.getByRole('button', { name: 'Nouveau virement' }));
    expect(onAddTransfer).toHaveBeenCalledOnce();
  });

  it('keeps a single three-dot menu on the account tab, transfer included', () => {
    stubPhone(true);
    renderActions('account');

    expect(screen.queryByRole('button', { name: 'Autres actions de la page' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /^Actions du compte/ }));
    expect(screen.getByRole('button', { name: /Nouveau virement/ })).toBeTruthy();
  });
});
