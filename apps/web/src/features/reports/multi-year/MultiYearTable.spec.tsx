import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { goalEvaluation, goalPeriod } from '@/features/goals/goal-mark/goalEvaluationFixtures';
import { multiYearReport } from './multiYearFixtures';
import { MultiYearTable } from './MultiYearTable';

const api = vi.hoisted(() => ({
  listGoalEvaluations: vi.fn(),
  readAnnualReportPreferences: vi.fn(),
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

function renderTable() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MultiYearTable report={multiYearReport} />
    </QueryClientProvider>,
  );
}

function rowOf(name: RegExp) {
  return screen.getByRole('row', { name });
}

const surplus = goalEvaluation({
  goalId: 'surplus',
  label: 'Excédent',
  periodicity: 'YEARLY',
  metric: 'BUDGET_SURPLUS',
  direction: 'MINIMUM',
  columnKey: 'cashIncome',
  target: { value: '6000.00', assetCode: 'EUR' },
  achieved: { value: '5400.00', assetCode: 'EUR' },
  gap: '-600.00',
  status: 'MISSED',
});

beforeEach(() => {
  api.readAnnualReportPreferences.mockResolvedValue(ok(preferences(true)));
  api.listGoalEvaluations.mockResolvedValue(
    ok({
      months: [],
      years: [
        goalPeriod('2025', surplus),
        goalPeriod('2026', goalEvaluation({ periodicity: 'YEARLY', status: 'MET', gap: '1.00' })),
      ],
    }),
  );
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('MultiYearTable goals', () => {
  it('asks once for the whole range of years, yearly only', async () => {
    renderTable();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    expect(api.listGoalEvaluations).toHaveBeenCalledTimes(1);
    expect(api.listGoalEvaluations.mock.calls[0]![0].query).toEqual({
      from: '2024-01',
      to: '2026-12',
      granularity: 'YEAR',
    });
  });

  it('marks the year row of a missed minimum and mutes an achieved one', async () => {
    renderTable();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    const y2025 = rowOf(/^2025/);
    const cell = within(y2025).getAllByRole('cell')[0]!;
    expect(cell.querySelector('[data-tone]')?.getAttribute('data-tone')).toBe('negative');
    expect(within(y2025).queryByText(/Minimum/)).toBeNull();
    expect(
      within(y2025).getByRole('button', { name: 'Objectif : Excédent, Non atteint' }),
    ).toBeTruthy();
    const y2026 = within(rowOf(/^2026/)).getAllByRole('cell')[0]!;
    expect(y2026.querySelector('[data-tone]')?.getAttribute('data-tone')).toBe('positive');
  });

  it('leaves the other years, other columns and the summary rows unchanged', async () => {
    renderTable();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    expect(within(rowOf(/^2024/)).queryByRole('button')).toBeNull();
    expect(within(rowOf(/^2025/)).getAllByRole('button')).toHaveLength(1);
    expect(within(screen.getAllByRole('rowgroup').at(-1)!).queryByRole('button')).toBeNull();
  });

  it('opens the goals panel on the year and goal of the row', async () => {
    window.history.replaceState({}, '', '/budget/2026?view=multi');
    renderTable();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Objectif : Excédent, Non atteint' }),
    );
    const params = new URLSearchParams(window.location.search);
    expect(params.get('panel')).toBe('goals');
    expect(params.get('goal')).toBe('surplus');
    expect(params.get('period')).toBe('2025');
  });

  it('shows the figures unmarked while loading and one retry line on failure', async () => {
    api.listGoalEvaluations.mockResolvedValue({
      data: undefined,
      response: { ok: false, status: 500 },
    });
    renderTable();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Les objectifs n’ont pas pu être vérifiés.');
    expect(screen.getAllByRole('row').length).toBeGreaterThan(3);
    api.listGoalEvaluations.mockResolvedValue(ok({ months: [], years: [] }));
    fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));
    await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
  });

  it('reads as before the goals when the workspace turned the colouring off', async () => {
    api.readAnnualReportPreferences.mockResolvedValue(ok(preferences(false)));
    renderTable();
    await waitFor(() => expect(api.readAnnualReportPreferences).toHaveBeenCalled());
    expect(api.listGoalEvaluations).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: /^Objectif :/ })).toBeNull();
    expect(document.querySelector('[data-tone]')).toBeNull();
  });
});
