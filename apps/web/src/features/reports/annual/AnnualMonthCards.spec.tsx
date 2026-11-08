import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { goalEvaluation, goalPeriod } from '@/features/goals/goal-mark/goalEvaluationFixtures';
import { annualReport } from './annualFixtures';
import { AnnualMonthCards } from './AnnualMonthCards';

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

function renderCards() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AnnualMonthCards onExplain={() => undefined} report={annualReport} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  api.readAnnualReportPreferences.mockResolvedValue(ok(preferences(true)));
  api.listGoalEvaluations.mockResolvedValue(
    ok({
      months: [goalPeriod('2026-07', goalEvaluation())],
      years: [
        goalPeriod(
          '2026',
          goalEvaluation({ goalId: 'year', label: 'Revenus annuels', periodicity: 'YEARLY' }),
        ),
      ],
    }),
  );
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('AnnualMonthCards goals', () => {
  it('marks the month card and the total of the summary card, nothing else', async () => {
    renderCards();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    const july = screen.getByRole('heading', { name: /juillet/i }).closest('li')!;
    // Only the value and the button: the target lives in the explanation.
    expect(within(july).queryByText(/Plafond|Minimum/)).toBeNull();
    expect(
      within(july).getByRole('button', { name: 'Objectif : Essentiel, Dépassé' }),
    ).toBeTruthy();
    expect(july.querySelector('[data-tone]')?.getAttribute('data-tone')).toBe('negative');
    const august = screen.getByRole('heading', { name: /août/i }).closest('li')!;
    expect(within(august).queryByRole('button')).toBeNull();
    const summary = screen.getByRole('heading', { name: 'Synthèse de l’année' }).closest('li')!;
    expect(
      within(summary).getAllByRole('button', { name: /^Objectif : Revenus annuels/ }),
    ).toHaveLength(1);
  });

  it('opens the goals panel and shows one retry line when the goals fail', async () => {
    window.history.replaceState({}, '', '/budget/2026');
    renderCards();
    fireEvent.click(
      (await screen.findAllByRole('button', { name: 'Objectif : Essentiel, Dépassé' }))[0]!,
    );
    expect(new URLSearchParams(window.location.search).get('period')).toBe('2026-07');
    cleanup();
    api.listGoalEvaluations.mockResolvedValue({
      data: undefined,
      response: { ok: false, status: 500 },
    });
    renderCards();
    expect((await screen.findByRole('alert')).textContent).toContain('pas pu être vérifiés');
  });

  it('reads as before the goals when the workspace turned the colouring off', async () => {
    api.readAnnualReportPreferences.mockResolvedValue(ok(preferences(false)));
    renderCards();
    await waitFor(() => expect(api.readAnnualReportPreferences).toHaveBeenCalled());
    expect(api.listGoalEvaluations).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: /^Objectif :/ })).toBeNull();
    expect(document.querySelector('[data-tone]')).toBeNull();
  });
});
