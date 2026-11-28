import { act, render, screen, fireEvent, cleanup } from '@testing-library/react';
import { afterEach, describe, it, expect, vi } from 'vitest';
import i18n from '@/i18n';
import english from '@/locales/en/translation.json';
import { AllocationCard } from './AllocationCard';
import { allocationFixture } from '@/features/portfolio/portfolio-account/overviewFixtures';
afterEach(cleanup);
i18n.addResourceBundle('en', 'translation', english);
describe('AllocationCard', () => {
  it('dates the complete observation actually used, rather than the selected day', () => {
    const allocation = allocationFixture();
    allocation.asOf = '2026-10-03';
    allocation.valuation = { ...allocation.valuation!, valuedOn: '2026-09-30' };
    allocation.newerPartialValuation = {
      ...allocation.valuation,
      valuedOn: '2026-10-03',
      completeness: 'PARTIAL',
      totalValue: null,
    };
    render(<AllocationCard allocation={allocation} by="INSTRUMENT" onByChange={vi.fn()} />);
    expect(screen.getByText('30/09/2026', { selector: 'time' }).getAttribute('dateTime')).toBe(
      '2026-09-30',
    );
    expect(screen.queryByText('03/10/2026', { selector: 'time' })).toBeNull();
    expect(
      screen.getByText('Valorisation du 03/10/2026 partielle : valeurs du 30/09/2026 affichées.'),
    ).toBeTruthy();
  });

  it.each([
    {
      total: '99',
      securities: '100',
      cash: '-1',
      securitiesShare: '1.010101010101010101010101',
      cashShare: '-0.010101010101010101010101',
      percentage: /-1,01 %/,
      amount: /-1,00/,
    },
    {
      total: '0.01',
      securities: '10000',
      cash: '-9999.99',
      securitiesShare: '1000000',
      cashShare: '-999999',
      percentage: /-99\s999\s900,00 %/,
      amount: /-9\s999,99/,
    },
  ])(
    'keeps signed values in the table and leaves the negative cash out of the donut: $cash against total $total',
    (values) => {
      const allocation = allocationFixture();
      allocation.total = values.total;
      allocation.items = [
        {
          key: 'SECURITIES',
          label: 'SECURITIES',
          value: values.securities,
          share: values.securitiesShare,
        },
        { key: 'CASH', label: 'CASH', value: values.cash, share: values.cashShare },
      ];
      allocation.slices = allocation.items;
      render(<AllocationCard allocation={allocation} by="INSTRUMENT" onByChange={vi.fn()} />);
      expect(screen.getByRole('status').textContent).toContain(
        '1 part négative ignorée dans le graphique',
      );
      expect(screen.getByRole('rowheader', { name: 'Espèces' })).toBeTruthy();
      expect(
        screen.getByRole('table', { name: 'Détail de la répartition' }).closest('details'),
      ).toBeNull();
      expect(screen.getByText(values.percentage)).toBeTruthy();
      expect(screen.getByText(values.amount)).toBeTruthy();
    },
  );
  it('checks unmerged table shares even when the chart slices hide a negative share', () => {
    const allocation = allocationFixture();
    allocation.items = [
      ...allocation.items,
      { key: 'CASH_DEBT', label: 'Espèces débitrices', value: '-1', share: '-0.01' },
    ];
    render(<AllocationCard allocation={allocation} by="INSTRUMENT" onByChange={vi.fn()} />);
    expect(screen.getByRole('status')).toBeTruthy();
  });

  it('uses server shares and retains a complete table alternative and grouping controls', () => {
    const change = vi.fn();
    render(<AllocationCard allocation={allocationFixture()} by="INSTRUMENT" onByChange={change} />);
    const table = screen.getByRole('table', { name: 'Détail de la répartition' });
    expect(table.parentElement?.getAttribute('role')).toBe('region');
    expect(table.parentElement?.getAttribute('aria-label')).toBe('Détail de la répartition');
    expect(table.parentElement?.tabIndex).toBe(0);
    expect(screen.getByRole('rowheader', { name: 'Non renseignée' })).toBeTruthy();
    expect(screen.getAllByText('75,00 %').length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole('button', { name: 'Région' }));
    expect(change).toHaveBeenCalledWith('REGION');
  });
  it.each(['NO_VALUATION', 'PARTIAL_VALUATION', 'NOT_DETAILED', 'NON_POSITIVE_TOTAL'] as const)(
    'explains %s without a chart',
    (reason) => {
      render(
        <AllocationCard
          allocation={{ ...allocationFixture(), reason, basis: null, slices: [] }}
          by="INSTRUMENT"
          onByChange={vi.fn()}
        />,
      );
      expect(screen.getByRole('status')).toBeTruthy();
      expect(screen.queryByRole('img', { name: 'Répartition du portefeuille' })).toBeNull();
    },
  );
  it('names classes and regions as on the instruments, keeping currency codes', () => {
    const allocation = {
      ...allocationFixture(),
      by: 'REGION' as const,
      items: [
        { key: 'WORLD', label: 'WORLD', value: '75', share: '0.75' },
        { key: 'CASH', label: 'CASH', value: '25', share: '0.25' },
      ],
    };
    render(<AllocationCard allocation={allocation} by="REGION" onByChange={vi.fn()} />);
    expect(screen.getByRole('rowheader', { name: 'Monde' })).toBeTruthy();
    expect(screen.getByRole('rowheader', { name: 'Espèces' })).toBeTruthy();
  });
  it.each([
    ['fr', ['Titres', 'Espèces', 'Non renseignée', 'Autres']],
    ['en', ['Securities', 'Cash', 'Not specified', 'Other']],
  ])('translates the server buckets by key in %s', async (language, names) => {
    const items = ['SECURITIES', 'CASH', 'UNSPECIFIED', 'OTHERS'].map((key) => ({
      key,
      label: key,
      value: '25',
      share: '0.25',
    }));
    await act(() => i18n.changeLanguage(language));
    try {
      render(
        <AllocationCard
          allocation={{ ...allocationFixture(), items, slices: items }}
          by="INSTRUMENT"
          onByChange={vi.fn()}
        />,
      );
      for (const name of names) {
        expect(screen.getByRole('rowheader', { name })).toBeTruthy();
      }
    } finally {
      await act(() => i18n.changeLanguage('fr'));
    }
  });

  it('splits a total-only valuation at cost and says so', () => {
    render(
      <AllocationCard
        allocation={{ ...allocationFixture(), reason: 'NOT_DETAILED', basis: 'COST' }}
        by="REGION"
        onByChange={vi.fn()}
      />,
    );
    expect(screen.getByText('· au prix de revient')).toBeTruthy();
    expect(screen.getByText(/la répartition suit le prix de revient/)).toBeTruthy();
    expect(screen.queryByRole('status')).toBeNull();
  });
});
