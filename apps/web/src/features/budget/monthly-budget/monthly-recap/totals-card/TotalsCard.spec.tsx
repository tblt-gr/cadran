import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import {
  goalEvaluation,
  goalPeriod,
  rateEvaluation,
} from '@/features/goals/goal-mark/goalEvaluationFixtures';
import { TotalsCard } from './TotalsCard';

const api = vi.hoisted(() => ({
  listGoalEvaluations: vi.fn(),
  readAnnualReportPreferences: vi.fn(),
  readMonthlyProjection: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

const preferences = (goalMarks: boolean) => ({
  columns: ['cashIncome'],
  incompleteMonths: 'exclude',
  goalMarks,
  version: 1,
});

function ok<T>(data: T) {
  return { data, response: { ok: true, status: 200 } };
}

const metric = (kpi: string | null, value: string | null) => ({
  kpi,
  value,
  assetCode: 'EUR',
  reason: null,
  pendingCount: 0,
  sourceTransactionIds: [],
  sourceTransferIds: [],
  sourceTransactions: [],
});
const totals = {
  cashIncome: metric('cashIncome', '3000.00'),
  budgetExpenses: metric('budgetExpenses', '1250.00'),
  savingsInflows: metric('savingsInflows', '200.00'),
  savingsWithdrawals: metric('savingsWithdrawals', '0.00'),
  netSavingsTransfers: metric('netSavingsTransfers', '200.00'),
  netSavingsRate: metric('netSavingsRate', '0.06'),
  expensesByAxis: [{ ...metric(null, '1250.00'), axis: 'ESSENTIAL' }],
  categories: [
    { ...metric(null, '400.00'), id: 'cat-1', label: 'Courses', type: 'EXPENSE' },
    { ...metric(null, '90.00'), id: 'cat-2', label: 'Loisirs', type: 'EXPENSE' },
  ],
};

function renderCard(month = '2026-08') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <TotalsCard
        month={month}
        onConfigure={() => undefined}
        owner
        totals={totals as never}
        visibleAxes={['ESSENTIAL']}
        visibleCategoryIds={['cat-1', 'cat-2']}
      />
    </QueryClientProvider>,
  );
}

/** The row of a label, whether it is the calculation button or the plain text of a marked row. */
function rowOf(label: string) {
  return screen.getByText(label).closest('li')!;
}

beforeEach(() => {
  api.readAnnualReportPreferences.mockResolvedValue(ok(preferences(true)));
  api.readMonthlyProjection.mockReturnValue(new Promise(() => undefined));
  api.listGoalEvaluations.mockResolvedValue(
    ok({
      months: [
        goalPeriod(
          '2026-08',
          rateEvaluation({ columnKey: 'netSavingsRate', metric: 'NET_SAVINGS_RATE' }),
          goalEvaluation({ columnKey: 'axis:ESSENTIAL' }),
          goalEvaluation({
            goalId: 'cat',
            label: 'Courses max',
            columnKey: 'category:cat-1',
            status: 'MET',
            gap: '10.00',
          }),
        ),
        goalPeriod('2026-07', goalEvaluation({ columnKey: 'category:cat-2' })),
      ],
      years: [],
    }),
  );
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('TotalsCard goals', () => {
  it('asks for the displayed month, monthly goals only', async () => {
    renderCard();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    expect(api.listGoalEvaluations.mock.calls[0]![0].query).toEqual({
      from: '2026-08',
      to: '2026-08',
      granularity: 'MONTH',
    });
  });

  it('marks a KPI, an axis and a category row with tone, target and explanation', async () => {
    renderCard();
    await screen.findAllByRole('button', { name: /^Objectif :/ });

    const rate = rowOf('Taux d’épargne net');
    expect(rate.querySelector('[data-tone]')?.getAttribute('data-tone')).toBe('negative');
    // The value to reach, without the word Minimum.
    expect(within(rate).getByText('10,00 %')).toBeTruthy();
    expect(within(rate).queryByText(/Minimum/)).toBeNull();
    expect(
      within(rate).getByRole('button', { name: 'Objectif : Taux d’épargne, Non atteint' }),
    ).toBeTruthy();

    const axis = rowOf('Essentiel');
    expect(axis.querySelector('[data-tone]')?.getAttribute('data-tone')).toBe('negative');
    expect(within(axis).getByText('1 200,00 €')).toBeTruthy();

    const courses = rowOf('Courses');
    expect(courses.querySelector('[data-tone]')?.getAttribute('data-tone')).toBe('positive');
  });

  it('shows no target on a row without goal, nor for a goal of another month', async () => {
    renderCard();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    const leisure = rowOf('Loisirs');
    expect(within(leisure).queryByRole('button', { name: /^Objectif :/ })).toBeNull();
    expect(leisure.querySelector('[data-tone]')).toBeNull();
    expect(within(rowOf('Revenus')).queryByText(/Plafond|Minimum/)).toBeNull();
  });

  it('opens the goals panel on the displayed month and the goal of the row', async () => {
    window.history.replaceState({}, '', '/budget/2026/08');
    renderCard();
    fireEvent.click(await screen.findByRole('button', { name: 'Objectif : Essentiel, Dépassé' }));
    const params = new URLSearchParams(window.location.search);
    expect(params.get('panel')).toBe('goals');
    expect(params.get('goal')).toBe('00000000-0000-7000-8000-000000000001');
    expect(params.get('period')).toBe('2026-08');
  });

  it('keeps the rows while loading and shows one retry line on failure', async () => {
    api.listGoalEvaluations.mockResolvedValue({
      data: undefined,
      response: { ok: false, status: 500 },
    });
    renderCard();
    expect(screen.getByRole('button', { name: 'Expliquer : Essentiel' })).toBeTruthy();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Les objectifs n’ont pas pu être vérifiés.');
    api.listGoalEvaluations.mockResolvedValue(ok({ months: [], years: [] }));
    fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));
    await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
  });

  it('has an Objectif column header, and shows only the `i` for a share of income', async () => {
    api.listGoalEvaluations.mockResolvedValue(
      ok({
        months: [
          goalPeriod(
            '2026-08',
            goalEvaluation({
              columnKey: 'axis:ESSENTIAL',
              valueType: 'INCOME_SHARE',
              formula: {
                key: 'goals.formula.incomeShare',
                operands: {
                  target: '1200.00',
                  achieved: '1250.00',
                  income: '4000.00',
                  ratio: '0.3',
                },
              },
            }),
          ),
        ],
        years: [],
      }),
    );
    renderCard();
    const axis = rowOf('Essentiel');
    await within(axis).findByRole('button', { name: /^Objectif :/ });
    expect(axis.textContent).not.toMatch(/1\s200|%/);
    expect(screen.getAllByText('Objectif').length).toBeGreaterThanOrEqual(3);
  });

  it('drops the column and the marks when the workspace turned the colouring off', async () => {
    api.readAnnualReportPreferences.mockResolvedValue(ok(preferences(false)));
    renderCard();
    await waitFor(() => expect(api.readAnnualReportPreferences).toHaveBeenCalled());
    await waitFor(() => expect(screen.queryByText('Objectif')).toBeNull());
    expect(api.listGoalEvaluations).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: /^Objectif :/ })).toBeNull();
    expect(document.querySelector('[data-tone]')).toBeNull();
  });

  it('shows the bare amount to reach on KPI, category and axis rows, whatever the direction', async () => {
    const amount = (
      columnKey: string,
      id: string,
      value: string,
      direction: 'CEILING' | 'MINIMUM',
    ) =>
      goalEvaluation({
        goalId: id,
        label: id,
        columnKey,
        direction,
        target: { value, assetCode: 'EUR' },
      });
    api.listGoalEvaluations.mockResolvedValue(
      ok({
        months: [
          goalPeriod(
            '2026-08',
            amount('netSavingsTransfers', 'epargne', '300.000000000000000000000000', 'MINIMUM'),
            amount('category:cat-2', 'loisirs', '800.00', 'CEILING'),
            amount('axis:ESSENTIAL', 'essentiel', '1200.50', 'CEILING'),
            amount('budgetExpenses', 'depenses', '2000.00', 'CEILING'),
          ),
        ],
        years: [],
      }),
    );
    renderCard();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    const target = (label: string) =>
      (rowOf(label).querySelector('.target, [class*="target"]')?.textContent ?? '').replace(
        /\s/g,
        ' ',
      );
    expect(target('Épargne nette par virements')).toBe('300,00 €');
    expect(target('Loisirs')).toBe('800,00 €');
    expect(target('Essentiel')).toBe('1 200,50 €');
    expect(target('Dépenses')).toBe('2 000,00 €');
  });

  it('replaces the calculation explanation of a marked row by its goal `i`, and keeps it elsewhere', async () => {
    renderCard();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    expect(screen.queryByRole('button', { name: 'Expliquer : Essentiel' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Expliquer : Taux d’épargne net' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Expliquer : Loisirs' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Expliquer : Revenus' })).toBeTruthy();
  });
});
