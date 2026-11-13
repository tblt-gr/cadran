import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { goalEvaluation, goalPeriod } from '@/features/goals/goal-mark/goalEvaluationFixtures';
import type { AnnualReport } from '@cadran/api-client';
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

function renderCards(report: AnnualReport = annualReport) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AnnualMonthCards onExplain={() => undefined} report={report} />
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

describe('AnnualMonthCards collapsible months', () => {
  const detailsOf = (month: RegExp) =>
    screen.getByRole('heading', { name: month }).closest('details') as HTMLDetailsElement;

  it('renders each month with data closed, the first column as its meta', async () => {
    renderCards();
    await screen.findAllByRole('button', { name: /^Objectif :/ });
    const july = detailsOf(/juillet/i);
    expect(july.open).toBe(false);
    expect(within(july.querySelector('summary')!).getByText(/2\s?000,50/)).toBeTruthy();
  });

  it('keeps the whole detail of a month in the card once opened', async () => {
    renderCards();
    const july = detailsOf(/juillet/i);
    fireEvent.click(july.querySelector('summary')!);
    expect(july.open).toBe(true);
    expect(within(july).getByRole('link', { name: /2\s?000,50/ })).toBeTruthy();
    expect(within(july).getByText(/Taux d’épargne/)).toBeTruthy();
  });

  it('does not collapse a future month', () => {
    renderCards();
    const december = screen.getByRole('heading', { name: /décembre/i }).closest('li')!;
    expect(december.querySelector('details')).toBeNull();
  });

  it('shows an empty value, never zero, when the first column is not computable', () => {
    const report = structuredClone(annualReport);
    report.rows[0]!.cells.cashIncome = { value: null, reason: 'ZERO_CASH_INCOME' };
    renderCards(report);
    const summary = detailsOf(/janvier/i).querySelector('summary')!;
    expect(summary.textContent).not.toMatch(/0,00/);
    expect(within(summary).getByRole('img', { name: /calculable/i })).toBeTruthy();
  });

  it('copes with a report without selected columns', () => {
    renderCards({ ...annualReport, columns: [] });
    expect(detailsOf(/juillet/i).querySelector('summary')).toBeTruthy();
  });

  it('keeps the year summary as a plain card', () => {
    renderCards();
    const summary = screen.getByRole('heading', { name: 'Synthèse de l’année' }).closest('li')!;
    expect(summary.querySelector('details')).toBeNull();
  });
});
