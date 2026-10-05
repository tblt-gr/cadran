import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { PortfolioTabs } from './PortfolioTabs';
afterEach(cleanup);
describe('PortfolioTabs', () => {
  it('preserves securities labels and allocation without a contract tab', () => {
    render(<PortfolioTabs accountId="a" active="positions" kind="PORTFOLIO" />);
    expect(screen.getByRole('link', { name: 'Positions' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Espèces' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Répartition' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Contrat' })).toBeNull();
  });
  it('uses insurance labels and appends the contract tab, preserving the date', () => {
    render(
      <PortfolioTabs accountId="a" active="contract" kind="INSURANCE_CONTRACT" asOf="2026-04-01" />,
    );
    expect(screen.getByRole('link', { name: 'Supports' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Liquidités' })).toBeTruthy();
    const contract = screen.getByRole('link', { name: 'Contrat' });
    expect(contract.getAttribute('href')).toBe('/portfolios/a?tab=contract&asOf=2026-04-01');
    expect(contract.getAttribute('aria-current')).toBe('page');
    expect(screen.getAllByRole('link').at(-1)).toBe(contract);
  });
});
