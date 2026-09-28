import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GoalsPage } from './GoalsPage';

const api = vi.hoisted(() => ({
  listGoals: vi.fn(),
  createGoal: vi.fn(),
  updateGoal: vi.fn(),
  closeGoal: vi.fn(),
  readGoal: vi.fn(),
  readGoalStatus: vi.fn(),
  previewGoalSchedule: vi.fn(),
  listAccounts: vi.fn(),
  listCategories: vi.fn(),
  getSession: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const ok = <T,>(data: T, status = 200) =>
  Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
const goal = {
  id: '00000000-0000-7000-8000-000000000001',
  label: 'Épargne',
  kind: 'ANNUAL' as const,
  metric: 'NET_SAVINGS_TRANSFERS' as const,
  direction: 'MINIMUM' as const,
  scope: { type: 'WORKSPACE' as const, label: 'Espace', archived: false },
  assetCode: 'EUR',
  state: 'ACTIVE' as const,
  currentVersion: {
    versionNo: 1,
    effectiveFrom: '2026-09',
    year: 2026,
    targetAmount: '12000.00',
    ratio: null,
  },
  version: 1,
  warnings: [] as 'REFERENCE_ARCHIVED'[],
};
/**
 * Real browsers dispatch a click when Enter/Space activates a focused button; jsdom does not
 * implement that default action, so this asserts the keyboard-reachable part (focus) and then
 * fires the same event sequence a keyboard activation produces, instead of a bare mouse click.
 */
function activateWithKeyboard(element: HTMLElement) {
  element.focus();
  expect(document.activeElement).toBe(element);
  fireEvent.keyDown(element, { key: 'Enter' });
  fireEvent.click(element);
  fireEvent.keyUp(element, { key: 'Enter' });
}
function renderPage() {
  return render(
    <QueryClientProvider
      client={
        new QueryClient({
          defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
        })
      }
    >
      <GoalsPage />
    </QueryClientProvider>,
  );
}
describe('GoalsPage', () => {
  // The page derives its default year and month from today's date, and the list mock mirrors a
  // server that rejects months after 2026-09: freezing only `Date` keeps the suite independent of
  // the real calendar while leaving the timers React Query and waitFor rely on untouched.
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-09-15T10:00:00Z'));
  });
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    vi.useRealTimers();
  });
  function mockSession() {
    api.getSession.mockReturnValue(
      ok({
        provisioned: true,
        authenticated: true,
        setupRequired: false,
        user: { id: 'u1', email: 'owner@example.test', displayName: 'Owner' },
        workspace: { id: 'w1', role: 'OWNER', timeZone: 'Europe/Paris', baseCurrency: 'EUR' },
      }),
    );
  }
  it('shows the empty active state and creates an annual exact goal in the shared modal', async () => {
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [] }));
    api.createGoal.mockReturnValue(ok(goal, 201));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvel objectif' }));
    expect(screen.getByRole('dialog', { name: 'Nouvel objectif' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Épargne' } });
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '12000.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.createGoal).toHaveBeenCalledWith(
        expect.objectContaining({
          body: expect.objectContaining({
            label: 'Épargne',
            targetAmount: { value: '12000.00', assetCode: 'EUR' },
          }),
        }),
      ),
    );
  });
  it('renders an accessible provisional status and opens its keyboard-usable monthly detail', async () => {
    mockSession();
    const status = {
      goalId: goal.id,
      month: '2026-09',
      kind: 'ANNUAL' as const,
      direction: 'MINIMUM' as const,
      goalVersion: 1,
      scheduleMode: 'LINEAR' as const,
      provisional: true,
      metricPolicy: { state: 'SINGLE' as const, version: 1, versions: [1] },
      target: '12000',
      achieved: '9000',
      expected: '9000',
      gap: '0',
      remaining: '3000',
      excess: '0',
      status: 'AHEAD' as const,
      progressRatio: '0.75',
      progressRatioDisplay: '0.75',
      reason: null,
      quality: 'PROVISIONAL' as const,
    };
    api.listGoals.mockReturnValue(ok({ items: [{ ...goal, status }] }));
    api.readGoalStatus.mockReturnValue(
      ok({
        ...status,
        months: [
          {
            month: '2026-09',
            state: 'PROVISIONAL',
            value: '1000',
            expectedPart: '1000',
            cumulativeValue: '9000',
            cumulativeExpected: '9000',
            goalVersion: 1,
            policyVersion: 1,
          },
        ],
      }),
    );
    renderPage();
    expect(await screen.findByText('En avance')).toBeTruthy();
    expect(screen.getByRole('meter')).toBeTruthy();
    expect(screen.getByText('Provisoire')).toBeTruthy();
    activateWithKeyboard(screen.getByRole('button', { name: 'Détail mensuel' }));
    expect(await screen.findByRole('dialog', { name: 'Détail de l’objectif' })).toBeTruthy();
    expect((await screen.findByRole('link', { name: 'septembre 2026' })).getAttribute('href')).toBe(
      '/budget/2026-09',
    );
  });
  it('switches to closed goals and opens the close confirmation from the accessible action menu', async () => {
    mockSession();
    api.listGoals.mockImplementation(({ query }) =>
      ok({ items: query.state === 'closed' ? [] : [goal] }),
    );
    renderPage();
    expect(await screen.findByText('Épargne')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Actions de l’objectif « Épargne »' }));
    fireEvent.click(screen.getByRole('button', { name: 'Clôturer l’objectif « Épargne »' }));
    expect(screen.getByRole('dialog', { name: 'Clôturer l’objectif' })).toBeTruthy();
    fireEvent.click(screen.getByLabelText('Clôturés'));
    expect(
      await screen.findByRole('heading', { name: 'Aucun objectif clôturé pour cette année' }),
    ).toBeTruthy();
  });
  it('marks a closed goal with a labelled badge instead of an inert action menu', async () => {
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [{ ...goal, state: 'CLOSED' as const }] }));
    renderPage();
    expect(await screen.findByText('Clôturé')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Actions de l’objectif « Épargne »' })).toBeNull();
    expect(screen.getByText('Année 2026')).toBeTruthy();
  });
  it('shows a not-calculable annual schedule state when an older annual goal has no schedule', async () => {
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [goal] }));
    renderPage();
    expect(await screen.findByText('Aucun calendrier mensuel pour cet objectif.')).toBeTruthy();
  });
  it('flags only the invalid field when an annual goal is submitted without a label', async () => {
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [] }));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvel objectif' }));
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '5000.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(screen.getByLabelText('Libellé').getAttribute('aria-invalid')).toBe('true');
    expect(screen.getByLabelText('Montant cible').getAttribute('aria-invalid')).toBeNull();
    expect(api.createGoal).not.toHaveBeenCalled();
  });
  it('surfaces the exact schedule difference returned by the server in the goal modal', async () => {
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [] }));
    api.createGoal.mockReturnValue(
      Promise.resolve({
        error: {
          type: '/problems/schedule_sum_mismatch',
          title: 'Unprocessable',
          status: 422,
          detail: 'sum mismatch',
          code: 'schedule_sum_mismatch',
          expected: '2000.00',
          actual: '1200.00',
          difference: '800.00',
        },
        response: new Response(null, { status: 422 }),
      }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvel objectif' }));
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Épargne' } });
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '2000.00' } });
    fireEvent.click(screen.getByRole('radio', { name: 'Personnalisé' }));
    for (const input of screen.getAllByRole('textbox', { name: /Montant de / })) {
      fireEvent.change(input, { target: { value: '100.00' } });
    }
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    expect((await screen.findByRole('alert')).textContent).toBe(
      'La somme des douze montants ne correspond pas exactement au montant cible. Écart retourné par le serveur : 800,00 €.',
    );
  });
  it('does not restore a stale preview after switching an annual draft to rolling and back', async () => {
    let resolvePreview: (value: unknown) => void;
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [] }));
    api.previewGoalSchedule.mockReturnValue(new Promise((resolve) => (resolvePreview = resolve)));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvel objectif' }));
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '2000.00' } });
    fireEvent.click(screen.getByRole('radio', { name: 'Personnalisé' }));
    fireEvent.click(screen.getByRole('button', { name: 'Répartir linéairement' }));
    fireEvent.click(screen.getByRole('radio', { name: 'Ratio glissant' }));
    resolvePreview!({
      data: {
        mode: 'LINEAR',
        parts: Array.from({ length: 12 }, (_, monthIndex) => ({
          monthIndex: monthIndex + 1,
          amount: '166.67',
          cumulative: '0',
        })),
      },
      response: new Response(null, { status: 200 }),
    });
    fireEvent.click(screen.getByRole('radio', { name: 'Annuel' }));
    fireEvent.click(screen.getByRole('radio', { name: 'Personnalisé' }));

    await waitFor(() =>
      expect((screen.getByLabelText('Montant de janvier') as HTMLInputElement).value).toBe(''),
    );
  });
  it('shows rolling reference income without coercing its exact amount to a number', async () => {
    mockSession();
    const rolling = {
      ...goal,
      id: '00000000-0000-7000-8000-000000000002',
      kind: 'ROLLING_RATIO' as const,
      currentVersion: { ...goal.currentVersion, year: null, targetAmount: null, ratio: '0.30' },
    };
    api.listGoals.mockReturnValue(ok({ items: [rolling] }));
    api.readGoal.mockReturnValue(
      ok({
        ...rolling,
        versions: [rolling.currentVersion],
        referenceIncome: {
          month: '2026-09',
          value: '2500.00',
          usableMonths: 10,
          windowStart: '2025-09',
          windowEnd: '2026-08',
          reason: null,
        },
      }),
    );
    renderPage();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Actions de l’objectif « Épargne »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Modifier l’objectif « Épargne »' }));
    expect(
      await screen.findByText(/Revenu de référence.*10\/12 mois, septembre 2025 à août 2026/),
    ).toBeTruthy();
  });
  it('opens the create modal from the keyboard, fills an annual goal and submits it', async () => {
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [] }));
    api.createGoal.mockReturnValue(ok(goal, 201));
    renderPage();
    const addButton = await screen.findByRole('button', { name: 'Nouvel objectif' });
    activateWithKeyboard(addButton);
    expect(screen.getByRole('dialog', { name: 'Nouvel objectif' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Libellé'), {
      target: { value: 'Résidence secondaire' },
    });
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '5000.00' } });
    activateWithKeyboard(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.createGoal).toHaveBeenCalledWith(
        expect.objectContaining({
          body: expect.objectContaining({
            label: 'Résidence secondaire',
            targetAmount: { value: '5000.00', assetCode: 'EUR' },
          }),
        }),
      ),
    );
  });
  it('renders the goals page and the create modal without horizontal overflow at 360 px', async () => {
    mockSession();
    const originalWidth = window.innerWidth;
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 360 });
    try {
      api.listGoals.mockReturnValue(ok({ items: [goal] }));
      renderPage();
      expect(await screen.findByText('Épargne')).toBeTruthy();
      fireEvent.click(screen.getByRole('button', { name: 'Nouvel objectif' }));
      expect(screen.getByRole('dialog', { name: 'Nouvel objectif' })).toBeTruthy();
      expect(screen.getByLabelText('Libellé')).toBeTruthy();
      expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeTruthy();
    } finally {
      Object.defineProperty(window, 'innerWidth', { configurable: true, value: originalWidth });
    }
  });
  it('creates a rolling ratio goal with the metric, scope, percentage and start month all sent', async () => {
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [] }));
    api.createGoal.mockReturnValue(ok({ ...goal, kind: 'ROLLING_RATIO' as const }, 201));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvel objectif' }));
    fireEvent.change(screen.getByLabelText('Libellé'), {
      target: { value: 'Discrétionnaire glissant' },
    });
    fireEvent.click(screen.getByRole('radio', { name: 'Ratio glissant' }));
    fireEvent.change(screen.getByLabelText('Indicateur'), { target: { value: 'AXIS_SPENDING' } });
    fireEvent.change(screen.getByLabelText('Périmètre'), { target: { value: 'DISCRETIONARY' } });
    fireEvent.change(screen.getByLabelText(/^Pourcentage/), { target: { value: '30' } });
    fireEvent.change(screen.getByLabelText('Mois de début'), { target: { value: '2026-10' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.createGoal).toHaveBeenCalledWith(
        expect.objectContaining({
          body: {
            label: 'Discrétionnaire glissant',
            kind: 'ROLLING_RATIO',
            metric: 'AXIS_SPENDING',
            scope: { type: 'AXIS', ref: 'DISCRETIONARY' },
            ratio: '0.30',
            startMonth: '2026-10',
          },
        }),
      ),
    );
  });
  it('shows the stored ratio as the matching percentage and resubmits it unchanged without drift', async () => {
    mockSession();
    const rolling = {
      ...goal,
      id: '00000000-0000-7000-8000-000000000003',
      kind: 'ROLLING_RATIO' as const,
      currentVersion: { ...goal.currentVersion, year: null, targetAmount: null, ratio: '0.30' },
    };
    api.listGoals.mockReturnValue(ok({ items: [rolling] }));
    api.readGoal.mockReturnValue(ok({ ...rolling, versions: [rolling.currentVersion] }));
    api.updateGoal.mockReturnValue(ok(rolling));
    renderPage();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Actions de l’objectif « Épargne »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Modifier l’objectif « Épargne »' }));
    const ratioInput = await screen.findByLabelText(/^Pourcentage/);
    expect((ratioInput as HTMLInputElement).value).toBe('30');
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.updateGoal).toHaveBeenCalledWith(
        expect.objectContaining({ body: expect.objectContaining({ ratio: '0.30' }) }),
      ),
    );
  });
  it('renders the loaded annual schedule table in the edit modal', async () => {
    mockSession();
    const schedule = {
      mode: 'LINEAR' as const,
      parts: [{ month: '2026-01', amount: '166.67', cumulative: '166.67' }],
    };
    api.listGoals.mockReturnValue(ok({ items: [goal] }));
    api.readGoal.mockReturnValue(
      ok({ ...goal, currentVersion: { ...goal.currentVersion, schedule }, versions: [] }),
    );
    renderPage();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Actions de l’objectif « Épargne »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Modifier l’objectif « Épargne »' }));

    expect(await screen.findByRole('table')).toBeTruthy();
    expect(screen.getByRole('rowheader', { name: 'janvier 2026' })).toBeTruthy();
  });

  it('renders a loaded annual goal without a schedule as not calculable in the edit modal', async () => {
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [goal] }));
    api.readGoal.mockReturnValue(
      ok({ ...goal, currentVersion: { ...goal.currentVersion, schedule: null }, versions: [] }),
    );
    renderPage();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Actions de l’objectif « Épargne »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Modifier l’objectif « Épargne »' }));

    expect(await screen.findByText('Aucun calendrier mensuel pour cet objectif.')).toBeTruthy();
  });
  it('shows an error inside the detail modal, not a page-wide failure, when the status call fails', async () => {
    mockSession();
    const status = {
      goalId: goal.id,
      month: '2026-09',
      kind: 'ANNUAL' as const,
      direction: 'MINIMUM' as const,
      goalVersion: 1,
      scheduleMode: 'LINEAR' as const,
      provisional: false,
      metricPolicy: { state: 'SINGLE' as const, version: 1, versions: [1] },
      target: '12000',
      achieved: '9000',
      expected: '9000',
      gap: '0',
      remaining: '3000',
      excess: '0',
      status: 'AHEAD' as const,
      progressRatio: '0.75',
      progressRatioDisplay: '0.75',
      reason: null,
      quality: 'COMPLETE' as const,
    };
    api.listGoals.mockReturnValue(ok({ items: [{ ...goal, status }] }));
    api.readGoalStatus.mockReturnValue(
      Promise.resolve({
        error: {
          type: '/problems/internal',
          title: 'Internal Server Error',
          status: 500,
          detail: 'unavailable',
        },
        response: new Response(null, { status: 500 }),
      }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Détail mensuel' }));
    expect(await screen.findByRole('dialog', { name: 'Détail de l’objectif' })).toBeTruthy();
    expect(await screen.findByRole('alert')).toHaveProperty(
      'textContent',
      'Impossible de charger les objectifs',
    );
    expect(
      screen.queryByRole('heading', { name: 'Impossible de charger les objectifs' }),
    ).toBeNull();
  });
  it('sends the month chosen for a past year so the cards show that month, not December', async () => {
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [goal] }));
    renderPage();
    expect(await screen.findByText('Épargne')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Année'), { target: { value: '2025' } });
    fireEvent.change(screen.getByLabelText('Mois'), { target: { value: '2025-06' } });

    await waitFor(() =>
      expect(api.listGoals).toHaveBeenCalledWith(
        expect.objectContaining({
          query: expect.objectContaining({ year: 2025, month: '2025-06' }),
        }),
      ),
    );
  });
  it('offers no monthly detail nor month choice for a future year, so no status call can fail', async () => {
    mockSession();
    const status = {
      goalId: goal.id,
      month: '2027-01',
      kind: 'ANNUAL' as const,
      direction: 'MINIMUM' as const,
      goalVersion: 1,
      scheduleMode: 'LINEAR' as const,
      provisional: true,
      metricPolicy: { state: 'SINGLE' as const, version: 1, versions: [1] },
      target: '12000',
      achieved: null,
      expected: null,
      gap: null,
      remaining: null,
      excess: null,
      status: 'NOT_CALCULABLE' as const,
      progressRatio: null,
      progressRatioDisplay: null,
      reason: 'GOAL_NOT_STARTED' as const,
      quality: 'PARTIAL' as const,
    };
    const future = {
      ...goal,
      label: 'Épargne 2027',
      currentVersion: { ...goal.currentVersion, year: 2027, effectiveFrom: '2027-01' },
    };
    api.listGoals.mockImplementation(({ query }) =>
      ok({ items: query.year === 2027 ? [{ ...future, status }] : [{ ...goal, status }] }),
    );
    renderPage();
    expect(await screen.findByRole('button', { name: 'Détail mensuel' })).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Année'), { target: { value: '2027' } });

    expect(await screen.findByText('Épargne 2027')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Détail mensuel' })).toBeNull();
    expect((screen.getByLabelText('Mois') as HTMLInputElement).disabled).toBe(true);
    expect(api.readGoalStatus).not.toHaveBeenCalled();
  });
  it('keeps the goals list usable after selecting a future year instead of showing a page-wide error', async () => {
    mockSession();
    const future = {
      ...goal,
      id: '00000000-0000-7000-8000-000000000005',
      currentVersion: { ...goal.currentVersion, year: 2027, effectiveFrom: '2027-01' },
    };
    api.listGoals.mockImplementation(({ query }) => {
      // The real backend rejects any month after the current workspace-local month (2026-09).
      if (typeof query.month === 'string' && query.month > '2026-09') {
        return Promise.resolve({
          error: {
            type: '/problems/future_goal_month',
            title: 'Unprocessable',
            status: 422,
            detail: 'future',
            code: 'future_goal_month',
          },
          response: new Response(null, { status: 422 }),
        });
      }
      return ok({ items: query.year === 2027 ? [future] : [goal] });
    });
    renderPage();
    expect(await screen.findByText('Épargne')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Année'), { target: { value: '2027' } });

    await waitFor(() =>
      expect(api.listGoals).toHaveBeenCalledWith(
        expect.objectContaining({
          query: expect.objectContaining({ year: 2027, month: undefined }),
        }),
      ),
    );
    expect(await screen.findByText('Épargne')).toBeTruthy();
    expect(
      screen.queryByRole('heading', { name: 'Impossible de charger les objectifs' }),
    ).toBeNull();
  });
  /** Mirrors the server: an empty, malformed or out-of-year month rejects the whole list. */
  function mockListRejectingInvalidMonths() {
    api.listGoals.mockImplementation(({ query }) => {
      const month: unknown = query.month;
      const valid =
        month === undefined ||
        (typeof month === 'string' &&
          /^\d{4}-(0[1-9]|1[0-2])$/.test(month) &&
          month.startsWith(`${query.year}-`));
      if (!valid) {
        return Promise.resolve({
          error: {
            type: '/problems/invalid_goal_month',
            title: 'Bad Request',
            status: 400,
            detail: 'invalid',
            code: 'invalid_goal_month',
          },
          response: new Response(null, { status: 400 }),
        });
      }
      return ok({ items: [goal] });
    });
  }
  it('keeps the last valid month and flags the field when the month picker is emptied', async () => {
    mockSession();
    mockListRejectingInvalidMonths();
    renderPage();
    expect(await screen.findByText('Épargne')).toBeTruthy();
    const monthInput = screen.getByLabelText('Mois') as HTMLInputElement;

    fireEvent.change(monthInput, { target: { value: '' } });

    await waitFor(() => expect(monthInput.getAttribute('aria-invalid')).toBe('true'));
    expect(api.listGoals).not.toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ month: '' }) }),
    );
    expect(screen.getByText('Épargne')).toBeTruthy();
    expect(
      screen.queryByRole('heading', { name: 'Impossible de charger les objectifs' }),
    ).toBeNull();
  });
  it('keeps the last valid month and flags the field when a month of another year is typed', async () => {
    mockSession();
    mockListRejectingInvalidMonths();
    renderPage();
    expect(await screen.findByText('Épargne')).toBeTruthy();
    const monthInput = screen.getByLabelText('Mois') as HTMLInputElement;
    const otherYear = String(Number.parseInt(monthInput.value.slice(0, 4), 10) - 2);

    fireEvent.change(monthInput, { target: { value: `${otherYear}-03` } });

    await waitFor(() => expect(monthInput.getAttribute('aria-invalid')).toBe('true'));
    expect(api.listGoals).not.toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ month: `${otherYear}-03` }) }),
    );
    expect(screen.getByText('Épargne')).toBeTruthy();
    expect(
      screen.queryByRole('heading', { name: 'Impossible de charger les objectifs' }),
    ).toBeNull();
  });
  it('does not send a month later than the current month of the displayed year', async () => {
    mockSession();
    mockListRejectingInvalidMonths();
    renderPage();
    expect(await screen.findByText('Épargne')).toBeTruthy();
    const monthInput = screen.getByLabelText('Mois') as HTMLInputElement;
    if (monthInput.value.endsWith('-12')) return;
    const later = `${monthInput.value.slice(0, 4)}-12`;

    fireEvent.change(monthInput, { target: { value: later } });

    await waitFor(() => expect(monthInput.getAttribute('aria-invalid')).toBe('true'));
    expect(api.listGoals).not.toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ month: later }) }),
    );
  });
  it('prefills the edit form from the latest version so a rename cannot revert a newer ratio', async () => {
    mockSession();
    const v1 = {
      ...goal.currentVersion,
      versionNo: 1,
      effectiveFrom: '2026-09',
      year: null,
      targetAmount: null,
      ratio: '0.30',
    };
    const v2 = {
      ...goal.currentVersion,
      versionNo: 2,
      effectiveFrom: '2027-02',
      year: null,
      targetAmount: null,
      ratio: '0.40',
    };
    const listed = {
      ...goal,
      id: '00000000-0000-7000-8000-000000000004',
      kind: 'ROLLING_RATIO' as const,
      currentVersion: v1,
      version: 2,
    };
    api.listGoals.mockReturnValue(ok({ items: [listed] }));
    api.readGoal.mockReturnValue(
      ok({ ...listed, currentVersion: v2, version: 2, versions: [v2, v1] }),
    );
    api.updateGoal.mockReturnValue(ok({ ...listed, currentVersion: v2 }));
    renderPage();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Actions de l’objectif « Épargne »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Modifier l’objectif « Épargne »' }));
    const ratioInput = await screen.findByLabelText(/^Pourcentage/);
    expect((ratioInput as HTMLInputElement).value).toBe('40');
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.updateGoal).toHaveBeenCalledWith(
        expect.objectContaining({ body: expect.objectContaining({ ratio: '0.40' }) }),
      ),
    );
  });
});
