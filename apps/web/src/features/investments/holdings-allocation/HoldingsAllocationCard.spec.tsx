import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { holdingsFixture } from '@/features/investments/holdingsFixture';
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
