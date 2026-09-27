import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { annualPreferences, annualReport } from './annualFixtures';
import { AnnualReportPage } from './AnnualReportPage';

const api = vi.hoisted(() => ({
  readAnnualReport: vi.fn(),
  readAnnualReportPreferences: vi.fn(),
  saveAnnualReportPreferences: vi.fn(),
  explainAnnualReportColumn: vi.fn(),
  listCategories: vi.fn(),
  listAccountGroups: vi.fn(),
  listAccounts: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

function ok<T>(data: T) {
  return { data, response: { ok: true, status: 200 } };
}
function failure(status: number) {
  return { data: undefined, response: { ok: false, status } };
}
function renderPage(year = 2026) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AnnualReportPage today="2026-09-24" year={year} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  api.readAnnualReport.mockResolvedValue(ok(annualReport));
  api.readAnnualReportPreferences.mockResolvedValue(ok(annualPreferences));
  api.saveAnnualReportPreferences.mockResolvedValue(ok({ ...annualPreferences, version: 4 }));
  api.listCategories.mockResolvedValue(ok({ items: [], total: 0 }));
  api.listAccountGroups.mockResolvedValue(ok({ items: [], total: 0 }));
  api.listAccounts.mockResolvedValue(ok({ items: [], total: 0 }));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('AnnualReportPage', () => {
  it('disables the previous-year link when the year before has no data', async () => {
    api.readAnnualReport.mockResolvedValue(ok({ ...annualReport, previousYearHasData: false }));
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    expect(screen.queryByRole('link', { name: 'Année précédente' })).toBeNull();
    const disabled = screen.getByText(/^Année précédente/).closest('[aria-disabled]')!;
    expect(disabled.getAttribute('aria-disabled')).toBe('true');
    const hint = document.getElementById(disabled.getAttribute('aria-describedby') ?? '');
    expect(hint?.textContent).toMatch(/aucune donnée/i);
  });

  it('keeps the previous-year link when the year before has data', async () => {
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    expect(screen.getByRole('link', { name: 'Année précédente' }).getAttribute('href')).toBe(
      '/reports/annual/2025',
    );
  });

  it('renders exact amounts and rates, and a dash with a hidden reason for empty cells', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /rapport annuel 2026/i });
    expect(within(table).getAllByText(/2\s?000,50/).length).toBeGreaterThan(0);
    expect(within(table).getAllByText(/25,00\s?%/).length).toBeGreaterThan(0);
    expect(within(table).getAllByText(/Non calculable/)[0]?.className).toContain('sr-only');
    expect(within(table).getAllByTitle('À venir').length).toBe(6);
    expect(
      within(table)
        .getAllByText('À venir')
        .every((node) => node.className.includes('sr-only')),
    ).toBe(true);
    expect(within(table).getAllByText('-').length).toBeGreaterThan(6);
  });

  it('renders the month cards with the same values as the table', async () => {
    renderPage();
    const cards = await screen.findByRole('list', { name: /mois de 2026/i });
    expect(within(cards).getAllByRole('listitem').length).toBeGreaterThanOrEqual(12);
    expect(within(cards).getAllByText(/2\s?000,50/).length).toBeGreaterThan(0);
  });

  it('renders values as plain text, never as links', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /rapport annuel 2026/i });
    expect(within(table).queryAllByRole('link')).toEqual([]);
    const cards = screen.getByRole('list', { name: /mois de 2026/i });
    expect(within(cards).queryAllByRole('link')).toEqual([]);
  });

  it('shows no info button beside a value that is not calculable', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /rapport annuel 2026/i });
    // cashIncome has a total; cashSavingsRate has none.
    expect(within(table).getAllByRole('button', { name: /expliquer.*revenus/i }).length).toBe(1);
    expect(within(table).queryAllByRole('button', { name: /expliquer.*épargne/i })).toEqual([]);
    const cards = screen.getByRole('list', { name: /mois de 2026/i });
    expect(within(cards).queryAllByRole('button', { name: /expliquer.*épargne/i })).toEqual([]);
  });

  it('lists every top category with its amount and share, without a data disclosure', async () => {
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    const region = screen.getByRole('region', { name: 'Principales catégories de dépenses' });
    expect(within(region).queryByText('Voir les données')).toBeNull();
    const list = within(region).getByRole('list', { name: 'Principales catégories de dépenses' });
    const rows = within(list).getAllByRole('listitem');
    expect(rows.map((row) => row.textContent)).toEqual([
      expect.stringContaining('Logement'),
      expect.stringContaining('Loisirs'),
      expect.stringContaining('Santé'),
    ]);
    expect(rows[1]?.textContent).toMatch(/600,00/);
    expect(rows[1]?.textContent).toMatch(/3,00\s?%/);
    expect(within(region).queryByRole('button', { name: /voir plus/i })).toBeNull();
  });

  it('shows six categories, then all of them with Voir plus, by keyboard too', async () => {
    const extra = Array.from({ length: 5 }, (_, index) => ({
      categoryId: `x${index}`,
      label: `Extra ${index}`,
      total: '10',
      share: '0.01',
    }));
    const top = annualReport.charts.topExpenseCategories;
    api.readAnnualReport.mockResolvedValue(
      ok({
        ...annualReport,
        charts: {
          ...annualReport.charts,
          topExpenseCategories: {
            ...top,
            items: [...top.items, ...extra],
          },
        },
      }),
    );
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    const region = screen.getByRole('region', { name: 'Principales catégories de dépenses' });
    const list = within(region).getByRole('list', { name: 'Principales catégories de dépenses' });
    expect(within(list).getAllByRole('listitem').length).toBe(6);
    const toggle = within(region).getByRole('button', { name: 'Voir plus' });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(toggle.getAttribute('aria-controls')).toBe(list.id);
    toggle.focus();
    expect(document.activeElement).toBe(toggle);
    fireEvent.click(toggle); // a native button turns Enter and Space into a click
    expect(within(list).getAllByRole('listitem').length).toBe(8);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(toggle.textContent).toBe('Voir moins');
    fireEvent.click(toggle);
    expect(within(list).getAllByRole('listitem').length).toBe(6);
  });

  it('lists every allocation group, dashing the ones without a value', async () => {
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    const region = screen.getByRole('region', { name: 'Répartition en fin de période' });
    expect(within(region).queryByText('Voir les données')).toBeNull();
    const rows = within(
      within(region).getByRole('list', { name: 'Répartition en fin de période' }),
    ).getAllByRole('listitem');
    expect(rows.length).toBe(2);
    expect(rows[1]?.textContent).toContain('Crypto');
    expect(within(rows[1]!).getAllByText('-').length).toBe(2);
    expect(within(rows[1]!).getByText(/Valorisation manquante/).className).toContain('sr-only');
  });

  it('saves the incomplete-month setting and refetches the report', async () => {
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    const toggle = screen.getByRole('button', { name: /mois incomplets/i });
    expect(toggle.getAttribute('aria-pressed')).toBe('false');
    expect(toggle.getAttribute('title')).toMatch(/exclus/);
    fireEvent.click(toggle);
    await waitFor(() =>
      expect(api.saveAnnualReportPreferences).toHaveBeenCalledWith(
        expect.objectContaining({
          body: {
            columns: ['cashIncome', 'cashSavingsRate'],
            incompleteMonths: 'include',
            version: 3,
          },
        }),
      ),
    );
    await waitFor(() => expect(api.readAnnualReport.mock.calls.length).toBeGreaterThan(1));
  });

  it('shows the mixed policy banner', async () => {
    api.readAnnualReport.mockResolvedValue(
      ok({
        ...annualReport,
        metricPolicy: { state: 'MIXED', version: null, label: null, versions: [1, 2] },
      }),
    );
    renderPage();
    const link = await screen.findByRole('link', { name: /politique d’indicateurs/i });
    expect(link.getAttribute('href')).toBe('/settings/metric-policy');
  });

  it('offers a retry after an error', async () => {
    api.readAnnualReport.mockResolvedValueOnce(failure(500));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: /réessayer/i }));
    expect(await screen.findByRole('table', { name: /rapport annuel 2026/i })).toBeTruthy();
  });

  it('keeps the data disclosure for the flows and net worth charts only', async () => {
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    expect(screen.getAllByText('Voir les données').length).toBe(2);
  });

  it('shows every chart whatever the selected columns', async () => {
    api.readAnnualReport.mockResolvedValue(
      ok({ ...annualReport, columns: [annualReport.columns[1]!] }),
    );
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    expect(screen.getAllByText('Voir les données').length).toBe(2);
    expect(screen.queryByText(/sélectionnez les colonnes/i)).toBeNull();
  });

  it('keeps every info button focusable', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /rapport annuel 2026/i });
    const buttons = within(table).getAllByRole('button');
    expect(buttons.length).toBeGreaterThan(0);
    for (const button of buttons) {
      expect(button.tabIndex).toBeGreaterThanOrEqual(0);
      button.focus();
      expect(document.activeElement).toBe(button);
    }
  });

  it('places the column and incomplete-month controls in the table header as icon buttons', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /rapport annuel 2026/i });
    const header = screen.getByRole('heading', { name: 'Rapport annuel 2026' }).parentElement!;
    const columns = within(header).getByRole('button', { name: 'Colonnes' });
    expect(columns.classList.contains('icon-button')).toBe(true);
    expect(columns.getAttribute('title')).toBe('Colonnes');
    expect(within(header).getByRole('button', { name: /mois incomplets/i })).toBeTruthy();
    expect(table.parentElement?.contains(columns)).toBe(false);
  });

  it('opens the aggregate explanation from a summary cell', async () => {
    api.explainAnnualReportColumn.mockResolvedValue(
      ok({
        column: 'cashIncome',
        kind: 'FLOW',
        formula: 'annual total = sum',
        policy: annualReport.metricPolicy,
        months: [
          {
            month: '2026-09',
            state: 'PROVISIONAL',
            value: '1',
            reason: null,
            counted: false,
            exclusionReason: 'PROVISIONAL',
          },
        ],
        aggregate: annualReport.aggregates.cashIncome,
      }),
    );
    renderPage();
    const table = await screen.findByRole('table', { name: /rapport annuel 2026/i });
    fireEvent.click(within(table).getAllByRole('button', { name: /expliquer.*revenus/i })[0]!);
    const dialog = await screen.findByRole('dialog');
    expect(await within(dialog).findByText('annual total = sum')).toBeTruthy();
  });

  it('keeps the table title outside the horizontally scrolling region', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /rapport annuel 2026/i });
    const title = screen.getByRole('heading', { name: 'Rapport annuel 2026' });
    const scroller = table.parentElement as HTMLElement;
    expect(scroller.contains(title)).toBe(false);
    expect(scroller.parentElement?.contains(title)).toBe(true);
  });

  it('offers explanations as circular info buttons with a tooltip', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /rapport annuel 2026/i });
    const button = within(table).getAllByRole('button', { name: /expliquer.*revenus/i })[0]!;
    expect(button.classList.contains('icon-button')).toBe(true);
    expect(button.getAttribute('title')).toBe(button.getAttribute('aria-label'));
    expect(button.textContent).toBe('');
  });

  it('draws the net worth like the dashboard curve and the donuts with a legend', async () => {
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    const netWorth = screen.getByRole('region', { name: 'Patrimoine net' });
    expect(within(netWorth).getByRole('img', { name: 'Patrimoine net' })).toBeTruthy();
    const allocation = screen.getByRole('region', { name: 'Répartition en fin de période' });
    expect(
      within(allocation).getByRole('list', { name: 'Répartition en fin de période' }),
    ).toBeTruthy();
  });

  it('limits the column picker to 40 columns', async () => {
    const many = Array.from({ length: 40 }, (_, i) => `category:${String(i).padStart(4, '0')}`);
    api.readAnnualReportPreferences.mockResolvedValue(ok({ ...annualPreferences, columns: many }));
    api.listCategories.mockResolvedValue(
      ok({
        items: Array.from({ length: 41 }, (_, i) => ({
          id: String(i).padStart(4, '0'),
          label: `Cat ${i}`,
        })),
        total: 41,
      }),
    );
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    fireEvent.click(screen.getByRole('button', { name: /colonnes/i }));
    const dialog = await screen.findByRole('dialog');
    await within(dialog).findByLabelText('Cat 40');
    expect(within(dialog).getByText('40 / 40')).toBeTruthy();
    expect((within(dialog).getByLabelText('Cat 40') as HTMLInputElement).disabled).toBe(true);
  });
});
