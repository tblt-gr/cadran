import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountActionsMenu } from './AccountActionsMenu';

const balanceAccount = {
  id: 'courant',
  label: 'Compte courant',
  kind: 'CURRENT',
  status: 'ACTIVE',
  editable: true,
  valuationMode: 'BALANCE',
  assetCode: 'EUR',
  version: 1,
  valuation: { snapshotId: 'snap', reconciliationStatus: 'UNRECONCILED' },
} as unknown as Account;

const portfolioAccount = {
  ...balanceAccount,
  id: 'pea',
  label: 'PEA',
  kind: 'PORTFOLIO',
  valuationMode: 'PORTFOLIO',
  valuation: { snapshotId: null, reconciliationStatus: null },
} as unknown as Account;

function setup(account: Account | undefined) {
  const handlers = {
    onAddTransfer: vi.fn(),
    onReconcile: vi.fn(),
    onRecordValuation: vi.fn(),
    onRules: vi.fn(),
  };
  render(
    <QueryClientProvider client={new QueryClient()}>
      <AccountActionsMenu account={account} {...handlers} />
    </QueryClientProvider>,
  );
  return handlers;
}

function openMenu(name: string | RegExp = /Actions du compte/) {
  fireEvent.click(screen.getByRole('button', { name }));
}

function itemTexts() {
  return Array.from(document.body.querySelectorAll('button[aria-label] > span')).map(
    (node) => node.textContent,
  );
}

afterEach(cleanup);

describe('AccountActionsMenu', () => {
  it('lists the balance account actions in order, without the primary operation', () => {
    setup(balanceAccount);
    openMenu('Actions du compte Compte courant');

    expect(itemTexts()).toEqual([
      'Nouveau virement',
      'Enregistrer un solde',
      'Rapprocher',
      'Voir les règles',
      'Modifier',
      'Archiver',
    ]);
  });

  it('lists the portfolio account actions and omits the balance-only ones', () => {
    setup(portfolioAccount);
    openMenu('Actions du compte PEA');

    expect(itemTexts()).toEqual([
      'Nouvelle valorisation',
      'Voir les règles',
      'Gérer les instruments',
      'Modifier',
      'Archiver',
    ]);
  });

  it('keeps Rapprocher visible but disabled with its reason when nothing is to reconcile', () => {
    setup({
      ...balanceAccount,
      valuation: { snapshotId: 'snap', reconciliationStatus: 'RECONCILED' },
    } as unknown as Account);
    openMenu();

    const item = screen.getByRole('button', { name: /^Rapprocher/ }) as HTMLButtonElement;
    expect(item.disabled).toBe(true);
    expect(item.getAttribute('aria-label')).toMatch(/rien à rapprocher/i);
  });

  it('disables every writing action of an archived account but keeps the rules', () => {
    const handlers = setup({
      ...balanceAccount,
      status: 'ARCHIVED',
      editable: false,
    } as unknown as Account);
    openMenu();

    for (const name of [
      /^Nouveau virement/,
      /^Enregistrer un solde/,
      /^Rapprocher/,
      /^Modifier/,
      /^Archiver/,
    ]) {
      const item = screen.getByRole('button', { name }) as HTMLButtonElement;
      expect(item.disabled).toBe(true);
      expect(item.getAttribute('aria-label')).toMatch(/archivé/i);
    }
    const rules = screen.getByRole('button', { name: 'Voir les règles' }) as HTMLButtonElement;
    expect(rules.disabled).toBe(false);
    fireEvent.click(rules);
    expect(handlers.onRules).toHaveBeenCalledTimes(1);
  });

  it('calls the matching handler and closes on selection', () => {
    const handlers = setup(balanceAccount);
    openMenu();
    fireEvent.click(screen.getByRole('button', { name: 'Nouveau virement' }));

    expect(handlers.onAddTransfer).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('button', { name: 'Nouveau virement' })).toBeNull();
  });

  it('closes on Escape and returns focus to the trigger', () => {
    setup(balanceAccount);
    openMenu();
    expect(screen.getByRole('button', { name: 'Voir les règles' })).toBeTruthy();

    fireEvent.keyDown(window, { key: 'Escape' });

    expect(screen.queryByRole('button', { name: 'Voir les règles' })).toBeNull();
    expect(document.activeElement).toBe(
      screen.getByRole('button', { name: 'Actions du compte Compte courant' }),
    );
  });

  it('opens the shared edit and archive modals', () => {
    setup(balanceAccount);
    openMenu();
    fireEvent.click(screen.getByRole('button', { name: /^Modifier/ }));
    expect(screen.getByRole('dialog')).toBeTruthy();
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Fermer' }));

    openMenu();
    fireEvent.click(screen.getByRole('button', { name: /^Archiver/ }));
    expect(screen.getByRole('dialog', { name: /Archiver/ })).toBeTruthy();
  });

  it('shows a disabled trigger while the account is unknown', () => {
    setup(undefined);

    const trigger = screen.getByRole('button', { name: 'Actions du compte' }) as HTMLButtonElement;
    expect(trigger.disabled).toBe(true);
  });
});
