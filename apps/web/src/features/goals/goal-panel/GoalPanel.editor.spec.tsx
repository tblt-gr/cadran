import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { coverageShortfall, defaultPolicy } from '@/features/goals/emergency-fund/fixtures';
import { GoalPanel } from './GoalPanel';

const api = vi.hoisted(() => ({
  listGoals: vi.fn(),
  listGoalEvaluations: vi.fn(),
  createGoal: vi.fn(),
  updateGoal: vi.fn(),
  readGoal: vi.fn(),
  readGoalStatus: vi.fn(),
  listGoalVersions: vi.fn(),
  previewGoalCorrection: vi.fn(),
  previewGoalSchedule: vi.fn(),
  listAccounts: vi.fn(),
  listCategories: vi.fn(),
  getSession: vi.fn(),
  readEmergencyFundCoverage: vi.fn(),
  readEmergencyFundPolicy: vi.fn(),
  updateEmergencyFundPolicy: vi.fn(),
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
  periodicity: 'YEARLY' as const,
  startPeriod: '2026',
  endPeriod: null,
  metric: 'NET_SAVINGS_TRANSFERS' as const,
  direction: 'MINIMUM' as const,
  scope: { type: 'WORKSPACE' as const, label: 'Espace', archived: false },
  assetCode: 'EUR',
  currentVersion: {
    versionNo: 1,
    effectiveFrom: '2026-09',
    valueType: 'AMOUNT' as const,
    targetAmount: '12000.00',
    ratio: null,
  },
  version: 1,
  warnings: [] as 'REFERENCE_ARCHIVED'[],
};
/** An evaluation of a listed goal in the panel's month (monthly goals) or year (yearly goals). */
type ListedGoal = Omit<typeof goal, 'periodicity'> & { periodicity: 'MONTHLY' | 'YEARLY' };
function evaluationOf(summary: ListedGoal) {
  return {
    goalId: summary.id,
    label: summary.label,
    periodicity: summary.periodicity,
    metric: summary.metric,
    scopeType: 'WORKSPACE',
    scopeRef: null,
    direction: summary.direction,
    valueType: summary.currentVersion.valueType,
    columnKey: null,
    target: null,
    achieved: null,
    gap: null,
    status: 'IN_PROGRESS',
    reason: null,
    formula: {
      key: 'goals.formula.amount',
      operands: { target: null, achieved: null, income: null, ratio: null },
    },
    versionNo: 1,
    overrides: [],
    pace: null,
  };
}
async function evaluationsOfListedGoals() {
  const listed = (await api.listGoals()) as { data: { items: ListedGoal[] } };
  const items = listed.data.items;
  return ok({
    months: [{ period: '2026-09', items: items.map(evaluationOf) }],
    years: [],
  });
}
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
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        <GoalPanel
          close={() => undefined}
          returnFocus={{ current: null }}
          scope={{ kind: 'month', month: '2026-09' }}
        />
      </QueryClientProvider>,
    ),
    queryClient,
  };
}
describe('GoalPanel editor', () => {
  // The page derives its default year and month from today's date, and the list mock mirrors a
  // server that rejects months after 2026-09: freezing only `Date` keeps the suite independent of
  // the real calendar while leaving the timers React Query and waitFor rely on untouched.
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-09-15T10:00:00Z'));
    api.listGoalEvaluations.mockImplementation(evaluationsOfListedGoals);
    api.readEmergencyFundCoverage.mockImplementation(() => ok(coverageShortfall));
    api.readEmergencyFundPolicy.mockImplementation(() => ok(defaultPolicy));
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
    expect(screen.getByText('Création')).toBeTruthy();
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
  it('sends one Idempotency-Key per creation and reuses it when the same body is retried', async () => {
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [] }));
    api.createGoal
      .mockReturnValueOnce(
        Promise.resolve({
          error: { type: 'about:blank', title: 'Unavailable', status: 503 },
          response: new Response(null, { status: 503 }),
        }),
      )
      .mockReturnValue(ok(goal, 201));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvel objectif' }));
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Épargne' } });
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '12000.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.createGoal).toHaveBeenCalledTimes(1));
    fireEvent.click(await screen.findByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.createGoal).toHaveBeenCalledTimes(2));

    const keys = api.createGoal.mock.calls.map(([options]) => options.headers['Idempotency-Key']);
    expect(keys[0]).toMatch(/^[0-9a-f-]{36}$/);
    expect(keys[1]).toBe(keys[0]);
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
    fireEvent.click(screen.getByRole('radio', { name: 'Annuel' }));
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
  it('does not restore a stale preview after switching a yearly draft to monthly and back', async () => {
    let resolvePreview: (value: unknown) => void;
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [] }));
    api.previewGoalSchedule.mockReturnValue(new Promise((resolve) => (resolvePreview = resolve)));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvel objectif' }));
    fireEvent.click(screen.getByRole('radio', { name: 'Annuel' }));
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '2000.00' } });
    fireEvent.click(screen.getByRole('radio', { name: 'Personnalisé' }));
    fireEvent.click(screen.getByRole('button', { name: 'Répartir linéairement' }));
    fireEvent.click(screen.getByRole('radio', { name: 'Mensuel' }));
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
  it('shows the rolling reference income without coercing its exact amount to a number', async () => {
    mockSession();
    const rolling = {
      ...goal,
      id: '00000000-0000-7000-8000-000000000002',
      periodicity: 'MONTHLY' as const,
      startPeriod: '2026-01',
      currentVersion: {
        ...goal.currentVersion,
        valueType: 'ROLLING_INCOME_SHARE' as const,
        targetAmount: null,
        ratio: '0.30',
      },
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
    await waitFor(() => expect(screen.queryByTestId('goal-form-skeleton')).toBeNull());
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
  it('creates a monthly goal on a rolling income share with every new field sent', async () => {
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [] }));
    api.createGoal.mockReturnValue(ok(goal, 201));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvel objectif' }));
    fireEvent.change(screen.getByLabelText('Libellé'), {
      target: { value: 'Discrétionnaire glissant' },
    });
    fireEvent.change(screen.getByLabelText('Indicateur'), { target: { value: 'AXIS_SPENDING' } });
    fireEvent.change(screen.getByLabelText('Périmètre'), { target: { value: 'DISCRETIONARY' } });
    fireEvent.change(screen.getByLabelText('Type de valeur'), {
      target: { value: 'ROLLING_INCOME_SHARE' },
    });
    fireEvent.change(screen.getByLabelText(/^Pourcentage/), { target: { value: '30' } });
    fireEvent.change(screen.getByLabelText('Début'), { target: { value: '2026-10' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.createGoal).toHaveBeenCalledWith(
        expect.objectContaining({
          body: {
            label: 'Discrétionnaire glissant',
            periodicity: 'MONTHLY',
            metric: 'AXIS_SPENDING',
            scope: { type: 'AXIS', ref: 'DISCRETIONARY' },
            startPeriod: '2026-10',
            endPeriod: null,
            valueType: 'ROLLING_INCOME_SHARE',
            ratio: '0.30',
          },
        }),
      ),
    );
  });
  it('creates a one-off yearly savings rate goal with the end set to the start', async () => {
    mockSession();
    api.listGoals.mockReturnValue(ok({ items: [] }));
    api.createGoal.mockReturnValue(ok(goal, 201));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvel objectif' }));
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Taux 2026' } });
    fireEvent.change(screen.getByLabelText('Indicateur'), { target: { value: 'SAVINGS_RATE' } });
    fireEvent.click(screen.getByRole('radio', { name: 'Annuel' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Une seule année' }));
    fireEvent.change(screen.getByLabelText('Taux cible'), { target: { value: '20' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.createGoal).toHaveBeenCalledWith(
        expect.objectContaining({
          body: {
            label: 'Taux 2026',
            periodicity: 'YEARLY',
            metric: 'SAVINGS_RATE',
            scope: { type: 'WORKSPACE', ref: null },
            startPeriod: '2026',
            endPeriod: '2026',
            valueType: 'RATE',
            ratio: '0.20',
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
      periodicity: 'MONTHLY' as const,
      startPeriod: '2026-01',
      currentVersion: {
        ...goal.currentVersion,
        valueType: 'ROLLING_INCOME_SHARE' as const,
        targetAmount: null,
        ratio: '0.30',
      },
    };
    api.listGoals.mockReturnValue(ok({ items: [rolling] }));
    api.readGoal.mockReturnValue(ok({ ...rolling, versions: [rolling.currentVersion] }));
    api.updateGoal.mockReturnValue(ok(rolling));
    renderPage();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Actions de l’objectif « Épargne »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Modifier l’objectif « Épargne »' }));
    await waitFor(() => expect(screen.queryByTestId('goal-form-skeleton')).toBeNull());
    const ratioInput = await screen.findByLabelText(/^Pourcentage/);
    expect((ratioInput as HTMLInputElement).value).toBe('30');
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.updateGoal).toHaveBeenCalledWith(
        expect.objectContaining({ body: expect.objectContaining({ ratio: '0.30' }) }),
      ),
    );
  });
  it('prefills the edit form from the latest version so a rename cannot revert a newer ratio', async () => {
    mockSession();
    const v1 = {
      ...goal.currentVersion,
      versionNo: 1,
      effectiveFrom: '2026-09',
      valueType: 'ROLLING_INCOME_SHARE' as const,
      targetAmount: null,
      ratio: '0.30',
    };
    const v2 = {
      ...goal.currentVersion,
      versionNo: 2,
      effectiveFrom: '2027-02',
      valueType: 'ROLLING_INCOME_SHARE' as const,
      targetAmount: null,
      ratio: '0.40',
    };
    const listed = {
      ...goal,
      id: '00000000-0000-7000-8000-000000000004',
      periodicity: 'MONTHLY' as const,
      startPeriod: '2026-01',
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
    await waitFor(() => expect(screen.queryByTestId('goal-form-skeleton')).toBeNull());
    const ratioInput = await screen.findByLabelText(/^Pourcentage/);
    expect((ratioInput as HTMLInputElement).value).toBe('40');
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.updateGoal).toHaveBeenCalledWith(
        expect.objectContaining({ body: expect.objectContaining({ ratio: '0.40' }) }),
      ),
    );
  });

  it('updates only the label of a future yearly goal', async () => {
    mockSession();
    const future = {
      ...goal,
      startPeriod: '2027',
      currentVersion: { ...goal.currentVersion, effectiveFrom: '2027-01' },
    };
    api.listGoals.mockReturnValue(ok({ items: [future] }));
    api.readGoal.mockReturnValue(ok({ ...future, versions: [future.currentVersion] }));
    api.updateGoal.mockReturnValue(ok({ ...future, label: 'Projet 2027' }));
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Actions de l’objectif « Épargne »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Modifier l’objectif « Épargne »' }));
    await waitFor(() => expect(screen.queryByTestId('goal-form-skeleton')).toBeNull());
    fireEvent.change(await screen.findByLabelText('Libellé'), {
      target: { value: 'Projet 2027' },
    });
    expect(screen.queryByLabelText('À partir de')).toBeNull();
    expect(screen.queryByLabelText('Montant cible')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() =>
      expect(api.updateGoal).toHaveBeenCalledWith(
        expect.objectContaining({ body: { label: 'Projet 2027', version: 1 } }),
      ),
    );
  });

  it('updates only the label of a future monthly goal', async () => {
    mockSession();
    const future = {
      ...goal,
      periodicity: 'MONTHLY' as const,
      startPeriod: '2027-01',
      currentVersion: {
        ...goal.currentVersion,
        effectiveFrom: '2027-01',
        valueType: 'INCOME_SHARE' as const,
        targetAmount: null,
        ratio: '0.20',
      },
    };
    api.listGoals.mockReturnValue(ok({ items: [future] }));
    api.readGoal.mockReturnValue(ok({ ...future, versions: [future.currentVersion] }));
    api.updateGoal.mockReturnValue(ok({ ...future, label: 'Projet 2027' }));
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Actions de l’objectif « Épargne »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Modifier l’objectif « Épargne »' }));
    await waitFor(() => expect(screen.queryByTestId('goal-form-skeleton')).toBeNull());
    fireEvent.change(await screen.findByLabelText('Libellé'), {
      target: { value: 'Projet 2027' },
    });
    expect(screen.queryByLabelText('À partir de')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() =>
      expect(api.updateGoal).toHaveBeenCalledWith(
        expect.objectContaining({ body: { label: 'Projet 2027', version: 1 } }),
      ),
    );
  });

  it('shows a failed correction save and returns to the preserved draft for a new preview', async () => {
    mockSession();
    const version = {
      ...goal.currentVersion,
      effectiveFrom: '2026-01',
      schedule: {
        mode: 'LINEAR' as const,
        parts: Array.from({ length: 12 }, (_, index) => ({
          month: `2026-${String(index + 1).padStart(2, '0')}`,
          amount: '1000.00',
          cumulative: `${(index + 1) * 1000}.00`,
        })),
      },
    };
    api.listGoals.mockReturnValue(ok({ items: [{ ...goal, currentVersion: version }] }));
    api.readGoal.mockReturnValue(ok({ ...goal, currentVersion: version, versions: [version] }));
    api.previewGoalCorrection.mockReturnValue(
      ok({
        months: [
          {
            month: '2026-01',
            before: { goalVersion: 1, expected: '1000.00', status: 'AHEAD' as const },
            after: { goalVersion: 2, expected: '1250.00', status: 'AHEAD' as const },
          },
        ],
      }),
    );
    api.updateGoal.mockReturnValue(
      Promise.resolve({
        error: {
          type: '/problems/stale_goal_version',
          title: 'Conflict',
          status: 409,
          detail: 'stale',
          code: 'stale_goal_version',
        },
        response: new Response(null, { status: 409 }),
      }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Actions de l’objectif « Épargne »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Modifier l’objectif « Épargne »' }));
    await waitFor(() => expect(screen.queryByTestId('goal-form-skeleton')).toBeNull());
    fireEvent.change(await screen.findByLabelText('À partir de'), {
      target: { value: '2026-01' },
    });
    fireEvent.change(screen.getByLabelText(/^Motif de la correction/), {
      target: { value: 'Correction documentée' },
    });
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '15000.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByText('Confirmer la correction historique')).toBeTruthy();
    expect(document.activeElement).toBe(
      screen.getByRole('heading', { name: 'Confirmer la correction historique' }),
    );
    expect(
      screen.getByText(/jusqu’à la prochaine version dont le mois d’effet est plus récent/),
    ).toBeTruthy();
    expect(screen.getByRole('table', { name: 'Impact mensuel de la correction' })).toBeTruthy();

    fireEvent.click(screen.getByLabelText('J’ai vérifié l’impact de cette correction.'));
    api.readGoal.mockReturnValue(
      ok({ ...goal, version: 2, currentVersion: version, versions: [version] }),
    );
    activateWithKeyboard(screen.getByRole('button', { name: 'Confirmer la correction' }));
    expect(await screen.findByRole('alert')).toHaveProperty(
      'textContent',
      'Cet objectif a changé et a été rechargé. Vérifiez vos valeurs puis réessayez.',
    );
    await waitFor(() => expect(api.readGoal).toHaveBeenCalledTimes(2));
    expect(
      (screen.getByRole('button', { name: 'Confirmer la correction' }) as HTMLButtonElement)
        .disabled,
    ).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: 'Revenir et recalculer l’aperçu' }));
    expect(document.activeElement).toBe(screen.getByLabelText('À partir de'));
    expect((screen.getByLabelText('Montant cible') as HTMLInputElement).value).toBe('15000.00');
    expect((screen.getByLabelText(/^Motif de la correction/) as HTMLTextAreaElement).value).toBe(
      'Correction documentée',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.previewGoalCorrection).toHaveBeenCalledTimes(2));
    expect(api.previewGoalCorrection.mock.calls[1]?.[0].body.version).toBe(2);
  });

  async function openRetroactiveCorrection() {
    mockSession();
    const version = {
      ...goal.currentVersion,
      effectiveFrom: '2026-01',
      schedule: {
        mode: 'LINEAR' as const,
        parts: Array.from({ length: 12 }, (_, index) => ({
          month: `2026-${String(index + 1).padStart(2, '0')}`,
          amount: '1000.00',
          cumulative: `${(index + 1) * 1000}.00`,
        })),
      },
    };
    api.listGoals.mockReturnValue(ok({ items: [{ ...goal, currentVersion: version }] }));
    api.readGoal.mockReturnValue(ok({ ...goal, currentVersion: version, versions: [version] }));
    renderPage();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Actions de l’objectif « Épargne »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Modifier l’objectif « Épargne »' }));
    await waitFor(() => expect(screen.queryByTestId('goal-form-skeleton')).toBeNull());
    fireEvent.change(await screen.findByLabelText('À partir de'), {
      target: { value: '2026-01' },
    });
    fireEvent.change(screen.getByLabelText(/^Motif de la correction/), {
      target: { value: 'Correction documentée' },
    });
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '15000.00' } });
    return version;
  }

  it('reloads the goal after a stale preview so the next preview uses the current version', async () => {
    const version = await openRetroactiveCorrection();
    api.previewGoalCorrection.mockReturnValueOnce(
      Promise.resolve({
        error: {
          type: '/problems/stale_goal_version',
          title: 'Conflict',
          status: 409,
          detail: 'stale',
          code: 'stale_goal_version',
        },
        response: new Response(null, { status: 409 }),
      }),
    );
    api.previewGoalCorrection.mockReturnValue(ok({ months: [] }));
    api.updateGoal.mockReturnValue(ok(goal));
    api.readGoal.mockReturnValue(
      ok({ ...goal, version: 2, currentVersion: version, versions: [version] }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect((await screen.findByRole('alert')).textContent).toContain('a été rechargé');
    await waitFor(() => expect(api.readGoal).toHaveBeenCalledTimes(2));

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.previewGoalCorrection).toHaveBeenCalledTimes(2));
    expect(api.previewGoalCorrection.mock.calls[1]?.[0].body.version).toBe(2);
  });

  it('saves directly when the preview shows no month changing', async () => {
    await openRetroactiveCorrection();
    api.previewGoalCorrection.mockReturnValue(ok({ months: [] }));
    api.updateGoal.mockReturnValue(ok(goal));
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(api.updateGoal).toHaveBeenCalledTimes(1));
    expect(screen.queryByText('Confirmer la correction historique')).toBeNull();
  });
});
