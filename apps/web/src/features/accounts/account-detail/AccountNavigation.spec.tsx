import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { AccountNavigation, activeAccountTab } from './AccountNavigation';

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

describe('AccountNavigation', () => {
  it('links the account, operations and balance tabs in order and marks the active one', () => {
    render(<AccountNavigation accountId="a1" tab="operations" />);
    expect(screen.getByRole('navigation', { name: 'Sections du compte' })).toBeTruthy();
    expect(screen.getAllByRole('link').map((link) => link.textContent)).toEqual([
      'Compte',
      'Opérations',
      'Solde',
    ]);
    expect(screen.getByRole('link', { name: 'Solde' }).getAttribute('href')).toBe(
      '/accounts/a1?tab=balance',
    );
    const operations = screen.getByRole('link', { name: 'Opérations' });
    expect(operations.getAttribute('href')).toBe('/accounts/a1?tab=operations');
    expect(operations.getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('link', { name: 'Compte' }).getAttribute('aria-current')).toBeNull();
    expect(screen.getByRole('link', { name: 'Solde' }).getAttribute('aria-current')).toBeNull();
  });

  it('marks the balance tab active', () => {
    render(<AccountNavigation accountId="a1" tab="balance" />);
    expect(screen.getByRole('link', { name: 'Solde' }).getAttribute('aria-current')).toBe('page');
  });

  it('reads the tab from the location, a month meaning operations', () => {
    window.history.replaceState(null, '', '/accounts/a1?tab=balance');
    expect(activeAccountTab()).toBe('balance');
    window.history.replaceState(null, '', '/accounts/a1?month=2026-09');
    expect(activeAccountTab()).toBe('operations');
    window.history.replaceState(null, '', '/accounts/a1?tab=bogus');
    expect(activeAccountTab()).toBe('account');
  });
});
