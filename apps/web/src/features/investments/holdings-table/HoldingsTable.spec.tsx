import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { holdingsFixture } from '@/features/investments/holdingsFixture';
import { expectExplanation } from '@/features/investments/explain-info/explanationDialog';
import { HoldingsTable } from './HoldingsTable';

afterEach(cleanup);

function rowOf(name: string) {
  return screen.getByText(name).closest('tr')!;
}

describe('HoldingsTable', () => {
  it('shows the aggregated line of an instrument held on two accounts', () => {
    render(<HoldingsTable holdings={holdingsFixture()} onSortChange={vi.fn()} sort="VALUE_DESC" />);
    const table = screen.getByRole('table', { name: 'Positions par actif' });
    expect(
      within(table)
        .getAllByRole('columnheader')
        .map((th) => th.textContent),
    ).toEqual([
      'Actif',
      'Quantité',
      'PRU',
      'Dernier cours',
      'Valeur',
      'Plus-value latente',
      'Part',
      'Détail',
    ]);
    const row = rowOf('MSCI World');
    expect(within(row).getByText('IE00B4L5Y983 · ETF')).toBeTruthy();
    expect(within(row).getByText('15')).toBeTruthy();
    expect(within(row).getByText(/92,0000/)).toBeTruthy();
    expect(within(row).getByText(/1\s510,00/)).toBeTruthy();
    expect(within(row).getByText(/\+130,00/)).toBeTruthy();
    expect(within(row).getByText('(+9,42 %)')).toBeTruthy();
    expect(within(row).getByText('60,16 %')).toBeTruthy();
    expect(within(row).getByText('3 novembre 2026')).toBeTruthy();
  });

  it('falls back to the ticker when there is no ISIN', () => {
    render(<HoldingsTable holdings={holdingsFixture()} onSortChange={vi.fn()} sort="VALUE_DESC" />);
    expect(within(rowOf('Action A')).getByText('A · Action')).toBeTruthy();
  });

  it('discloses one sub-row per account with a link to the account', () => {
    render(<HoldingsTable holdings={holdingsFixture()} onSortChange={vi.fn()} sort="VALUE_DESC" />);
    const [toggle] = screen.getAllByRole('button', { name: /Détail par compte/ });
    expect(toggle!.getAttribute('aria-expanded')).toBe('false');
    expect(screen.queryByRole('link', { name: 'PEA' })).toBeNull();
    fireEvent.click(toggle!);
    expect(toggle!.getAttribute('aria-expanded')).toBe('true');
    expect(screen.getByRole('link', { name: 'PEA' }).getAttribute('href')).toMatch(
      /^\/portfolios\/.+\?tab=positions$/,
    );
    expect(screen.getByRole('link', { name: 'CTO' })).toBeTruthy();
    expect(screen.getByText('Valorisé le 31 octobre 2026')).toBeTruthy();
    fireEvent.click(toggle!);
    expect(screen.queryByRole('link', { name: 'PEA' })).toBeNull();
  });

  it('explains an unknown cost and a missing price in a modal, never as zero', async () => {
    const base = holdingsFixture();
    const [first, second] = base.items;
    render(
      <HoldingsTable
        holdings={{
          ...base,
          items: [
            first!,
            {
              ...second!,
              value: null,
              reason: 'MISSING_PRICE',
              lastPrice: null,
              share: { ratio: null, percentDisplay: null, reason: 'MISSING_VALUE' },
            },
          ],
        }}
        onSortChange={vi.fn()}
        sort="VALUE_DESC"
      />,
    );
    expect(screen.queryByText('Valeur non calculable : cours manquant')).toBeNull();
    await expectExplanation(
      within(rowOf('Action A')).getByRole('button', {
        name: 'Expliquer : Valeur (Action A) non calculable',
      }),
      'Valeur (Action A)',
      'Valeur non calculable : cours manquant',
    );
    const unknown = holdingsFixture();
    cleanup();
    render(<HoldingsTable holdings={unknown} onSortChange={vi.fn()} sort="VALUE_DESC" />);
    expect(screen.queryByText(/Plus-value non calculable/)).toBeNull();
    await expectExplanation(
      within(rowOf('Action A')).getByRole('button', {
        name: 'Expliquer : Plus-value latente (Action A) non calculable',
      }),
      'Plus-value latente (Action A)',
      "Plus-value non calculable : coût d'origine inconnu",
    );
  });

  it('tells mixed units in a modal and keeps null sub-row amounts safe', async () => {
    const base = holdingsFixture();
    const [first] = base.items;
    render(
      <HoldingsTable
        holdings={{
          ...base,
          assetCode: null,
          items: [
            {
              ...first!,
              value: null,
              costBasis: null,
              unrealizedGain: null,
              performance: null,
              averageUnitCost: null,
              reason: 'MIXED_ASSETS',
              share: { ratio: null, percentDisplay: null, reason: 'MIXED_ASSETS' },
              accounts: first!.accounts.map((account) => ({
                ...account,
                value: null,
                costBasis: null,
                unrealizedGain: null,
                performance: null,
              })),
            },
          ],
        }}
        onSortChange={vi.fn()}
        sort="VALUE_DESC"
      />,
    );
    await expectExplanation(
      within(rowOf('MSCI World')).getByRole('button', {
        name: 'Expliquer : Valeur (MSCI World) non calculable',
      }),
      'Valeur (MSCI World)',
      'Totaux non calculés : les comptes utilisent plusieurs unités.',
    );
    fireEvent.click(screen.getByRole('button', { name: /Détail par compte/ }));
    expect(screen.getByRole('link', { name: 'PEA' })).toBeTruthy();
    expect(
      screen.getByRole('button', { name: 'Expliquer : Valeur (MSCI World, PEA) non calculable' }),
    ).toBeTruthy();
    expect(screen.queryByText(/NaN|undefined/)).toBeNull();
  });

  it('keeps every numeric cell and the chevron borderless on one line', () => {
    const { container } = render(
      <HoldingsTable holdings={holdingsFixture()} onSortChange={vi.fn()} sort="VALUE_DESC" />,
    );
    const cells = [...container.querySelectorAll('tbody tr:first-child td[data-label]')];
    expect(cells.length).toBe(6);
    // Quantity, prices, value, gain and share carry the nowrap numeric class.
    for (const cell of cells) expect(cell.className).toMatch(/number/);
    const chevron = screen.getAllByRole('button', { name: /Détail par compte/ })[0]!;
    expect(chevron.className).toMatch(/icon-ghost/);
    expect(chevron.className).not.toMatch(/icon-button/);
  });

  it('labels every cell for the stacked mobile layout', () => {
    const { container } = render(
      <HoldingsTable holdings={holdingsFixture()} onSortChange={vi.fn()} sort="VALUE_DESC" />,
    );
    const labels = [...container.querySelectorAll('tbody tr:first-child td')].map((td) =>
      td.getAttribute('data-label'),
    );
    expect(labels).toEqual([
      'Quantité',
      'PRU',
      'Dernier cours',
      'Valeur',
      'Plus-value latente',
      'Part',
      null,
    ]);
  });

  it('asks the server for another order from the sortable headers and marks the current one', () => {
    const onSortChange = vi.fn();
    render(
      <HoldingsTable holdings={holdingsFixture()} onSortChange={onSortChange} sort="VALUE_DESC" />,
    );
    expect(screen.getByRole('columnheader', { name: /Valeur/ }).getAttribute('aria-sort')).toBe(
      'descending',
    );
    fireEvent.click(
      within(screen.getByRole('columnheader', { name: /Plus-value latente/ })).getByRole('button'),
    );
    expect(onSortChange).toHaveBeenCalledWith('GAIN_DESC');
    fireEvent.click(
      within(screen.getByRole('columnheader', { name: /Actif/ })).getByRole('button'),
    );
    expect(onSortChange).toHaveBeenCalledWith('NAME_ASC');
  });

  it('keeps the caption and headers and blocks only the values while loading', () => {
    const { container } = render(
      <HoldingsTable holdings={null} onSortChange={vi.fn()} sort="VALUE_DESC" />,
    );
    expect(screen.getByRole('table', { name: 'Positions par actif' })).toBeTruthy();
    expect(screen.getByRole('columnheader', { name: 'Quantité' })).toBeTruthy();
    expect(container.querySelectorAll('tbody [data-skeleton]').length).toBeGreaterThan(0);
  });
});
