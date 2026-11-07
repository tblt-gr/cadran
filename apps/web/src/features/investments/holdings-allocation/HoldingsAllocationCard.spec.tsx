import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { allTotalHoldings, holdingsFixture } from '@/features/investments/holdingsFixture';
import { HoldingsAllocationCard } from './HoldingsAllocationCard';

afterEach(cleanup);

describe('HoldingsAllocationCard', () => {
  it('lists the asset classes in a captioned legend table and switches criterion', () => {
    render(<HoldingsAllocationCard holdings={holdingsFixture()} />);
    const table = screen.getByRole('table', { name: 'Répartition des titres' });
    expect(
      within(table)
        .getAllByRole('columnheader')
        .map((th) => th.textContent),
    ).toEqual(['Libellé', 'Montant', 'Part']);
    expect(within(table).getByText('Actions')).toBeTruthy();
    expect(within(table).getByText('100,00 %')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Région' }));
    expect(within(table).getByText('Monde')).toBeTruthy();
    expect(within(table).getByText('Non renseignée')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Actif' }));
    expect(within(table).getByText('MSCI World')).toBeTruthy();
    expect(within(table).getByText('60,16 %')).toBeTruthy();
  });

  it('offers a fourth criterion by account', () => {
    render(<HoldingsAllocationCard holdings={holdingsFixture()} />);
    expect(
      screen.getByRole('group', { name: 'Critère de répartition' }).querySelectorAll('button'),
    ).toHaveLength(4);
    fireEvent.click(screen.getByRole('button', { name: 'Compte' }));
    const table = screen.getByRole('table', { name: 'Répartition des titres' });
    expect(within(table).getByText('CTO')).toBeTruthy();
    expect(within(table).getByText('PEA')).toBeTruthy();
    expect(within(table).getByText('39,84 %')).toBeTruthy();
  });

  it('defaults to the account criterion when there is no line, and lists the unsplit slice', () => {
    render(<HoldingsAllocationCard holdings={allTotalHoldings()} />);
    expect(screen.getByRole('button', { name: 'Compte' }).getAttribute('aria-pressed')).toBe(
      'true',
    );
    const table = screen.getByRole('table', { name: 'Répartition des titres' });
    expect(within(table).getByText('Assurance vie')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Actif' }));
    expect(within(table).getByText('Comptes valorisés en total')).toBeTruthy();
    expect(within(table).getByText(/800,00/)).toBeTruthy();
  });

  it('starts by account when the page shows the account view', () => {
    render(<HoldingsAllocationCard holdings={holdingsFixture()} view="account" />);
    expect(screen.getByRole('button', { name: 'Compte' }).getAttribute('aria-pressed')).toBe(
      'true',
    );
  });

  it('keeps the asset class default when lines are held', () => {
    render(<HoldingsAllocationCard holdings={holdingsFixture()} />);
    expect(
      screen.getByRole('button', { name: "Classe d'actifs" }).getAttribute('aria-pressed'),
    ).toBe('true');
  });

  it('shows a text instead of the donut when the shares are not calculable', () => {
    const holdings = holdingsFixture();
    render(
      <HoldingsAllocationCard
        holdings={{
          ...holdings,
          allocation: {
            ...holdings.allocation,
            byAssetClass: [{ key: 'EQUITY', label: 'EQUITY', value: '2510.00', share: null }],
          },
        }}
      />,
    );
    expect(screen.getByText('Répartition non calculable')).toBeTruthy();
    expect(screen.getByRole('table', { name: 'Répartition des titres' })).toBeTruthy();
  });

  it('keeps the title and the criteria real while loading', () => {
    const { container } = render(<HoldingsAllocationCard holdings={null} />);
    expect(screen.getByRole('heading', { name: 'Répartition' })).toBeTruthy();
    expect(screen.getByRole('button', { name: "Classe d'actifs" })).toBeTruthy();
    expect(container.querySelectorAll('[data-skeleton]').length).toBeGreaterThan(0);
  });
});
