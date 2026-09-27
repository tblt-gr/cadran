import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { annualReport } from '@/features/reports/annual/annualFixtures';
import { multiYearReport } from './multiYearFixtures';
import { MultiYearReportPage } from './MultiYearReportPage';

const api = vi.hoisted(() => ({
  readAnnualReport: vi.fn(),
  readMultiYearReport: vi.fn(),
  readAnnualReportPreferences: vi.fn(),
  saveAnnualReportPreferences: vi.fn(),
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
function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MultiYearReportPage today="2026-09-24" />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  api.readMultiYearReport.mockResolvedValue(ok(multiYearReport));
  api.readAnnualReport.mockResolvedValue(ok(annualReport));
  api.readAnnualReportPreferences.mockResolvedValue(
    ok({ columns: ['cashIncome', 'endNetWorth'], incompleteMonths: 'exclude', version: 3 }),
  );
  api.saveAnnualReportPreferences.mockResolvedValue(
    ok({ columns: ['cashIncome', 'endNetWorth'], incompleteMonths: 'include', version: 4 }),
  );
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('MultiYearReportPage', () => {
  it('shows a skeleton status while the first load is pending', async () => {
    let resolve!: (value: ReturnType<typeof ok<typeof multiYearReport>>) => void;
    api.readMultiYearReport.mockReturnValue(new Promise((r) => (resolve = r)));
    renderPage();
    const status = screen.getByRole('status');
    expect(within(status).getAllByRole('row')).toHaveLength(1);
    resolve(ok(multiYearReport));
    await screen.findByRole('table', { name: /pluriannuel/i });
  });

  it('shows exactly one skeleton row per requested year while a narrowed range is fetching', async () => {
    renderPage();
    await screen.findByRole('table', { name: /pluriannuel/i });
    let resolveNext!: (value: ReturnType<typeof ok<typeof multiYearReport>>) => void;
    api.readMultiYearReport.mockReturnValue(new Promise((r) => (resolveNext = r)));
    const fromSelect = screen.getByLabelText('Depuis') as HTMLSelectElement;
    fireEvent.change(fromSelect, { target: { value: '2025' } });
    const status = await screen.findByRole('status');
    // Narrowed from 2025 to the previous to (2026): exactly two requested years.
    expect(within(status).getAllByRole('row').length).toBe(2);
    resolveNext(
      ok({ ...multiYearReport, from: 2025, to: 2026, years: multiYearReport.years.slice(1) }),
    );
    await screen.findByRole('table', { name: /pluriannuel/i });
  });

  it('offers a retry after a non-range error', async () => {
    api.readMultiYearReport.mockResolvedValueOnce(failure(500));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: /réessayer/i }));
    expect(await screen.findByRole('table', { name: /pluriannuel/i })).toBeTruthy();
  });

  it('surfaces a 422 range error inline next to the selector, keeping it usable', async () => {
    renderPage();
    await screen.findByRole('table', { name: /pluriannuel/i });
    api.readMultiYearReport.mockResolvedValueOnce(failure(422));
    const fromSelect = screen.getByLabelText('Depuis') as HTMLSelectElement;
    fireEvent.change(fromSelect, { target: { value: '2025' } });
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toMatch(/15 ans|postérieure/i);
    expect(screen.getByLabelText('Depuis')).toBeTruthy();
    expect(screen.getByLabelText("Jusqu'à")).toBeTruthy();
    expect(screen.queryByRole('table')).toBeNull();
  });

  it('shows the empty state when the workspace has no data', async () => {
    api.readMultiYearReport.mockResolvedValue(ok({ ...multiYearReport, years: [] }));
    renderPage();
    expect(await screen.findByText(/aucune donnée/i)).toBeTruthy();
    expect(screen.queryByRole('table')).toBeNull();
  });

  it('renders one row per year, oldest first, each linking to its annual report', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /pluriannuel/i });
    const links = within(table).getAllByRole('link');
    expect(links.map((link) => link.textContent)).toEqual(['2024', '2025', '2026']);
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/reports/annual/2024',
      '/reports/annual/2025',
      '/reports/annual/2026',
    ]);
    for (const link of links) {
      link.focus();
      expect(document.activeElement).toBe(link);
    }
  });

  it('shows the exact amount and its signed change against the previous year', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /pluriannuel/i });
    expect(within(table).getAllByText(/24\s?000,00\s?€/).length).toBeGreaterThan(0);
    expect(within(table).getAllByText(/\+2\s?000,00\s?€/).length).toBeGreaterThan(0);
  });

  it('shows a RATE column change in points, never as a percentage', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /pluriannuel/i });
    expect(within(table).getAllByText(/\+5,00\s?points/).length).toBeGreaterThan(0);
  });

  it('shows the changeRatio reason inline when it fails on its own (ZERO_BASE / NEGATIVE_BASE)', async () => {
    api.readMultiYearReport.mockResolvedValue(
      ok({
        ...multiYearReport,
        years: [
          multiYearReport.years[0]!,
          {
            ...multiYearReport.years[1]!,
            cells: {
              ...multiYearReport.years[1]!.cells,
              cashIncome: {
                value: '24000',
                reason: null,
                asOf: null,
                change: '2000',
                changeRatio: null,
                changeReason: 'ZERO_BASE',
              },
            },
          },
          multiYearReport.years[2]!,
        ],
      }),
    );
    renderPage();
    const table = await screen.findByRole('table', { name: /pluriannuel/i });
    expect(within(table).getAllByText(/\+2\s?000,00\s?€/).length).toBeGreaterThan(0);
    expect(within(table).getByText(/valeur nulle l.année précédente/i)).toBeTruthy();
  });

  it('shows the change as null with a reason when the year is partial', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /pluriannuel/i });
    const rows = within(table).getAllByRole('row');
    const year2026 = rows.find((row) => row.textContent?.includes('2026'))!;
    expect(within(year2026).getAllByText('-').length).toBeGreaterThan(0);
  });

  it('shows the policy version for each year', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /pluriannuel/i });
    expect(within(table).getAllByText('v1').length).toBe(3);
  });

  it('shows Mixte when a year carries a mixed metric policy', async () => {
    api.readMultiYearReport.mockResolvedValue(
      ok({
        ...multiYearReport,
        years: [
          { ...multiYearReport.years[0]!, metricPolicy: { state: 'MIXED', version: null } },
          ...multiYearReport.years.slice(1),
        ],
      }),
    );
    renderPage();
    const table = await screen.findByRole('table', { name: /pluriannuel/i });
    expect(within(table).getByText('Mixte')).toBeTruthy();
  });

  it('exposes snapshotMonths and liveMonths for a year that mixes both', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /pluriannuel/i });
    const rows = within(table).getAllByRole('row');
    const year2026 = rows.find((row) => row.textContent?.includes('2026'))!;
    expect(year2026.textContent).toMatch(/8/);
    expect(year2026.textContent).toMatch(/1/);
  });

  it('renders 15 rows for a long history', async () => {
    const years = Array.from({ length: 15 }, (_, index) => ({
      ...multiYearReport.years[0]!,
      year: 2012 + index,
    }));
    api.readMultiYearReport.mockResolvedValue(
      ok({ ...multiYearReport, from: 2012, to: 2026, years }),
    );
    renderPage();
    const table = await screen.findByRole('table', { name: /pluriannuel/i });
    expect(within(table).getAllByRole('link').length).toBe(15);
  });

  it('shows the nominal values notice', async () => {
    renderPage();
    await screen.findByRole('table', { name: /pluriannuel/i });
    expect(screen.getByText(/valeurs nominales, non corrigées de l’inflation/i)).toBeTruthy();
  });

  it('switches to detailed mode and renders every month of the selected years in one chronology', async () => {
    api.readAnnualReport.mockImplementation(({ path }: { path: { year: number } }) =>
      Promise.resolve(
        ok({
          ...annualReport,
          year: path.year,
          charts: {
            ...annualReport.charts,
            flows: {
              ...annualReport.charts.flows,
              months: annualReport.charts.flows.months.map((month) => ({
                ...month,
                month: `${path.year}${month.month.slice(4)}`,
              })),
            },
            netWorth: {
              ...annualReport.charts.netWorth,
              months: annualReport.charts.netWorth.months.map((month) => ({
                ...month,
                month: `${path.year}${month.month.slice(4)}`,
              })),
            },
          },
        }),
      ),
    );
    renderPage();
    await screen.findByRole('table', { name: /pluriannuel/i });

    fireEvent.click(screen.getByRole('button', { name: /vue détaillée/i }));

    const table = await screen.findByRole('table', { name: /rapport détaillé 2024-2026/i });
    const rows = within(table).getAllByRole('row');
    expect(rows).toHaveLength(37);
    expect(rows[1]?.textContent).toMatch(/janvier 2024/i);
    expect(rows[12]?.textContent).toMatch(/décembre 2024/i);
    expect(rows[13]?.textContent).toMatch(/janvier 2025/i);
    expect(rows[36]?.textContent).toMatch(/décembre 2026/i);
    expect(screen.getAllByText(/voir les données/i).length).toBeGreaterThanOrEqual(1);
    const alternatives = [...document.querySelectorAll('details table')];
    expect(alternatives).toHaveLength(2);
    expect(alternatives.map((table) => table.querySelectorAll('tbody tr').length)).toEqual([
      36, 36,
    ]);
    expect(api.readAnnualReport).toHaveBeenCalledWith(
      expect.objectContaining({ path: { year: 2024 } }),
    );
    fireEvent.click(screen.getByRole('button', { name: /vue compacte/i }));
    expect(await screen.findByRole('table', { name: /pluriannuel/i })).toBeTruthy();
  });

  it('keeps report tabs present while the detailed annual reports are loading', async () => {
    api.readAnnualReport.mockReturnValue(new Promise(() => undefined));
    renderPage();
    await screen.findByRole('table', { name: /pluriannuel/i });

    fireEvent.click(screen.getByRole('button', { name: /vue détaillée/i }));

    expect(screen.getByRole('navigation', { name: /type de rapport/i })).toBeTruthy();
    expect((await screen.findAllByRole('status')).length).toBe(1);
  });

  it('shows one policy warning before the continuous chronology when the selected years differ', async () => {
    api.readAnnualReport.mockImplementation(({ path }: { path: { year: number } }) =>
      Promise.resolve(
        ok({
          ...annualReport,
          year: path.year,
          rows: annualReport.rows.map((row) => ({
            ...row,
            month: `${path.year}${row.month.slice(4)}`,
          })),
          charts: {
            ...annualReport.charts,
            flows: {
              ...annualReport.charts.flows,
              months: annualReport.charts.flows.months.map((month) => ({
                ...month,
                month: `${path.year}${month.month.slice(4)}`,
              })),
            },
            netWorth: {
              ...annualReport.charts.netWorth,
              months: annualReport.charts.netWorth.months.map((month) => ({
                ...month,
                month: `${path.year}${month.month.slice(4)}`,
              })),
            },
          },
        }),
      ),
    );
    api.readMultiYearReport.mockResolvedValue(
      ok({
        ...multiYearReport,
        years: [
          multiYearReport.years[0]!,
          {
            ...multiYearReport.years[1]!,
            metricPolicy: { state: 'SINGLE', version: 2 },
          },
          multiYearReport.years[2]!,
        ],
      }),
    );
    renderPage();
    await screen.findByRole('table', { name: /pluriannuel/i });

    fireEvent.click(screen.getByRole('button', { name: /vue détaillée/i }));

    expect(
      await screen.findByText(/plusieurs versions de la politique d’indicateurs/i),
    ).toBeTruthy();
    expect(screen.getAllByRole('table', { name: /rapport détaillé/i })).toHaveLength(1);
  });

  it('loads annual months only for the currently selected detailed range', async () => {
    api.readAnnualReport.mockImplementation(({ path }: { path: { year: number } }) =>
      Promise.resolve(
        ok({
          ...annualReport,
          year: path.year,
          charts: {
            ...annualReport.charts,
            flows: {
              ...annualReport.charts.flows,
              months: annualReport.charts.flows.months.map((month) => ({
                ...month,
                month: `${path.year}${month.month.slice(4)}`,
              })),
            },
            netWorth: {
              ...annualReport.charts.netWorth,
              months: annualReport.charts.netWorth.months.map((month) => ({
                ...month,
                month: `${path.year}${month.month.slice(4)}`,
              })),
            },
          },
        }),
      ),
    );
    api.readMultiYearReport.mockImplementation(({ query }: { query: { from?: number } }) =>
      Promise.resolve(
        ok(
          query.from === 2025
            ? { ...multiYearReport, from: 2025, years: multiYearReport.years.slice(1) }
            : multiYearReport,
        ),
      ),
    );
    renderPage();
    await screen.findByRole('table', { name: /pluriannuel/i });
    fireEvent.change(screen.getByLabelText('Depuis'), { target: { value: '2025' } });
    await screen.findByRole('table', { name: /pluriannuel/i });
    fireEvent.click(screen.getByRole('button', { name: /vue détaillée/i }));
    await screen.findByRole('table', { name: /rapport détaillé 2025-2026/i });
    expect(api.readAnnualReport).toHaveBeenCalledWith(
      expect.objectContaining({ path: { year: 2025 } }),
    );
    expect(api.readAnnualReport).toHaveBeenCalledWith(
      expect.objectContaining({ path: { year: 2026 } }),
    );
    expect(api.readAnnualReport).not.toHaveBeenCalledWith(
      expect.objectContaining({ path: { year: 2024 } }),
    );
  });

  it('shows the average of complete years in a summary row', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /pluriannuel/i });
    expect(within(table).getAllByText(/23\s?000,00\s?€/).length).toBeGreaterThan(0);
  });

  it('shows the cash savings rate summary supplied by the report', async () => {
    renderPage();
    const table = await screen.findByRole('table', { name: /pluriannuel/i });
    expect(within(table).getAllByText(/22,50\s?%/).length).toBeGreaterThan(0);
  });

  it('lets the caller narrow the year range and refetches', async () => {
    renderPage();
    await screen.findByRole('table', { name: /pluriannuel/i });
    const fromSelect = screen.getByLabelText('Depuis') as HTMLSelectElement;
    const toSelect = screen.getByLabelText("Jusqu'à") as HTMLSelectElement;
    fireEvent.change(fromSelect, { target: { value: '2025' } });
    await screen.findByRole('table', { name: /pluriannuel/i });
    expect(api.readMultiYearReport).toHaveBeenLastCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ from: 2025 }) }),
    );
    expect(toSelect).toBeTruthy();
  });

  it('uses availableFrom to select an older 15-year window than the default response', async () => {
    renderPage();
    await screen.findByRole('table', { name: /pluriannuel/i });
    const fromSelect = screen.getByLabelText('Depuis') as HTMLSelectElement;
    expect(within(fromSelect).getByRole('option', { name: '2010' })).toBeTruthy();

    fireEvent.change(fromSelect, { target: { value: '2010' } });
    await screen.findByRole('table', { name: /pluriannuel/i });
    expect(api.readMultiYearReport).toHaveBeenLastCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ from: 2010, to: 2024 }) }),
    );
  });

  describe('mobile cards (below 640 px)', () => {
    it('shows the same partial-year and snapshot/live notes as the table', async () => {
      renderPage();
      const cards = await screen.findByRole('list', { name: /pluriannuel/i });
      const items = within(cards).getAllByRole('listitem');
      const year2026Card = items.find((item) => item.textContent?.includes('2026'))!;
      const year2025Card = items.find((item) => item.textContent?.includes('2025'))!;
      expect(year2026Card.textContent).toMatch(/partielle/i);
      expect(year2026Card.textContent).toMatch(/8 mois clôturés, 1 en direct/i);
      expect(year2025Card.textContent).toMatch(/v1/);
    });

    it('ends with a summary card carrying the average, minimum and maximum', async () => {
      renderPage();
      const cards = await screen.findByRole('list', { name: /pluriannuel/i });
      const items = within(cards).getAllByRole('listitem');
      const summaryCard = items[items.length - 1]!;
      expect(summaryCard.textContent).toMatch(/moyenne des années complètes/i);
      expect(within(summaryCard).getAllByText(/23\s?000,00\s?€/).length).toBeGreaterThan(0);
      expect(within(summaryCard).getAllByText(/22\s?000,00\s?€/).length).toBeGreaterThan(0);
      expect(within(summaryCard).getAllByText(/24\s?000,00\s?€/).length).toBeGreaterThan(0);
    });
  });
});
