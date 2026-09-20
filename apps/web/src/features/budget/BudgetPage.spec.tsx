import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { BudgetPage } from './BudgetPage';

const api = vi.hoisted(() => ({
  listBudgetPlans: vi.fn(),
  readBudgetPlan: vi.fn(),
  createBudgetPlan: vi.fn(),
  createBudgetTarget: vi.fn(),
  updateBudgetPlan: vi.fn(),
  updateBudgetTarget: vi.fn(),
  activateBudgetPlan: vi.fn(),
  closeBudgetPlan: vi.fn(),
  readBudgetComparisons: vi.fn(),
  listCategories: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const plan = {
  id: '00000000-0000-7000-8000-000000000001',
  periodType: 'MONTH' as const,
  period: '2026-03',
  assetCode: 'EUR',
  state: 'DRAFT' as const,
  version: 1,
};
const ok = <T,>(data: T, status = 200) =>
  Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
const detail = (overrides = {}) => ({ ...plan, targets: [], ...overrides });
const target = {
  id: '00000000-0000-7000-8000-000000000002',
  scopeType: 'AXIS' as const,
  scopeId: 'ESSENTIAL',
  valueType: 'AMOUNT' as const,
  storedAmount: '1',
  storedRatio: null,
  resolvedAmount: '1',
  nonCalculableReason: null,
  overlapping: false,
  version: 1,
};
const comparisons = {
  planId: plan.id,
  period: plan.period,
  assetCode: plan.assetCode,
  status: 'AVAILABLE' as const,
  reason: null,
  comparisons: [],
};
function renderPage(
  planId?: string,
  client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  }),
) {
  return render(
    <QueryClientProvider client={client}>
      <BudgetPage planId={planId} />
    </QueryClientProvider>,
  );
}
describe('BudgetPage', () => {
  beforeEach(() => {
    api.readBudgetComparisons.mockReturnValue(ok(comparisons));
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });
  it('shows an empty state and submits a monthly draft in the shared modal', async () => {
    api.listBudgetPlans.mockReturnValue(ok({ items: [], page: 1, perPage: 100, total: 0 }));
    api.createBudgetPlan.mockReturnValue(ok(plan, 201));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier plan' }));
    expect(screen.getByRole('dialog', { name: 'Nouveau plan budgétaire' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Période'), { target: { value: '2026-03' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.createBudgetPlan).toHaveBeenCalledWith(
        expect.objectContaining({
          body: { periodType: 'MONTH', period: '2026-03', assetCode: 'EUR' },
        }),
      ),
    );
  });
  it('shows a stored decimal ratio as an exact percentage and preserves the server non-calculable reason', async () => {
    api.listBudgetPlans.mockReturnValue(ok({ items: [plan], page: 1, perPage: 100, total: 1 }));
    api.readBudgetPlan.mockReturnValue(
      ok(
        detail({
          targets: [
            {
              id: '00000000-0000-7000-8000-000000000002',
              scopeType: 'AXIS',
              scopeId: 'ESSENTIAL',
              valueType: 'RATIO',
              storedAmount: null,
              storedRatio: '0.30',
              resolvedAmount: null,
              nonCalculableReason: 'ZERO_CASH_INCOME',
              overlapping: true,
              version: 1,
            },
          ],
        }),
      ),
    );
    renderPage(plan.id);
    expect(await screen.findByText('30 %')).toBeTruthy();
    expect(await screen.findByText('Non calculable')).toBeTruthy();
    expect(screen.getByText('Revenus de trésorerie nuls')).toBeTruthy();
    expect(screen.getByText('Chevauchement de périmètre')).toBeTruthy();
  });
  it('shows a session-expired state for an unauthorized list', async () => {
    api.listBudgetPlans.mockResolvedValue({
      error: {},
      response: new Response('{}', { status: 401 }),
    });
    renderPage();
    expect(await screen.findByRole('heading', { name: 'Session expirée' })).toBeTruthy();
  });
  it('disables activation of an empty draft and explains why', async () => {
    api.listBudgetPlans.mockReturnValue(ok({ items: [plan], page: 1, perPage: 100, total: 1 }));
    api.readBudgetPlan.mockReturnValue(ok(detail()));
    renderPage(plan.id);

    const activate = await screen.findByRole('button', { name: 'Activer' });
    expect(activate.getAttribute('disabled')).not.toBeNull();
    expect(activate.getAttribute('aria-describedby')).toBeTruthy();
    expect(screen.getByText('Ajoutez un objectif avant d’activer ce plan.')).toBeTruthy();
  });
  it('opens the category target perimeter picker as a labelled combobox', async () => {
    api.listBudgetPlans.mockReturnValue(ok({ items: [plan], page: 1, perPage: 100, total: 1 }));
    api.readBudgetPlan.mockReturnValue(ok(detail()));
    api.listCategories.mockReturnValue(
      ok({
        items: [
          {
            archivedAt: null,
            color: null,
            icon: null,
            id: '00000000-0000-7000-8000-000000000003',
            label: 'Logement',
            type: 'EXPENSE',
          },
        ],
        page: 1,
        perPage: 50,
        total: 1,
      }),
    );
    renderPage(plan.id);

    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter un objectif' }));
    const picker = screen.getByRole('combobox', { name: 'Périmètre' });
    expect(picker.getAttribute('aria-expanded')).toBe('false');
    fireEvent.focus(picker);
    expect(await screen.findByRole('listbox', { name: 'Périmètre' })).toBeTruthy();
  });
  it('explains that a ratio target is entered as an exact decimal ratio', async () => {
    api.listBudgetPlans.mockReturnValue(ok({ items: [plan], page: 1, perPage: 100, total: 1 }));
    api.readBudgetPlan.mockReturnValue(ok(detail()));
    api.listCategories.mockReturnValue(ok({ items: [], page: 1, perPage: 50, total: 0 }));
    renderPage(plan.id);

    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter un objectif' }));
    fireEvent.change(screen.getByLabelText('Type d’objectif'), { target: { value: 'RATIO' } });
    const ratio = screen.getByRole('textbox', { name: 'Ratio décimal' });
    expect(ratio.getAttribute('aria-describedby')).toBeTruthy();
    expect(
      screen.getByText('Saisissez le ratio décimal exact : 0.30 correspond à 30 %.'),
    ).toBeTruthy();
  });
  it('invalidates the budget comparison after an edited target is saved', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    const invalidate = vi.spyOn(client, 'invalidateQueries');
    api.listBudgetPlans.mockReturnValue(ok({ items: [plan], page: 1, perPage: 100, total: 1 }));
    api.readBudgetPlan.mockReturnValue(ok(detail({ targets: [target] })));
    api.updateBudgetTarget.mockReturnValue(ok(target));
    renderPage(plan.id, client);

    fireEvent.click((await screen.findAllByRole('button', { name: 'Modifier' }))[1]!);
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() =>
      expect(invalidate).toHaveBeenCalledWith({ queryKey: ['budget-comparisons', plan.id] }),
    );
  });
  it.each([
    [401, 'Votre session n’autorise pas cette opération.'],
    [409, 'Les données ont changé. Rechargez le plan avant de recommencer.'],
    [422, 'La demande est invalide ou une référence n’est plus utilisable.'],
    [0, 'L’opération a échoué. Réessayez.'],
  ])('renders lifecycle request error %i', async (status, message) => {
    api.listBudgetPlans.mockReturnValue(ok({ items: [plan], page: 1, perPage: 100, total: 1 }));
    api.readBudgetPlan.mockReturnValue(ok(detail({ targets: [target] })));
    api.activateBudgetPlan.mockReturnValue(
      status === 0
        ? Promise.resolve({ error: {} })
        : Promise.resolve({ error: {}, response: new Response('{}', { status }) }),
    );
    renderPage(plan.id);

    fireEvent.click(await screen.findByRole('button', { name: 'Activer' }));
    expect((await screen.findByRole('alert')).textContent).toContain(message);
  });
});
