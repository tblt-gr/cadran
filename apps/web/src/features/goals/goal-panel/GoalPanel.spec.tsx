import type { GoalEvaluation, GoalSummary } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { coverageShortfall, defaultPolicy } from '@/features/goals/emergency-fund/fixtures';
import { GoalPanel } from './GoalPanel';
import type { GoalPanelScope } from './useGoalPanelData';

const api = vi.hoisted(() => ({
  listGoals: vi.fn(),
  listGoalEvaluations: vi.fn(),
  createGoal: vi.fn(),
  stopGoal: vi.fn(),
  deleteGoal: vi.fn(),
  readGoal: vi.fn(),
  listGoalVersions: vi.fn(),
  previewGoalSchedule: vi.fn(),
  listAccounts: vi.fn(),
  listCategories: vi.fn(),
  getSession: vi.fn(),
  readEmergencyFundCoverage: vi.fn(),
  readEmergencyFundPolicy: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const ok = <T,>(data: T, status = 200) =>
  Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });

function summary(id: string, label: string, extra: Partial<GoalSummary> = {}): GoalSummary {
  return {
    id,
    label,
    periodicity: 'MONTHLY',
    startPeriod: '2026-01',
    endPeriod: null,
    metric: 'NET_SAVINGS_TRANSFERS',
    direction: 'MINIMUM',
    scope: { type: 'WORKSPACE', label: 'Espace', archived: false },
    assetCode: 'EUR',
    currentVersion: {
      versionNo: 1,
      effectiveFrom: '2026-01',
      valueType: 'AMOUNT',
      targetAmount: '500.00',
      ratio: null,
      schedule: null,
    },
    version: 3,
    warnings: [],
    status: null,
    ...extra,
  };
}
function evaluation(goal: GoalSummary, extra: Partial<GoalEvaluation> = {}): GoalEvaluation {
  return {
    goalId: goal.id,
    label: goal.label,
    periodicity: goal.periodicity,
    metric: goal.metric,
    scopeType: goal.scope.type,
    scopeRef: null,
    direction: goal.direction,
    valueType: 'AMOUNT',
    columnKey: null,
    target: { value: '500.00', assetCode: 'EUR' },
    achieved: { value: '320.00', assetCode: 'EUR' },
    gap: '-180.00',
    status: 'IN_PROGRESS',
    reason: null,
    formula: {
      key: 'goals.formula.amount',
      operands: { target: '500.00', achieved: '320.00', income: null, ratio: null },
    },
    versionNo: 1,
    overrides: [],
    pace: null,
    ...extra,
  };
}
const g1 = summary('00000000-0000-7000-8000-000000000001', 'G1');
const g2 = summary('00000000-0000-7000-8000-000000000002', 'G2', { startPeriod: '2026-08' });
const g3 = summary('00000000-0000-7000-8000-000000000003', 'G3');
const g4 = summary('00000000-0000-7000-8000-000000000004', 'G4', {
  periodicity: 'YEARLY',
  startPeriod: '2026',
});
const cap = summary('00000000-0000-7000-8000-000000000005', 'Plafond courses', {
  direction: 'CEILING',
  metric: 'CATEGORY_SPENDING',
  scope: { type: 'CATEGORY', id: 'c1', label: 'Courses', archived: false },
  startPeriod: '2026-11',
  endPeriod: '2026-11',
});
const rows = (...items: GoalEvaluation[]) => items;

function renderPanel(scope: GoalPanelScope) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={queryClient}>
      <GoalPanel close={vi.fn()} returnFocus={{ current: null }} scope={scope} />
    </QueryClientProvider>,
  );
}
/** Headings render at once; the rows only exist once the skeleton blocks of the lists are gone. */
const loaded = () =>
  waitFor(() =>
    expect(
      screen
        .getByRole('dialog', { name: /^Objectifs/ })
        .querySelector('section li [data-skeleton]'),
    ).toBeNull(),
  );
const november: GoalPanelScope = { kind: 'month', month: '2026-11' };

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-11-15T10:00:00Z'));
  api.getSession.mockReturnValue(
    ok({
      provisioned: true,
      authenticated: true,
      setupRequired: false,
      user: { id: 'u1', email: 'owner@example.test', displayName: 'Owner' },
      workspace: { id: 'w1', role: 'OWNER', timeZone: 'Europe/Paris', baseCurrency: 'EUR' },
    }),
  );
  api.listGoals.mockImplementation(() => ok({ items: [g1, g2, g3, g4, cap] }));
  api.readEmergencyFundCoverage.mockImplementation(() => ok(coverageShortfall));
  api.readEmergencyFundPolicy.mockImplementation(() => ok(defaultPolicy));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  vi.useRealTimers();
});

describe('GoalPanel monthly', () => {
  it('titles the panel with the month and lists only that month, ceilings first', async () => {
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [
          {
            period: '2026-11',
            items: rows(evaluation(g3, { status: 'MISSED' }), evaluation(cap, { status: 'MET' })),
          },
        ],
        years: [{ period: '2026', items: rows(evaluation(g4)) }],
      }),
    );
    renderPanel(november);

    expect(await screen.findByRole('dialog', { name: 'Objectifs · Novembre 2026' })).toBeTruthy();
    await loaded();
    const month = screen.getByRole('region', { name: 'Ce mois-ci' });
    expect(
      within(month)
        .getAllByRole('heading', { level: 4 })
        .map((heading) => heading.textContent),
    ).toEqual(['Plafond courses', 'G3']);
    expect(within(month).getByText('Courses · Novembre 2026 uniquement')).toBeTruthy();
    expect(within(month).getByText('Tout l’espace · Chaque mois depuis janv. 2026')).toBeTruthy();
    expect(screen.queryByText('G4')).toBeNull();
    expect(screen.queryByRole('region', { name: 'Année 2026' })).toBeNull();
  });

  it('words a missed ceiling as exceeded and a missed minimum as not reached, with the figures', async () => {
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [
          {
            period: '2026-11',
            items: rows(
              evaluation(cap, { status: 'MISSED' }),
              evaluation(g3, { status: 'MISSED' }),
              evaluation(g1, { status: 'MET' }),
            ),
          },
        ],
        years: [],
      }),
    );
    renderPanel(november);

    await loaded();
    const month = screen.getByRole('region', { name: 'Ce mois-ci' });
    expect(within(month).getByText('Dépassé')).toBeTruthy();
    expect(within(month).getByText('Non atteint')).toBeTruthy();
    expect(within(month).getByText('Atteint')).toBeTruthy();
    // Each figure also appears in the gap and formula line that explains the row.
    expect(within(month).getAllByText(/500,00/).length).toBe(6);
    expect(within(month).getAllByText(/320,00/).length).toBe(6);
  });

  it('explains a row with its gap, formula and version, and focuses the goal a report pointed at', async () => {
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [
          {
            period: '2026-11',
            items: rows(
              evaluation(cap, { status: 'MISSED', gap: '-50.00' }),
              evaluation(g1, { status: 'MET' }),
            ),
          },
        ],
        years: [],
      }),
    );
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <GoalPanel
          close={vi.fn()}
          focusGoalId={cap.id}
          returnFocus={{ current: null }}
          scope={november}
        />
      </QueryClientProvider>,
    );

    await loaded();
    const row = document.querySelector<HTMLElement>(`[data-goal-id="${cap.id}"]`)!;
    await waitFor(() => expect(document.activeElement).toBe(row));
    expect(row.textContent).toMatch(/Dépassement de 50,00/);
    expect(row.textContent).toMatch(/Version 1/);
    expect(document.querySelector(`[data-goal-id="${g1.id}"]`)).not.toBe(document.activeElement);
  });

  it('shows the winner of August with the goal it replaced, and G3 not reached', async () => {
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [
          {
            period: '2026-08',
            items: rows(
              evaluation(g2, { status: 'MET', overrides: [g1.id] }),
              evaluation(g3, { status: 'MISSED' }),
            ),
          },
        ],
        years: [
          {
            period: '2026',
            items: rows(evaluation(g4, { pace: { expected: '1', status: 'BEHIND' } })),
          },
        ],
      }),
    );
    renderPanel({ kind: 'month', month: '2026-08' });

    await loaded();
    const month = screen.getByRole('region', { name: 'Ce mois-ci' });
    const g2Row = within(month).getByRole('heading', { name: 'G2' }).closest('li')!;
    expect(within(g2Row).getByText('Atteint')).toBeTruthy();
    expect(within(g2Row).getByText('G1 · Remplacé ce mois-ci par G2')).toBeTruthy();
    const g3Row = within(month).getByRole('heading', { name: 'G3' }).closest('li')!;
    expect(within(g3Row).getByText('Non atteint')).toBeTruthy();
    expect(within(month).queryByRole('heading', { name: 'G1' })).toBeNull();
  });

  it('shows label, pill and menu on line one, then a named progress bar with achieved / target', async () => {
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [{ period: '2026-11', items: rows(evaluation(g1, { status: 'MISSED' })) }],
        years: [],
      }),
    );
    renderPanel(november);
    await loaded();

    const row = screen.getByRole('heading', { name: 'G1' }).closest('li')!;
    const head = row.firstElementChild as HTMLElement;
    expect(within(head).getByText('Non atteint')).toBeTruthy();
    expect(within(head).getByRole('button', { name: 'Actions de l’objectif « G1 »' })).toBeTruthy();
    const bar = within(row).getByRole('meter', { name: /Avancement de l’objectif « G1 »/ });
    expect(bar.getAttribute('aria-valuetext')).toMatch(/320,00.*sur.*500,00/);
    expect(row.getAttribute('data-tone')).toBe('negative');
    expect(row.textContent).toMatch(/320,00.*\/.*500,00/);
    expect(within(row).getByText('Tout l’espace · Chaque mois depuis janv. 2026')).toBeTruthy();
  });

  it.each([
    ['MET', 'MINIMUM', '0.00', 'done'],
    ['MET', 'CEILING', '10.00', 'done'],
    ['MISSED', 'MINIMUM', '-60.00', 'negative'],
    ['MISSED', 'CEILING', '-5.00', 'negative'],
    ['IN_PROGRESS', 'MINIMUM', '-60.00', 'negative'],
    ['IN_PROGRESS', 'MINIMUM', '10.00', 'positive'],
    ['IN_PROGRESS', 'CEILING', '10.00', 'positive'],
  ] as const)(
    'shows %s %s (gap %s) as %s, greyed only when done',
    async (status, direction, gap, tone) => {
      const goal = summary('00000000-0000-7000-8000-0000000000aa', 'GX', { direction });
      api.listGoals.mockImplementation(() => ok({ items: [goal] }));
      api.listGoalEvaluations.mockImplementation(() =>
        ok({
          months: [{ period: '2026-11', items: rows(evaluation(goal, { status, gap })) }],
          years: [],
        }),
      );
      renderPanel(november);
      await loaded();
      const row = screen.getByRole('heading', { name: 'GX' }).closest('li')!;
      expect(row.getAttribute('data-tone')).toBe(tone);
    },
  );

  it('keeps a running minimum under its target red, with the plain "En cours" pill', async () => {
    const goal = summary('00000000-0000-7000-8000-0000000000ab', 'Épargne', {});
    api.listGoals.mockImplementation(() => ok({ items: [goal] }));
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [
          {
            period: '2026-11',
            items: rows(
              evaluation(goal, {
                gap: '-60.00',
                target: { value: '800.00', assetCode: 'EUR' },
                achieved: { value: '740.00', assetCode: 'EUR' },
              }),
            ),
          },
        ],
        years: [],
      }),
    );
    renderPanel(november);
    await loaded();
    const row = screen.getByRole('heading', { name: 'Épargne' }).closest('li')!;
    expect(row.getAttribute('data-tone')).toBe('negative');
    expect(within(row).getByText('En cours')).toBeTruthy();
    expect(within(row).getByRole('meter').getAttribute('aria-valuetext')).toMatch(/740,00.*800,00/);
  });

  it('draws no bar and shows empty markers when the goal is not calculable', async () => {
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [
          {
            period: '2026-11',
            items: rows(evaluation(g1, { status: 'NOT_CALCULABLE', target: null, achieved: null })),
          },
        ],
        years: [],
      }),
    );
    renderPanel(november);
    await loaded();
    const row = screen.getByRole('heading', { name: 'G1' }).closest('li')!;
    expect(within(row).queryByRole('meter')).toBeNull();
    expect(within(row).getAllByText('Valeur non définie')).toHaveLength(2);
  });

  it('explains a non calculable goal with its reason, as text', async () => {
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [
          {
            period: '2026-11',
            items: rows(
              evaluation(g1, {
                status: 'NOT_CALCULABLE',
                reason: 'NO_REFERENCE_INCOME',
                target: null,
                achieved: null,
              }),
            ),
          },
        ],
        years: [],
      }),
    );
    renderPanel(november);

    expect(await screen.findByText('Non calculable')).toBeTruthy();
    expect(screen.getByText('Le revenu de référence est indisponible')).toBeTruthy();
    expect(screen.getAllByText('Valeur non définie').length).toBeGreaterThan(0);
  });

  it('says so when the month has no goal, and keeps the footer, without the emergency fund', async () => {
    api.listGoalEvaluations.mockImplementation(() => ok({ months: [], years: [] }));
    renderPanel(november);

    expect(await screen.findByText('Aucun objectif pour ce mois.')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Nouvel objectif' })).toBeTruthy();
    expect(screen.queryByRole('heading', { name: 'Fonds d’urgence' })).toBeNull();
  });

  it('shows the real headings and footer over three skeleton rows while loading', async () => {
    api.listGoalEvaluations.mockImplementation(() => new Promise(() => undefined));
    renderPanel(november);

    expect(screen.getByRole('heading', { name: 'Ce mois-ci' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Nouvel objectif' }).hasAttribute('disabled')).toBe(
      false,
    );
    for (const name of ['Ce mois-ci']) {
      const section = screen.getByRole('region', { name });
      expect(section.querySelectorAll(':scope > ul > li')).toHaveLength(3);
      expect(section.querySelectorAll('li [data-skeleton]').length).toBeGreaterThan(0);
      expect(within(section).queryByText(/Atteint|En cours|Non atteint/)).toBeNull();
    }
    expect(screen.getAllByText('Chargement des objectifs…')).toHaveLength(1);
  });

  it('says the goals are unavailable, retries, and keeps the footer usable', async () => {
    api.listGoalEvaluations.mockRejectedValueOnce(new TypeError('offline'));
    renderPanel(november);

    expect(await screen.findByText('Les objectifs sont indisponibles.')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Nouvel objectif' })).toBeTruthy();

    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [{ period: '2026-11', items: rows(evaluation(g1, { status: 'MET' })) }],
        years: [],
      }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByRole('heading', { name: 'G1' })).toBeTruthy();
    expect(screen.queryByText('Les objectifs sont indisponibles.')).toBeNull();
  });

  it('opens the form prefilled monthly, from the displayed month, without an end, and adds the row', async () => {
    let created = false;
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [{ period: '2026-11', items: created ? rows(evaluation(g1)) : [] }],
        years: [],
      }),
    );
    api.createGoal.mockImplementation(() => {
      created = true;
      return ok(g1, 201);
    });
    renderPanel(november);
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvel objectif' }));

    expect((screen.getByRole('radio', { name: 'Mensuel' }) as HTMLInputElement).checked).toBe(true);
    expect((screen.getByLabelText('Début') as HTMLInputElement).value).toBe('2026-11');
    expect((screen.getByLabelText('Sans fin') as HTMLInputElement).checked).toBe(true);

    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'G1' } });
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '500.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    expect(await screen.findByRole('heading', { name: 'G1' })).toBeTruthy();
    expect(api.listGoalEvaluations.mock.calls.length).toBeGreaterThan(1);
    expect(await screen.findByText('L’objectif a été enregistré.')).toBeTruthy();
  });
});

describe('GoalPanel yearly', () => {
  it('words the precedence line of a yearly row with the year', async () => {
    const g5 = summary('00000000-0000-7000-8000-000000000006', 'G5', {
      periodicity: 'YEARLY',
      startPeriod: '2026',
    });
    api.listGoals.mockImplementation(() => ok({ items: [g4, g5] }));
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [],
        years: [{ period: '2026', items: rows(evaluation(g5, { overrides: [g4.id] })) }],
      }),
    );
    renderPanel({ kind: 'year', year: 2026 });

    expect(await screen.findByText('G4 · Remplacé cette année par G5')).toBeTruthy();
  });

  it('lists the yearly goals with their pace, and no monthly goal', async () => {
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [],
        years: [
          {
            period: '2026',
            items: rows(evaluation(g4, { pace: { expected: '1000.00', status: 'BEHIND' } })),
          },
        ],
      }),
    );
    renderPanel({ kind: 'year', year: 2026 });

    expect(await screen.findByRole('dialog', { name: 'Objectifs · 2026' })).toBeTruthy();
    await loaded();
    const yearly = screen.getByRole('region', { name: 'Objectifs annuels' });
    expect(within(yearly).getByText('En cours')).toBeTruthy();
    expect(within(yearly).getByText('En retard')).toBeTruthy();
    expect(screen.queryByRole('region', { name: 'Objectifs mensuels' })).toBeNull();
    expect(screen.queryByText(/Tenu \d/)).toBeNull();
  });

  it('says so when the year has no goal, and opens the form on the year', async () => {
    api.listGoalEvaluations.mockImplementation(() => ok({ months: [], years: [] }));
    renderPanel({ kind: 'year', year: 2026 });

    expect(await screen.findByText('Aucun objectif pour cette année.')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Nouvel objectif' }));
    expect((screen.getByRole('radio', { name: 'Annuel' }) as HTMLInputElement).checked).toBe(true);
    expect((screen.getByLabelText('Début') as HTMLInputElement).value).toBe('2026');
  });
});

describe('GoalPanel row actions', () => {
  function monthlyWithG1() {
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [{ period: '2026-11', items: rows(evaluation(g1, { status: 'MET' })) }],
        years: [{ period: '2026', items: rows(evaluation(g4)) }],
      }),
    );
  }
  async function choose(label: string, goal: string) {
    fireEvent.click(
      await screen.findByRole('button', { name: `Actions de l’objectif « ${goal} »` }),
    );
    fireEvent.click(screen.getByRole('button', { name: label }));
  }

  it('offers edit, history, stop and delete in the row menu', async () => {
    monthlyWithG1();
    renderPanel(november);
    fireEvent.click(await screen.findByRole('button', { name: 'Actions de l’objectif « G1 »' }));
    const menu = document.getElementById(
      screen
        .getByRole('button', { name: 'Actions de l’objectif « G1 »' })
        .getAttribute('aria-controls') ?? '',
    )!;
    expect(
      within(menu)
        .getAllByRole('button')
        .map((item) => item.getAttribute('aria-label')),
    ).toEqual([
      'Modifier l’objectif « G1 »',
      'Historique de l’objectif « G1 »',
      'Arrêter l’objectif « G1 »',
      'Supprimer l’objectif « G1 »',
    ]);
  });

  it('stops a monthly goal at the month before the displayed one', async () => {
    monthlyWithG1();
    api.stopGoal.mockImplementation(() => ok(g1));
    renderPanel(november);
    await choose('Arrêter l’objectif « G1 »', 'G1');

    const dialog = screen.getByRole('dialog', { name: 'Arrêter l’objectif « G1 »' });
    expect((within(dialog).getByLabelText('Dernier mois concerné') as HTMLInputElement).value).toBe(
      '2026-10',
    );
    fireEvent.click(within(dialog).getByRole('button', { name: 'Arrêter' }));
    await waitFor(() =>
      expect(api.stopGoal).toHaveBeenCalledWith(
        expect.objectContaining({
          path: { id: g1.id },
          body: { expectedVersion: 3, lastPeriod: '2026-10' },
        }),
      ),
    );
    await waitFor(() => expect(screen.queryByRole('dialog', { name: /^Arrêter/ })).toBeNull());
  });

  it('defaults a yearly goal to stop at the previous year', async () => {
    monthlyWithG1();
    renderPanel({ kind: 'year', year: 2026 });
    await choose('Arrêter l’objectif « G4 »', 'G4');

    const dialog = screen.getByRole('dialog', { name: 'Arrêter l’objectif « G4 »' });
    expect(
      (within(dialog).getByLabelText('Dernière année concernée') as HTMLInputElement).value,
    ).toBe('2025');
  });

  it('deletes a goal against its version', async () => {
    monthlyWithG1();
    api.deleteGoal.mockImplementation(() =>
      Promise.resolve({ response: new Response(null, { status: 204 }) }),
    );
    renderPanel(november);
    await choose('Supprimer l’objectif « G1 »', 'G1');
    fireEvent.click(
      within(screen.getByRole('dialog', { name: 'Supprimer l’objectif « G1 »' })).getByRole(
        'button',
        { name: 'Supprimer' },
      ),
    );

    await waitFor(() =>
      expect(api.deleteGoal).toHaveBeenCalledWith(
        expect.objectContaining({
          path: { id: g1.id },
          headers: expect.objectContaining({ 'If-Match': '3' }),
        }),
      ),
    );
  });

  it('refuses to delete a goal covering a closed month and offers to stop it instead', async () => {
    monthlyWithG1();
    api.deleteGoal.mockImplementation(() =>
      Promise.resolve({
        error: {
          type: '/problems/goal_covers_closed_period',
          title: 'Conflict',
          status: 409,
          code: 'goal_covers_closed_period',
        },
        response: new Response(null, { status: 409 }),
      }),
    );
    renderPanel(november);
    await choose('Supprimer l’objectif « G1 »', 'G1');
    fireEvent.click(
      within(screen.getByRole('dialog', { name: 'Supprimer l’objectif « G1 »' })).getByRole(
        'button',
        { name: 'Supprimer' },
      ),
    );

    expect(
      await screen.findByText('Cet objectif couvre un mois clôturé : arrêtez-le plutôt.'),
    ).toBeTruthy();
    const dialog = screen.getByRole('dialog', { name: 'Supprimer l’objectif « G1 »' });
    expect(within(dialog).queryByRole('button', { name: 'Supprimer' })).toBeNull();
    await waitFor(() =>
      expect(document.activeElement).toBe(within(dialog).getByRole('button', { name: 'Arrêter' })),
    );
    fireEvent.click(within(dialog).getByRole('button', { name: 'Arrêter' }));
    expect(await screen.findByRole('dialog', { name: 'Arrêter l’objectif « G1 »' })).toBeTruthy();
  });

  it('reloads the goal after a stale version so the retry deletes the current version', async () => {
    monthlyWithG1();
    api.deleteGoal
      .mockImplementationOnce(() =>
        Promise.resolve({
          error: {
            type: 'about:blank',
            title: 'Conflict',
            status: 409,
            code: 'stale_goal_version',
          },
          response: new Response(null, { status: 409 }),
        }),
      )
      .mockImplementation(() => Promise.resolve({ response: new Response(null, { status: 204 }) }));
    renderPanel(november);
    await choose('Supprimer l’objectif « G1 »', 'G1');
    const dialog = screen.getByRole('dialog', { name: 'Supprimer l’objectif « G1 »' });
    api.listGoals.mockImplementation(() => ok({ items: [{ ...g1, version: 4 }, g4] }));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Supprimer' }));

    await waitFor(() => expect(api.deleteGoal).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(api.listGoals.mock.calls.length).toBeGreaterThan(1));
    await waitFor(() => expect(within(dialog).getByRole('alert')).toBeTruthy());
    fireEvent.click(within(dialog).getByRole('button', { name: 'Supprimer' }));
    await waitFor(() => expect(api.deleteGoal).toHaveBeenCalledTimes(2));
    expect(api.deleteGoal.mock.calls[1]![0].headers['If-Match']).toBe('4');
  });

  it('opens the version history of the goal', async () => {
    monthlyWithG1();
    api.listGoalVersions.mockImplementation(() => ok({ items: [] }));
    renderPanel(november);
    await choose('Historique de l’objectif « G1 »', 'G1');

    expect(await screen.findByRole('dialog', { name: 'Historique des versions' })).toBeTruthy();
    await waitFor(() => expect(api.listGoalVersions).toHaveBeenCalled());
  });
});

describe('GoalPanel multi-year', () => {
  const g5 = summary('00000000-0000-7000-8000-000000000006', 'G5', {
    periodicity: 'YEARLY',
    startPeriod: '2024',
  });
  /** One answer per requested year, as the server gives for a range of a single year. */
  function answerPerYear(byYear: Record<string, GoalEvaluation[]>) {
    api.listGoals.mockImplementation(() => ok({ items: [g4, g5] }));
    api.listGoalEvaluations.mockImplementation(({ query }: { query: { from: string } }) => {
      const year = query.from.slice(0, 4);
      return ok({ months: [], years: [{ period: year, items: byYear[year] ?? [] }] });
    });
  }

  const yearItem = (year: string) =>
    screen.getByText(year, { selector: 'summary span span' }).closest('details')!;

  it('asks for one year per request and lists one accordion item per year, the last one open', async () => {
    answerPerYear({
      '2024': [evaluation(g5, { status: 'MET' })],
      '2025': [],
      '2026': [
        evaluation(g4, { status: 'IN_PROGRESS', gap: '-5.00' }),
        evaluation(g5, { status: 'MET' }),
      ],
    });
    renderPanel({ kind: 'years', years: [2024, 2025, 2026] });

    expect(await screen.findByRole('dialog', { name: 'Objectifs · 2024 à 2026' })).toBeTruthy();
    await loaded();
    expect(api.listGoalEvaluations.mock.calls.map(([options]) => options.query)).toEqual([
      { from: '2024-01', to: '2024-12', granularity: 'BOTH' },
      { from: '2025-01', to: '2025-12', granularity: 'BOTH' },
      { from: '2026-01', to: '2026-12', granularity: 'BOTH' },
    ]);
    expect(yearItem('2024').open).toBe(false);
    expect(yearItem('2025').open).toBe(false);
    expect(yearItem('2026').open).toBe(true);
    // Year on the left, goals on target out of yearly goals at the far end.
    expect(yearItem('2024').querySelector('summary')!.textContent).toBe('20241 / 1');
    expect(yearItem('2026').querySelector('summary')!.textContent).toBe('20261 / 2');
    expect(within(yearItem('2026')).getAllByRole('heading', { level: 4 })).toHaveLength(2);
    // No goal: the year alone, and the empty text inside.
    expect(yearItem('2025').querySelector('summary')!.textContent).toBe('2025');
    expect(
      within(yearItem('2025')).getByText('Aucun objectif annuel pour cette année.'),
    ).toBeTruthy();
  });

  it('opens a closed year from its summary', async () => {
    answerPerYear({ '2024': [evaluation(g5, { status: 'MET' })] });
    renderPanel({ kind: 'years', years: [2024, 2026] });
    await loaded();
    expect(yearItem('2024').open).toBe(false);

    fireEvent.click(yearItem('2024').querySelector('summary')!);
    // jsdom does not toggle a details element on click: the toggle event is what the panel reads.
    yearItem('2024').open = true;
    fireEvent(yearItem('2024'), new Event('toggle'));
    await waitFor(() => expect(yearItem('2024').open).toBe(true));
  });

  it.each([
    [[2026], 'Objectifs · 2026'],
    [[2024, 2026], 'Objectifs · 2024, 2026'],
    [[2025, 2026], 'Objectifs · 2025 à 2026'],
  ])('titles the panel for the years %j', async (years, title) => {
    answerPerYear({});
    renderPanel({ kind: 'years', years });
    expect(await screen.findByRole('dialog', { name: title })).toBeTruthy();
  });

  it('opens the form prefilled yearly, on the last selected year, without an end', async () => {
    answerPerYear({});
    renderPanel({ kind: 'years', years: [2024, 2025] });
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvel objectif' }));

    expect((screen.getByRole('radio', { name: 'Annuel' }) as HTMLInputElement).checked).toBe(true);
    expect((screen.getByLabelText('Début') as HTMLInputElement).value).toBe('2025');
    expect((screen.getByLabelText('Sans fin') as HTMLInputElement).checked).toBe(true);
  });

  it('defaults a stop to the year before the section it was opened from', async () => {
    answerPerYear({ '2024': [evaluation(g5, { status: 'MET' })], '2025': [], '2026': [] });
    renderPanel({ kind: 'years', years: [2024, 2025, 2026] });
    fireEvent.click(await screen.findByRole('button', { name: 'Actions de l’objectif « G5 »' }));
    fireEvent.click(screen.getByRole('button', { name: 'Arrêter l’objectif « G5 »' }));

    const dialog = screen.getByRole('dialog', { name: 'Arrêter l’objectif « G5 »' });
    expect(
      (within(dialog).getByLabelText('Dernière année concernée') as HTMLInputElement).value,
    ).toBe('2023');
  });

  it('keeps every year summary real over skeleton values, the open one over three rows', () => {
    api.listGoals.mockImplementation(() => new Promise(() => undefined));
    api.listGoalEvaluations.mockImplementation(() => new Promise(() => undefined));
    renderPanel({ kind: 'years', years: [2025, 2026] });

    for (const year of ['2025', '2026']) {
      const summary = yearItem(year).querySelector('summary')!;
      expect(summary.textContent).toBe(year);
      expect(summary.querySelector('[data-skeleton]')).toBeTruthy();
    }
    expect(yearItem('2026').open).toBe(true);
    expect(yearItem('2026').querySelectorAll(':scope > div > ul > li')).toHaveLength(3);
  });

  it('says the goals are unavailable when one year fails', async () => {
    api.listGoals.mockImplementation(() => ok({ items: [] }));
    api.listGoalEvaluations.mockImplementation(({ query }: { query: { from: string } }) =>
      query.from.startsWith('2025')
        ? Promise.reject(new TypeError('offline'))
        : ok({ months: [], years: [] }),
    );
    renderPanel({ kind: 'years', years: [2025, 2026] });
    expect(await screen.findByText('Les objectifs sont indisponibles.')).toBeTruthy();
  });
});

describe('GoalPanel data cost', () => {
  it('reuses fresh evaluations when the panel is reopened, and asks for the goals list when opened', async () => {
    api.listGoalEvaluations.mockImplementation(() =>
      ok({ months: [{ period: '2026-11', items: rows(evaluation(g1)) }], years: [] }),
    );
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    const panel = (
      <QueryClientProvider client={queryClient}>
        <GoalPanel close={vi.fn()} returnFocus={{ current: null }} scope={november} />
      </QueryClientProvider>
    );
    const first = render(panel);
    await loaded();
    expect(api.listGoals).toHaveBeenCalledTimes(1);
    first.unmount();

    render(panel);
    await loaded();
    expect(api.listGoalEvaluations).toHaveBeenCalledTimes(1);

    await queryClient.invalidateQueries({ queryKey: ['goal-evaluations'] });
    await waitFor(() => expect(api.listGoalEvaluations).toHaveBeenCalledTimes(2));
  });
});

describe('GoalPanel summaries', () => {
  it('finds the summary of a recent goal that sits beyond the first page of the goals list', async () => {
    const old = Array.from({ length: 100 }, (_, index) =>
      summary(
        `00000000-0000-7000-8000-${String(index + 1000).padStart(12, '0')}`,
        `Ancien ${index}`,
      ),
    );
    const recent = summary('00000000-0000-7000-8000-000000009999', 'Récent');
    api.listGoals.mockImplementation(({ query }: { query: { page: number } }) =>
      ok({
        items: query.page === 1 ? old : [recent],
        page: query.page,
        pageSize: 100,
        total: 101,
      }),
    );
    api.listGoalEvaluations.mockImplementation(() =>
      ok({
        months: [{ period: '2026-11', items: rows(evaluation(recent, { status: 'MET' })) }],
        years: [],
      }),
    );
    renderPanel(november);
    await loaded();

    expect(api.listGoals).toHaveBeenCalledTimes(2);
    expect(screen.getByRole('button', { name: 'Actions de l’objectif « Récent »' })).toBeTruthy();
    expect(screen.getByText('Tout l’espace · Chaque mois depuis janv. 2026')).toBeTruthy();
  });
});
