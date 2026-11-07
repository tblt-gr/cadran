import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import {
  holdingsFixture,
  knownCostFigures,
  totalAccountSummary,
} from '@/features/investments/holdingsFixture';
import { expectExplanation } from '@/features/investments/explain-info/explanationDialog';
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

  it('discloses the lines of a detailed account with an explained dash for the unknown cost', async () => {
    render(<HoldingsByAccountTable holdings={holdingsFixture()} />);
    const toggle = screen.getByRole('button', { name: /Détail par actif : CTO/ });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(screen.queryByText('IE00B4L5Y983 · ETF')).toBeNull();
    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(screen.getByText('IE00B4L5Y983 · ETF')).toBeTruthy();
    expect(screen.getByText('A · Action')).toBeTruthy();
    expect(screen.queryByText(/Plus-value non calculable/)).toBeNull();
    await expectExplanation(
      screen.getByRole('button', {
        name: 'Expliquer : Plus-value latente (Action A, CTO) non calculable',
      }),
      'Plus-value latente (Action A, CTO)',
      "Plus-value non calculable : coût d'origine inconnu",
    );
    expect(screen.getByText(/\+30,00/)).toBeTruthy();
    const quantity = screen.getByText('20').closest('td')!;
    expect(quantity.className).toBe(quantity.nextElementSibling!.className);
    fireEvent.click(toggle);
    expect(screen.queryByText('A · Action')).toBeNull();
  });

  it('explains a missing price and mixed units in a modal instead of the line value, never zero', async () => {
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
    expect(screen.queryByText('Valeur non calculable : cours manquant')).toBeNull();
    await expectExplanation(
      screen.getByRole('button', {
        name: /Expliquer : Valeur des titres \(.*CTO\) non calculable/,
      }),
      /Valeur des titres \(.*CTO\)/,
      'Valeur non calculable : cours manquant',
    );
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
    await expectExplanation(
      screen.getByRole('button', {
        name: /Expliquer : Valeur des titres \(.*CTO\) non calculable/,
      }),
      /Valeur des titres \(.*CTO\)/,
      'Totaux non calculés : les comptes utilisent plusieurs unités.',
    );
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

  it('shows the known-cost gain as the account gain with its modal explanation', async () => {
    const base = holdingsFixture();
    render(
      <HoldingsByAccountTable
        holdings={{
          ...base,
          accounts: [{ ...base.accounts[0]!, knownCost: knownCostFigures }, base.accounts[1]!],
        }}
      />,
    );
    const cto = rowOf('CTO');
    const gainCell = within(cto)
      .getByRole('button', {
        name: 'Expliquer : Plus-value latente (CTO) calculé sur une partie des positions',
      })
      .closest('td')!;
    expect(gainCell.getAttribute('data-label')).toBe('Plus-value latente');
    expect(within(gainCell).getByText(/\+2\s454,65/)).toBeTruthy();
    expect(within(gainCell).getByText('(+6,15 %)')).toBeTruthy();
    expect(within(gainCell).queryByText(/Plus-value non calculable/)).toBeNull();
    await expectExplanation(
      within(gainCell).getByRole('button', {
        name: 'Expliquer : Plus-value latente (CTO) calculé sur une partie des positions',
      }),
      'Plus-value latente (CTO)',
      /1 position sans coût exclue/,
    );
    // One grid child, so the stacked mobile layout keeps its label column.
    expect(gainCell.children).toHaveLength(1);
    expect(
      within(rowOf('PEA')).queryByRole('button', { name: /calculé sur une partie des positions/ }),
    ).toBeNull();
  });

  it('keeps a dash and its explanation on an account without known-cost figures', async () => {
    render(<HoldingsByAccountTable holdings={holdingsFixture()} />);
    expect(within(rowOf('CTO')).queryByText(/Plus-value non calculable/)).toBeNull();
    await expectExplanation(
      within(rowOf('CTO')).getByRole('button', {
        name: 'Expliquer : Plus-value latente (CTO) non calculable',
      }),
      'Plus-value latente (CTO)',
      "Plus-value non calculable : coût d'origine inconnu",
    );
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
