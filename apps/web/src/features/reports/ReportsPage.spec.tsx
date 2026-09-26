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
  benefitSpending: metric,
  metricPolicy: { version: 2, label: 'Sans exclusion' },
  budgetExpenses: metric,
  uncategorizedExpenses: metric,
  budgetSurplus: metric,
  savingsTransfers: metric,
  cashSavingsRate: { value: '0.12345', assetCode: null, reason: null },
  savingsInflows: { value: '300', assetCode: 'EUR', reason: null },
  savingsWithdrawals: { value: '50', assetCode: 'EUR', reason: null },
  netSavingsTransfers: { value: '250', assetCode: 'EUR', reason: null },
  netSavingsRate: { value: '0.20825', assetCode: null, reason: null },
  beginningNetWorth: metric,
  endNetWorth: metric,
  netWorthDelta: metric,
  beginningNetWorthState: 'POSITIVE',
  accounts: [],
  reconciliationStatus: 'NO_ACCOUNTS',
};
const zeroMetric = { value: '0', assetCode: 'EUR', reason: null };
const emptyReport: MonthlyProjection = {
  ...report,
  state: 'EMPTY',
  pendingCount: 0,
  cashIncome: zeroMetric,
  nonCashBenefits: { value: null, assetCode: null, reason: 'UNKNOWN_METRIC_POLICY' },
  benefitSpending: { value: null, assetCode: null, reason: 'UNKNOWN_METRIC_POLICY' },
  budgetExpenses: zeroMetric,
  uncategorizedExpenses: zeroMetric,
  budgetSurplus: zeroMetric,
  savingsTransfers: zeroMetric,
  cashSavingsRate: { value: null, assetCode: null, reason: 'ZERO_CASH_INCOME' },
  savingsInflows: zeroMetric,
  savingsWithdrawals: zeroMetric,
  netSavingsTransfers: zeroMetric,
  netSavingsRate: { value: null, assetCode: null, reason: 'ZERO_CASH_INCOME' },
  beginningNetWorth: zeroMetric,
  endNetWorth: zeroMetric,
  netWorthDelta: zeroMetric,
  beginningNetWorthState: 'ZERO',
  accounts: [
    {
      id: '00000000-0000-7000-8000-0000000000d1',
      label: 'Compte courant',
      assetCode: 'EUR',
      kind: 'CURRENT',
      beginningValue: {
        value: '0',
        assetCode: 'EUR',
        quality: 'CURRENT',
        ageDays: 0,
        valuedOn: '2026-09-01',
      },
      endValue: {
        value: '0',
        assetCode: 'EUR',
        quality: 'CURRENT',
        ageDays: 0,
        valuedOn: '2026-09-30',
      },
      reconciliationStatus: 'UNRECONCILED',
    },
  ],
  reconciliationStatus: 'UNRECONCILED',
};
const explanation: MonthlyKpiExplanation = {
  kpi: 'cashIncome',
  metricPolicy: { version: 2, label: 'Sans exclusion' },
  value: '1200.50',
  assetCode: 'EUR',
  reason: null,
  reasonExplanation: null,
  formula: 'revenus comptabilisés',
  scope: 'espace courant',
  period: { start: '2026-09-01', end: '2026-09-30' },
  sourceTransactionIds: ['00000000-0000-7000-8000-0000000000a1'],
  sourceTransactions: [
    {
      id: '00000000-0000-7000-8000-0000000000a1',
      bookedOn: '2026-09-12',
      label: 'Salaire septembre',
      amount: { value: '1200.50', assetCode: 'EUR' },
      state: 'BOOKED',
    },
  ],
  sourceAccountIds: [],
  sourceTransferIds: ['00000000-0000-7000-8000-0000000000b1'],
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
    expect(screen.getByText('Politique v2')).toBeTruthy();
    expect(screen.getByRole('table').textContent).toContain('Dépenses des avantages');
    expect(screen.getByRole('table').textContent).toContain('1 200,50 €');
    expect(screen.getByRole('table').textContent).toContain('12,345 %');
    expect(screen.getByRole('table').textContent).toContain('250 €');
    expect(screen.getByRole('table').textContent).toContain('20,825 %');
    const explain = screen.getByRole('button', { name: 'Expliquer : Revenus en espèces' });
    explain.focus();
    expect(document.activeElement).toBe(explain);
    fireEvent.click(explain);
    expect(await screen.findByText('revenus comptabilisés')).toBeTruthy();
    const sources = screen.getByRole('table', { name: 'Transactions sources' });
    const sourceRegion = screen.getByRole('region', { name: 'Transactions sources' });
    expect(sourceRegion.getAttribute('tabindex')).toBe('0');
    expect(sources.textContent).toContain('12/09/2026');
    expect(sources.textContent).toContain('Salaire septembre');
    expect(sources.textContent).toContain('1 200,50 €');
    expect(sources.textContent).toContain('BOOKED');
    expect(screen.getByText('Aucune source dans le périmètre.')).toBeTruthy();
    expect(screen.getByText('00000000-0000-7000-8000-0000000000b1')).toBeTruthy();
    expect(explain.getAttribute('aria-controls')).toBe('monthly-kpi-explanation-cashIncome');
    expect(api.explainMonthlyKpi).toHaveBeenCalledWith(
      expect.objectContaining({
        query: { month: expect.any(String) },
        path: { kpi: 'cashIncome' },
      }),
    );
  });

  it('keeps month selection visible for loading and a contract-realistic empty report', async () => {
    api.readMonthlyProjection.mockReturnValue(new Promise(() => {}));
    const loading = render(
      <QueryClientProvider client={new QueryClient()}>
        <ReportsPage />
      </QueryClientProvider>,
    );
    expect((await screen.findByRole('status')).textContent).toContain('Chargement');
    expect(screen.getByLabelText('Mois')).toBeTruthy();

    api.readMonthlyProjection.mockReturnValue(success(emptyReport));
    loading.unmount();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ReportsPage />
      </QueryClientProvider>,
    );
    expect(await screen.findByText('Aucun mouvement comptabilisé pour ce mois')).toBeTruthy();
    expect(screen.getByLabelText('Mois')).toBeTruthy();
    expect(screen.getByRole('table')).toBeTruthy();
    expect(screen.getByRole('table').textContent).toContain('0 €');
    expect(screen.getAllByText('Non calculable : ZERO_CASH_INCOME')).toHaveLength(2);
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

  it('names unavailable figures and keeps the selector plus retry action for a generic error', async () => {
    api.readMonthlyProjection.mockReturnValue(
      success({ ...report, cashIncome: { value: null, assetCode: null, reason: 'NO_ACCOUNT' } }),
    );
    const calculable = render(
      <QueryClientProvider client={new QueryClient()}>
        <ReportsPage />
      </QueryClientProvider>,
    );
    expect(await screen.findByText('Non calculable : NO_ACCOUNT')).toBeTruthy();

    api.readMonthlyProjection.mockReset();
    api.readMonthlyProjection
      .mockReturnValueOnce(
        Promise.resolve({ data: undefined, response: new Response(null, { status: 500 }) }),
      )
      .mockReturnValue(success(report));
    calculable.unmount();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ReportsPage />
      </QueryClientProvider>,
    );
    expect((await screen.findByRole('alert')).textContent).toContain('indisponible');
    expect(screen.getByLabelText('Mois')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByRole('table')).toBeTruthy();
  });

  it('keeps the selector but does not offer retry when the workspace is unauthorized', async () => {
    api.readMonthlyProjection.mockReturnValue(
      Promise.resolve({ data: undefined, response: new Response(null, { status: 403 }) }),
    );
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ReportsPage />
      </QueryClientProvider>,
    );
    expect((await screen.findByRole('alert')).textContent).toContain('pas autorisé');
    expect(screen.getByLabelText('Mois')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Réessayer' })).toBeNull();
  });
  it('keeps a negative net savings metric distinct from zero and explains it by transfer ID', async () => {
    api.readMonthlyProjection.mockReturnValue(
      success({
        ...report,
        savingsInflows: { value: '100', assetCode: 'EUR', reason: null },
        savingsWithdrawals: { value: '300', assetCode: 'EUR', reason: null },
        netSavingsTransfers: { value: '-200', assetCode: 'EUR', reason: null },
        netSavingsRate: { value: '-0.1666', assetCode: null, reason: null },
      }),
    );
    api.explainMonthlyKpi.mockReturnValue(
      success({ ...explanation, kpi: 'netSavingsTransfers', value: '-200' }),
    );
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ReportsPage />
      </QueryClientProvider>,
    );
    expect((await screen.findByRole('table')).textContent).toContain('-200 €');
    fireEvent.click(
      screen.getByRole('button', { name: 'Expliquer : Épargne nette par virements' }),
    );
    expect(await screen.findByText('00000000-0000-7000-8000-0000000000b1')).toBeTruthy();
  });
});
