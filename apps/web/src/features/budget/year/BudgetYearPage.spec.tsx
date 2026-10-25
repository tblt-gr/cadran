import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { annualPreferences, annualReport } from '@/features/reports/annual/annualFixtures';
import { multiYearReport } from '@/features/reports/multi-year/multiYearFixtures';
import { BudgetYearPage } from './BudgetYearPage';

const api = vi.hoisted(() => ({
  readAnnualReport: vi.fn(),
  readMultiYearReport: vi.fn(),
  readMonthlyBounds: vi.fn(),
  readAnnualReportPreferences: vi.fn(),
  saveAnnualReportPreferences: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

const ok = <T,>(data: T) => ({ data, response: { ok: true, status: 200 } });
const url = () => window.location.pathname + window.location.search;

function renderPage(search = '') {
  window.history.replaceState({}, '', `/budget/2026${search}`);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <BudgetYearPage today="2026-09-24" year={2026} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  api.readAnnualReport.mockResolvedValue(ok(annualReport));
  api.readMultiYearReport.mockResolvedValue(ok(multiYearReport));
  api.readMonthlyBounds.mockResolvedValue(
    ok({ firstDataMonth: '2024-01', currentMonth: '2026-09', timezone: 'Europe/Paris' }),
  );
  api.readAnnualReportPreferences.mockResolvedValue(ok(annualPreferences));
  api.saveAnnualReportPreferences.mockResolvedValue(ok(annualPreferences));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  window.history.replaceState({}, '', '/');
});

describe('BudgetYearPage', () => {
  it('shows the compact annual report by default, without any query string', async () => {
    renderPage();
    expect(await screen.findByRole('table', { name: /rapport annuel 2026/i })).toBeTruthy();
    expect(url()).toBe('/budget/2026');
  });

  it('writes the multi-year mode to the query string and clears it on the way back', async () => {
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    fireEvent.click(screen.getByRole('button', { name: 'Pluriannuel' }));
    expect(url()).toBe('/budget/2026?view=multi');
    expect(await screen.findByRole('table', { name: /pluriannuel/i })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Annuel' }));
    expect(url()).toBe('/budget/2026');
    expect(await screen.findByRole('table', { name: /rapport annuel 2026/i })).toBeTruthy();
  });

  it('writes the display mode and the year range to the query string', async () => {
    renderPage('?view=multi');
    await screen.findByRole('table', { name: /pluriannuel/i });
    fireEvent.click(screen.getByRole('button', { name: /vue détaillée/i }));
    expect(url()).toBe('/budget/2026?view=multi&display=detailed');
    fireEvent.change(screen.getByLabelText('Depuis'), { target: { value: '2025' } });
    expect(url()).toMatch(/^\/budget\/2026\?view=multi&display=detailed&from=2025&to=\d{4}$/);
  });

  it('reads the range from the query string and asks the backend for it', async () => {
    renderPage('?view=multi&from=2025&to=2026');
    await screen.findByRole('table', { name: /pluriannuel/i });
    expect(api.readMultiYearReport).toHaveBeenCalledWith(
      expect.objectContaining({ query: { from: 2025, to: 2026 } }),
    );
  });

  it('falls back to the backend default bounds when from and to are missing or half given', async () => {
    renderPage('?view=multi&from=2025');
    await screen.findByRole('table', { name: /pluriannuel/i });
    for (const call of api.readMultiYearReport.mock.calls) {
      expect(call[0].query).toEqual({});
    }
  });

  it('follows the browser history (popstate) between the two views', async () => {
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    act(() => {
      window.history.pushState({}, '', '/budget/2026?view=multi');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(await screen.findByRole('table', { name: /pluriannuel/i })).toBeTruthy();
    act(() => {
      window.history.pushState({}, '', '/budget/2026');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(await screen.findByRole('table', { name: /rapport annuel 2026/i })).toBeTruthy();
  });

  it('drops the multi-year view when the year select changes: it lands on the compact annual report', async () => {
    renderPage('?view=multi&display=detailed&from=2024&to=2026');
    await screen.findByRole('group', { name: /type de rapport/i });
    await waitFor(() =>
      expect(
        Array.from((screen.getByRole('combobox', { name: 'Année' }) as HTMLSelectElement).options)
          .map((option) => option.value)
          .includes('2025'),
      ).toBe(true),
    );
    fireEvent.change(screen.getByRole('combobox', { name: 'Année' }), {
      target: { value: '2025' },
    });
    expect(url()).toBe('/budget/2025');
  });

  it('disables the multi-year view when the workspace has a single year of data', async () => {
    api.readMultiYearReport.mockResolvedValue(ok({ availableFrom: 2026, from: 2026, to: 2026 }));
    renderPage();
    await screen.findByRole('table', { name: /rapport annuel 2026/i });
    await waitFor(() =>
      expect(
        (screen.getByRole('button', { name: 'Pluriannuel' }) as HTMLButtonElement).disabled,
      ).toBe(true),
    );
  });
});
