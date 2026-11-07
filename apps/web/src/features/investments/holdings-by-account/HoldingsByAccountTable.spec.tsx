import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { holdingsFixture, totalAccountSummary } from '@/features/investments/holdingsFixture';
import { HoldingsByAccountTable } from './HoldingsByAccountTable';

afterEach(cleanup);

function rowOf(name: string) {
  return screen.getByRole('link', { name }).closest('tr')!;
}

describe('HoldingsByAccountTable', () => {
  it('lists the accounts in the server order with value, gain, share and valuation date', () => {
    render(<HoldingsByAccountTable holdings={holdingsFixture()} />);
    const table = screen.getByRole('table', { name: 'Positions par compte' });
    expect(
      within(table)
        .getAllByRole('columnheader')
        .map((th) => th.textContent),
    ).toEqual([
      'Compte',
      'Valeur des titres',
      'Plus-value latente',
      'Part',
      'Valorisé le',
      'Détail',
    ]);
    expect(within(table).queryAllByRole('button', { name: /Trier|Valeur/ })).toHaveLength(0);
    const names = within(table)
      .getAllByRole('link')
      .map((link) => link.textContent);
    expect(names).toEqual(['CTO', 'PEA']);
    const pea = rowOf('PEA');
    expect(screen.getByRole('link', { name: 'PEA' }).getAttribute('href')).toMatch(
      /^\/portfolios\/[^/?]+$/,
    );
    expect(within(pea).getByText(/1\s000,00/)).toBeTruthy();
    expect(within(pea).getByText(/\+100,00/)).toBeTruthy();
    expect(within(pea).getByText('(+11,11 %)')).toBeTruthy();
    expect(within(pea).getByText('39,84 %')).toBeTruthy();
    expect(within(pea).getByText('31 octobre 2026')).toBeTruthy();
  });

  it('discloses the lines of a detailed account with the not-computable texts', () => {
    render(<HoldingsByAccountTable holdings={holdingsFixture()} />);
    const toggle = screen.getByRole('button', { name: /Détail par actif : CTO/ });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(screen.queryByText('IE00B4L5Y983 · ETF')).toBeNull();
    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(screen.getByText('IE00B4L5Y983 · ETF')).toBeTruthy();
    expect(screen.getByText('A · Action')).toBeTruthy();
    expect(screen.getAllByText("Plus-value non calculable : coût d'origine inconnu")).toHaveLength(
      2,
    );
    expect(screen.getByText(/\+30,00/)).toBeTruthy();
    const quantity = screen.getByText('20').closest('td')!;
    expect(quantity.className).toBe(quantity.nextElementSibling!.className);
    fireEvent.click(toggle);
    expect(screen.queryByText('A · Action')).toBeNull();
  });

  it('tells a missing price and mixed units in place of the line value, never zero', () => {
    const base = holdingsFixture();
    const cto = base.accounts[0]!;
    const { rerender } = render(
      <HoldingsByAccountTable
        holdings={{
          ...base,
          accounts: [{ ...cto, lines: [{ ...cto.lines[0]!, value: null }] }],
        }}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: /Détail par actif/ }));
    expect(screen.getByText('Valeur non calculable : cours manquant')).toBeTruthy();
    rerender(
      <HoldingsByAccountTable
        holdings={{
          ...base,
          assetCode: null,
          accounts: [
            {
              ...cto,
              securitiesValue: null,
              share: { ratio: null, percentDisplay: null, reason: 'MIXED_ASSETS' },
              lines: [{ ...cto.lines[0]!, value: null }],
            },
          ],
        }}
      />,
    );
    expect(
      screen.getByText('Totaux non calculés : les comptes utilisent plusieurs unités.'),
    ).toBeTruthy();
  });

  it('names an account valued as a total without a disclosure and links the detailed entry', () => {
    const base = holdingsFixture();
    render(
      <HoldingsByAccountTable
        holdings={{
          ...base,
          accounts: [
            totalAccountSummary,
            { ...totalAccountSummary, accountId: 'p', label: 'Compte P', detail: 'PARTIAL' },
            {
              ...totalAccountSummary,
              accountId: 'n',
              label: 'Compte N',
              detail: 'NONE',
              valuedOn: null,
              securitiesValue: null,
            },
          ],
        }}
      />,
    );
    expect(screen.queryByRole('button', { name: /Détail par actif/ })).toBeNull();
    expect(screen.getByText('Valorisation en total : titres non détaillés')).toBeTruthy();
    expect(screen.getByText('Valorisation partielle : titres non détaillés')).toBeTruthy();
    expect(screen.getByText('Aucune valorisation')).toBeTruthy();
    const links = screen.getAllByRole('link', { name: /Saisir une valorisation détaillée/ });
    expect(links).toHaveLength(3);
    expect(links.map((link) => link.textContent)).toEqual([
      'Saisir une valorisation détaillée Assurance vie',
      'Saisir une valorisation détaillée Compte P',
      'Saisir une valorisation détaillée Compte N',
    ]);
    expect(links[0]!.getAttribute('href')).toBe(
      `/portfolios/${totalAccountSummary.accountId}?tab=valuations`,
    );
    const none = rowOf('Compte N');
    expect(none.className).toMatch(/greyed/);
    expect(within(none).queryByText(/^0/)).toBeNull();
    expect(within(none).getAllByText('-').length).toBeGreaterThan(0);
  });

  it('labels every cell for the stacked mobile layout', () => {
    const { container } = render(<HoldingsByAccountTable holdings={holdingsFixture()} />);
    const labels = [...container.querySelectorAll('tbody tr:first-child td')].map((td) =>
      td.getAttribute('data-label'),
    );
    expect(labels).toEqual([
      'Valeur des titres',
      'Plus-value latente',
      'Part',
      'Valorisé le',
      null,
    ]);
  });

  it('keeps the caption and headers real and blocks only the values while loading', () => {
    const { container } = render(<HoldingsByAccountTable holdings={null} />);
    expect(screen.getByRole('table', { name: 'Positions par compte' })).toBeTruthy();
    expect(screen.getByRole('columnheader', { name: 'Part' })).toBeTruthy();
    expect(container.querySelectorAll('tbody [data-skeleton]').length).toBeGreaterThan(0);
  });
});
