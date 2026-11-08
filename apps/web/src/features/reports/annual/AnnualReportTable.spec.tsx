import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import {
  goalEvaluation,
  goalPeriod,
  rateEvaluation,
} from '@/features/goals/goal-mark/goalEvaluationFixtures';
import { annualReport } from './annualFixtures';
import { AnnualReportTable } from './AnnualReportTable';

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
      <AnnualReportTable onExplain={() => undefined} report={annualReport} />
    </QueryClientProvider>,
  );
}

function rowOf(name: RegExp) {
  return screen.getByRole('row', { name });
}
function toneOf(row: HTMLElement, column: number) {
  const cell = within(row).getAllByRole('cell')[column]!;
  return cell.querySelector('[data-tone]')?.getAttribute('data-tone') ?? null;
}

beforeEach(() => {
  api.readAnnualReportPreferences.mockResolvedValue(ok(preferences(true)));
  api.listGoalEvaluations.mockResolvedValue(
    ok({
      months: [
        goalPeriod('2026-07', goalEvaluation()),
        goalPeriod('2026-08', goalEvaluation({ status: 'MET', gap: '100.00' })),
        goalPeriod(
          '2026-09',
          rateEvaluation({
            status: 'IN_PROGRESS',
            gap: '0.05',
            achieved: { ratio: '0.15', percentDisplay: '15 %' },
          }),
        ),
        goalPeriod('2026-02', rateEvaluation({ status: 'MISSED' })),
      ],
      years: [
        goalPeriod(
          '2026',
          goalEvaluation({
            goalId: 'year',
            label: 'Revenus de l’année',
            periodicity: 'YEARLY',
            direction: 'MINIMUM',
            status: 'MISSED',
            target: { value: '20000.00', assetCode: 'EUR' },
          }),
        ),
      ],
    }),
  );
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('AnnualReportTable goals', () => {
  it('asks for the year with both periodicities', async () => {
    renderTable();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    expect(api.listGoalEvaluations.mock.calls[0]![0].query).toEqual({
      from: '2026-01',
      to: '2026-12',
      granularity: 'BOTH',
    });
  });

  it('marks a missed ceiling, an achieved one and a reached running minimum by month', async () => {
    renderTable();
    await screen.findAllByRole('button', { name: /^Objectif :/ });

    const july = rowOf(/juillet/i);
    expect(toneOf(july, 0)).toBe('negative');
    // Only the value and the button: the target lives in the explanation.
    expect(within(july).queryByText(/Plafond|Minimum/)).toBeNull();
    expect(
      within(july).getByRole('button', { name: 'Objectif : Essentiel, Dépassé' }),
    ).toBeTruthy();

    const august = rowOf(/août/i);
    expect(toneOf(august, 0)).toBe('positive');
    expect(
      within(august).getByRole('button', { name: 'Objectif : Essentiel, Atteint' }),
    ).toBeTruthy();

    const september = rowOf(/septembre/i);
    expect(toneOf(september, 1)).toBe('positive');
    expect(within(september).queryByText(/Minimum/)).toBeNull();

    expect(toneOf(rowOf(/février/i), 1)).toBe('negative');
  });

  it('leaves months without a matching goal unchanged', async () => {
    renderTable();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    const march = rowOf(/mars/i);
    expect(toneOf(march, 0)).toBeNull();
    expect(within(march).queryByRole('button')).toBeNull();
    // A monthly goal never marks the total row.
    expect(toneOf(screen.getAllByRole('row').at(-1)!, 0)).toBeNull();
  });

  it('marks the total row with the yearly goal only', async () => {
    renderTable();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    const total = rowOf(/^total/i);
    expect(toneOf(total, 0)).toBe('negative');
    expect(within(total).queryByText(/Minimum/)).toBeNull();
    expect(
      within(total).getByRole('button', { name: 'Objectif : Revenus de l’année, Non atteint' }),
    ).toBeTruthy();
  });

  it('opens the goals panel on the month and goal of the marked cell', async () => {
    window.history.replaceState({}, '', '/budget/2026');
    renderTable();
    fireEvent.click(
      await within(await screen.findByRole('row', { name: /juillet/i })).findByRole('button', {
        name: 'Objectif : Essentiel, Dépassé',
      }),
    );
    const params = new URLSearchParams(window.location.search);
    expect(params.get('panel')).toBe('goals');
    expect(params.get('goal')).toBe('00000000-0000-7000-8000-000000000001');
    expect(params.get('period')).toBe('2026-07');
  });

  it('opens the goals panel on the year for the total row', async () => {
    window.history.replaceState({}, '', '/budget/2026');
    renderTable();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    fireEvent.click(
      within(screen.getByRole('row', { name: /^total/i })).getByRole('button', {
        name: /^Objectif : Revenus/,
      }),
    );
    expect(new URLSearchParams(window.location.search).get('period')).toBe('2026');
  });

  it('renders the figures at once, without marks while the goals load', async () => {
    api.listGoalEvaluations.mockReturnValue(new Promise(() => undefined));
    renderTable();
    expect(within(rowOf(/juillet/i)).getAllByRole('cell')[0]!.textContent).toMatch(/2\s000,50/);
    expect(screen.queryByRole('button', { name: /^Objectif :/ })).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('keeps every figure and shows one retry line when the goals fail', async () => {
    api.listGoalEvaluations.mockResolvedValue({
      data: undefined,
      response: { ok: false, status: 500 },
    });
    renderTable();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Les objectifs n’ont pas pu être vérifiés.');
    expect(within(rowOf(/juillet/i)).getAllByRole('cell')[0]!.textContent).toMatch(/2\s000,50/);
    api.listGoalEvaluations.mockResolvedValue(ok({ months: [], years: [] }));
    fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));
    await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
  });

  it('reads as before the goals when the workspace turned the colouring off', async () => {
    api.readAnnualReportPreferences.mockResolvedValue(ok(preferences(false)));
    renderTable();
    await waitFor(() => expect(api.readAnnualReportPreferences).toHaveBeenCalled());
    expect(await screen.findByRole('row', { name: /juillet/i })).toBeTruthy();
    expect(api.listGoalEvaluations).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: /^Objectif :/ })).toBeNull();
    expect(document.querySelector('[data-tone]')).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('keeps a single `i` on a marked total: the goal one replaces the calculation one', async () => {
    renderTable();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    const cells = within(rowOf(/^total/i)).getAllByRole('cell');
    expect(within(cells[0]!).getAllByRole('button')).toHaveLength(1);
    expect(within(cells[0]!).getByRole('button', { name: /^Objectif :/ })).toBeTruthy();
    expect(within(cells[0]!).queryByRole('button', { name: /^Expliquer les agrégats/ })).toBeNull();
  });
});
