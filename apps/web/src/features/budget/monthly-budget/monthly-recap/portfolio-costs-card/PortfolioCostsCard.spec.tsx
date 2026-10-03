import type { MonthlyPortfolioCosts } from '@cadran/api-client';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { PortfolioCostsCard } from './PortfolioCostsCard';

const eur = (value: string) => ({ value, assetCode: 'EUR' });
const costs = (overrides: Partial<MonthlyPortfolioCosts> = {}): MonthlyPortfolioCosts => ({
  items: [
    {
      accountId: 'a-1',
      label: 'Assurance vie',
      assetCode: 'EUR',
      fees: eur('1.99'),
      taxes: eur('0'),
    },
    { accountId: 'a-2', label: 'PEA', assetCode: 'EUR', fees: eur('6.99'), taxes: eur('4.95') },
  ],
  totalFees: eur('8.98'),
  totalTaxes: eur('4.95'),
  assetCode: 'EUR',
  ...overrides,
});

afterEach(cleanup);

describe('PortfolioCostsCard', () => {
  it('lists fees and taxes per account, outside the budget, with a total row', () => {
    render(<PortfolioCostsCard costs={costs()} />);

    expect(screen.getByRole('heading', { name: 'Coûts de portefeuille' })).toBeTruthy();
    expect(
      screen.getByText('Hors budget, déjà inclus dans la valeur des portefeuilles'),
    ).toBeTruthy();
    const rows = within(screen.getByRole('table')).getAllByRole('row');
    expect(rows).toHaveLength(4);
    expect(rows[2]?.textContent).toMatch(/PEA.*6,99.*4,95/);
    expect(rows[3]?.textContent).toMatch(/Total.*8,98.*4,95/);
    expect(screen.getByRole('link', { name: 'PEA' }).getAttribute('href')).toBe(
      '/portfolios/a-2?tab=cash',
    );
  });

  it('says so when every figure is zero', () => {
    render(
      <PortfolioCostsCard
        costs={costs({
          items: [
            {
              accountId: 'a-1',
              label: 'PEA',
              assetCode: 'EUR',
              fees: eur('0'),
              taxes: eur('0.00'),
            },
          ],
          totalFees: eur('0'),
          totalTaxes: eur('0.00'),
        })}
      />,
    );
    expect(screen.getByText('Aucun frais ni taxe ce mois-ci')).toBeTruthy();
    expect(screen.queryByRole('table')).toBeNull();
  });

  it('keeps each row in its own asset and shows no total when assets are mixed', () => {
    render(
      <PortfolioCostsCard
        costs={costs({
          items: [
            {
              accountId: 'a-1',
              label: 'Compte USD',
              assetCode: 'USD',
              fees: { value: '2.50', assetCode: 'USD' },
              taxes: { value: '0', assetCode: 'USD' },
            },
            {
              accountId: 'a-2',
              label: 'PEA',
              assetCode: 'EUR',
              fees: eur('1.99'),
              taxes: eur('0'),
            },
          ],
          totalFees: null,
          totalTaxes: null,
          assetCode: null,
        })}
      />,
    );
    const rows = within(screen.getByRole('table')).getAllByRole('row');
    expect(rows[1]?.textContent).toMatch(/Compte USD.*2,50.*\$/);
    expect(rows[2]?.textContent).toMatch(/PEA.*1,99.*€/);
    const total = rows[3] as HTMLElement;
    expect(total.textContent).toMatch(/Total/);
    expect(total.textContent).not.toMatch(/\d/);
    expect(within(total).getAllByText('-')).toHaveLength(2);
  });

  it('says so when no portfolio account had an operation', () => {
    render(
      <PortfolioCostsCard
        costs={costs({ items: [], totalFees: eur('0'), totalTaxes: eur('0') })}
      />,
    );
    expect(screen.getByText('Aucun frais ni taxe ce mois-ci')).toBeTruthy();
  });
});
