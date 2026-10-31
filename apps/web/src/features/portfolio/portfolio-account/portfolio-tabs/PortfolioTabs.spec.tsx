import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { activeTab, tabForKind } from './activeTab';
import { PortfolioTabs } from './PortfolioTabs';
afterEach(cleanup);
describe('PortfolioTabs', () => {
  it('orders shared account tabs before investment views, without a contract tab', () => {
    render(<PortfolioTabs accountId="a" active="positions" kind="PORTFOLIO" />);
    expect(screen.getAllByRole('link').map((link) => link.textContent)).toEqual([
      'Compte',
      'Opérations',
      'Valorisations',
      'Positions',
      'Performance',
    ]);
    expect(screen.getByRole('link', { name: 'Compte' }).getAttribute('href')).toBe(
      '/portfolios/a?tab=account',
    );
    expect(screen.queryByRole('link', { name: 'Répartition' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Contrat' })).toBeNull();
  });
  it('uses insurance labels and appends the contract tab, preserving the date', () => {
    render(
      <PortfolioTabs accountId="a" active="contract" kind="INSURANCE_CONTRACT" asOf="2026-04-01" />,
    );
    expect(screen.getByRole('link', { name: 'Supports' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Liquidités' })).toBeNull();
    const contract = screen.getByRole('link', { name: 'Contrat' });
    expect(contract.getAttribute('href')).toBe('/portfolios/a?tab=contract&asOf=2026-04-01');
    expect(contract.getAttribute('aria-current')).toBe('page');
    expect(screen.getAllByRole('link').at(-1)).toBe(contract);
    expect(screen.getAllByRole('link')[0].textContent).toBe('Compte');
  });
  it('opens Compte by default and keeps former allocation links', () => {
    window.history.replaceState({}, '', '/portfolios/a');
    expect(activeTab()).toBe('account');
    window.history.replaceState({}, '', '/portfolios/a?tab=cash');
    expect(activeTab()).toBe('operations');
    window.history.replaceState({}, '', '/portfolios/a?tab=allocation');
    expect(activeTab()).toBe('positions');
    window.history.replaceState({}, '', '/portfolios/a?tab=performance');
    expect(activeTab()).toBe('performance');
  });
  it('reads the account and contract tabs as one per kind of account', () => {
    window.history.replaceState({}, '', '/portfolios/a?tab=account');
    expect(activeTab()).toBe('account');
    expect(tabForKind('contract', 'PORTFOLIO')).toBe('account');
    expect(tabForKind('account', 'INSURANCE_CONTRACT')).toBe('account');
    expect(tabForKind('cash', 'INSURANCE_CONTRACT')).toBe('cash');
  });
});
