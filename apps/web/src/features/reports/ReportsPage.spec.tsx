import type { MonthlyKpiExplanation, MonthlyProjection } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { ReportsPage } from './ReportsPage';

const api = vi.hoisted(() => ({ readMonthlyProjection: vi.fn(), explainMonthlyKpi: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

const metric = { value: '1200.50', assetCode: 'EUR', reason: null };
const report: MonthlyProjection = {
  month: '2026-09',
  periodStart: '2026-09-01',
  periodEnd: '2026-09-30',
  state: 'PENDING',
  quality: 'CURRENT',
  pendingCount: 2,
  cashIncome: metric,
  nonCashBenefits: metric,
  budgetExpenses: metric,
  uncategorizedExpenses: metric,
  budgetSurplus: metric,
  savingsTransfers: metric,
  cashSavingsRate: { value: '0.12345', assetCode: null, reason: null },
  beginningNetWorth: metric,
  endNetWorth: metric,
  netWorthDelta: metric,
  beginningNetWorthState: 'POSITIVE',
  accounts: [],
  reconciliationStatus: 'NO_ACCOUNTS',
};
const explanation: MonthlyKpiExplanation = {
  kpi: 'cashIncome',
  value: '1200.50',
  assetCode: 'EUR',
  reason: null,
  reasonExplanation: null,
  formula: 'revenus comptabilisés',
  scope: 'espace courant',
  period: { start: '2026-09-01', end: '2026-09-30' },
  sourceTransactionIds: [],
  sourceAccountIds: [],
  freshness: 'CURRENT',
  quality: 'CURRENT',
  pendingCount: 2,
};
const success = <T,>(data: T) =>
  Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });

describe('ReportsPage', () => {
  afterEach(() => {
    cleanup();
    vi.resetAllMocks();
  });

  it('prints exact backend metrics, pending data and the server explanation without calculating', async () => {
    api.readMonthlyProjection.mockReturnValue(success(report));
    api.explainMonthlyKpi.mockReturnValue(success(explanation));
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <ReportsPage />
      </QueryClientProvider>,
    );
    expect(await screen.findByRole('table')).toBeTruthy();
    expect(screen.getByText(/2 mouvement\(s\) en attente/)).toBeTruthy();
    expect(screen.getByRole('table').textContent).toContain('1 200,50 €');
    expect(screen.getByRole('table').textContent).toContain('12,345 %');
    const explain = screen.getByRole('button', { name: 'Expliquer : Revenus en espèces' });
    explain.focus();
    expect(document.activeElement).toBe(explain);
    fireEvent.click(explain);
    expect(await screen.findByText('revenus comptabilisés')).toBeTruthy();
    expect(screen.getAllByText('Aucune source dans le périmètre.')).toHaveLength(2);
    expect(explain.getAttribute('aria-controls')).toBe('monthly-kpi-explanation-cashIncome');
    expect(api.explainMonthlyKpi).toHaveBeenCalledWith(
      expect.objectContaining({
        query: { month: expect.any(String) },
        path: { kpi: 'cashIncome' },
      }),
    );
  });

  it('keeps month selection visible for loading, empty and unauthorized states', async () => {
    api.readMonthlyProjection.mockReturnValue(new Promise(() => {}));
    const loading = render(
      <QueryClientProvider client={new QueryClient()}>
        <ReportsPage />
      </QueryClientProvider>,
    );
    expect((await screen.findByRole('status')).textContent).toContain('Chargement');
    expect(screen.getByLabelText('Mois')).toBeTruthy();

    api.readMonthlyProjection.mockReturnValue(success({ ...report, state: 'EMPTY' }));
    loading.unmount();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ReportsPage />
      </QueryClientProvider>,
    );
    expect(await screen.findByText('Aucune donnée publiée pour ce mois')).toBeTruthy();
    expect(screen.getByLabelText('Mois')).toBeTruthy();
    expect(screen.getByRole('table')).toBeTruthy();
  });

  it('switches the bounded monthly query when the selected month changes', async () => {
    api.readMonthlyProjection.mockReturnValue(success(report));
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ReportsPage />
      </QueryClientProvider>,
    );
    const month = await screen.findByLabelText('Mois');
    fireEvent.change(month, { target: { value: '2026-08' } });
    expect(await screen.findByRole('table')).toBeTruthy();
    expect(api.readMonthlyProjection).toHaveBeenLastCalledWith(
      expect.objectContaining({ query: { month: '2026-08' } }),
    );
  });

  it('names unavailable figures and authorization failures instead of presenting zero', async () => {
    api.readMonthlyProjection.mockReturnValue(
      success({ ...report, cashIncome: { value: null, assetCode: null, reason: 'NO_ACCOUNT' } }),
    );
    const calculable = render(
      <QueryClientProvider client={new QueryClient()}>
        <ReportsPage />
      </QueryClientProvider>,
    );
    expect(await screen.findByText('Non calculable : NO_ACCOUNT')).toBeTruthy();

    api.readMonthlyProjection.mockReturnValue(
      Promise.resolve({ data: undefined, response: new Response(null, { status: 401 }) }),
    );
    calculable.unmount();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ReportsPage />
      </QueryClientProvider>,
    );
    expect((await screen.findByRole('alert')).textContent).toContain('pas autorisé');
  });
});
