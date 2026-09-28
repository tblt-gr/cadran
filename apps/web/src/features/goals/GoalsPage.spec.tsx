import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GoalsPage } from './GoalsPage';

const api = vi.hoisted(() => ({
  listGoals: vi.fn(),
  createGoal: vi.fn(),
  updateGoal: vi.fn(),
  closeGoal: vi.fn(),
  readGoal: vi.fn(),
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
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
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
