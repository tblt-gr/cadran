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
  it('renders exact amounts, rates and the non-calculable reason as text', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /rapport annuel 2026/i });
    expect(within(table).getAllByText(/2\s?000,50/).length).toBeGreaterThan(0);
    expect(within(table).getAllByText(/25\s?%/).length).toBeGreaterThan(0);
    expect(within(table).getAllByText(/Non calculable/).length).toBeGreaterThan(0);
    expect(within(table).getAllByText('À venir').length).toBe(6);
  });

  it('renders the month cards with the same values as the table', async () => {
    renderPage();
    const cards = await screen.findByRole('list', { name: /mois de 2026/i });
    expect(within(cards).getAllByRole('listitem').length).toBeGreaterThanOrEqual(12);
    expect(within(cards).getAllByText(/2\s?000,50/).length).toBeGreaterThan(0);
  });

  it('links a cell to its drill-down source', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /rapport annuel 2026/i });
    const link = within(table).getAllByRole('link', { name: /2\s?000,50/ })[0]!;
    expect(link.getAttribute('href')).toBe('/reports?month=2026-01');
  });

  it('saves the incomplete-month setting and refetches the report', async () => {
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    fireEvent.click(screen.getByRole('switch', { name: /mois incomplets/i }));
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

  it('explains a future year on 422', async () => {
    api.readAnnualReport.mockResolvedValue(failure(422));
    renderPage(2027);
    expect((await screen.findByRole('alert')).textContent).toMatch(/année/i);
  });

  it('offers a retry after an error', async () => {
    api.readAnnualReport.mockResolvedValueOnce(failure(500));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: /réessayer/i }));
    expect(await screen.findByRole('table', { name: /rapport annuel 2026/i })).toBeTruthy();
  });

  it('exposes chart data tables', async () => {
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    expect(screen.getAllByText('Voir les données').length).toBe(4);
    expect(screen.getAllByText('Logement').length).toBeGreaterThan(0);
    expect(screen.getAllByText(/Non calculable : Valorisation manquante/).length).toBeGreaterThan(
      0,
    );
  });

  it('shows every chart whatever the selected columns', async () => {
    api.readAnnualReport.mockResolvedValue(
      ok({ ...annualReport, columns: [annualReport.columns[1]!] }),
    );
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    expect(screen.getAllByText('Voir les données').length).toBe(4);
    expect(screen.queryByText(/sélectionnez les colonnes/i)).toBeNull();
  });

  it('keeps every drill-down link in the tab order and focusable', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /rapport annuel 2026/i });
    const links = within(table).getAllByRole('link');
    expect(links.length).toBeGreaterThan(0);
    for (const link of links) {
      expect(link.getAttribute('href')).toBeTruthy();
      expect(link.tabIndex).toBeGreaterThanOrEqual(0);
      link.focus();
      expect(document.activeElement).toBe(link);
    }
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
