import type { Category, CategoryImpact, CategoryLifecycleOperation } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { CategoryRequestError } from '@/features/categories/categoryError';
import { CategoryLifecycleDialog } from './CategoryLifecycleDialog';

const api = vi.hoisted(() => ({
  listCategories: vi.fn(),
  previewCategoryImpact: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const category: Category = {
  id: '00000000-0000-7000-8000-0000000000c1',
  type: 'EXPENSE',
  label: 'Restaurants',
  parentId: null,
  parentLabel: null,
  icon: null,
  color: null,
  defaultAnalyticAxes: [],
  budgetIncluded: true,
  sortOrder: 0,
  depth: 1,
  version: 3,
  used: true,
  typeEditable: false,
  typeEditReason: 'USED',
  canAcceptChildren: true,
  archivedAt: null,
  replacement: null,
};

const target: Category = {
  ...category,
  id: '00000000-0000-7000-8000-0000000000c2',
  label: 'Sorties',
  version: 1,
};

function impact(overrides: Partial<CategoryImpact> = {}): CategoryImpact {
  return {
    operation: 'MERGE',
    categoryId: category.id,
    targetId: target.id,
    targetLabel: target.label,
    effectiveFrom: null,
    descendantCount: 2,
    archivedDescendantCount: 0,
    reparentedChildCount: 2,
    incomingRedirectionCount: 0,
    resultingDepth: 2,
    maximumDepth: 8,
    archivesSource: true,
    redirectsHistory: true,
    allowed: true,
    blockers: [],
    affectedClassifications: null,
    affectedClassificationsReason: 'TRANSACTIONS_UNAVAILABLE',
    ...overrides,
  };
}

function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

async function chooseTarget(label: string) {
  const combobox = screen.getByRole('combobox', { name: label });
  fireEvent.change(combobox, { target: { value: 'Sor' } });
  // Selection happens on mousedown so a pointer choice lands before the input blurs.
  fireEvent.mouseDown(await screen.findByRole('option', { name: 'Sorties' }));
}

function renderDialog(
  operation: CategoryLifecycleOperation,
  onConfirm = vi.fn(),
  submitError: unknown = null,
) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={queryClient}>
      <CategoryLifecycleDialog
        category={category}
        onCancel={vi.fn()}
        onConfirm={onConfirm}
        operation={operation}
        pending={false}
        submitError={submitError}
        submitFailed={submitError !== null}
      />
    </QueryClientProvider>,
  );

  return onConfirm;
}

describe('CategoryLifecycleDialog', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('waits for an operand before asking the backend for an impact', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [target], page: 1, perPage: 50, total: 1 }),
    );
    api.previewCategoryImpact.mockImplementation(() => success(impact()));
    renderDialog('MERGE');

    expect(
      screen.getByText('Choisissez les paramètres de l’opération pour afficher son impact.'),
    ).toBeTruthy();
    expect(api.previewCategoryImpact).not.toHaveBeenCalled();
    expect(
      screen.getByRole('button', { name: 'Fusionner' }).getAttribute('disabled'),
    ).not.toBeNull();

    await chooseTarget('Catégorie qui reçoit l’historique');

    expect(await screen.findByText('Impact de l’opération')).toBeTruthy();
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Fusionner' }).getAttribute('disabled')).toBeNull(),
    );
  });

  it('reports an uncountable history rather than an invented zero', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [target], page: 1, perPage: 50, total: 1 }),
    );
    api.previewCategoryImpact.mockImplementation(() => success(impact()));
    renderDialog('MERGE');

    await chooseTarget('Catégorie qui reçoit l’historique');

    expect(
      await screen.findByText('Non calculable : aucun historique de transactions n’existe encore.'),
    ).toBeTruthy();
    expect(screen.queryByText('Écritures reclassées')).toBeTruthy();
    expect(screen.getByText('2 sur 8')).toBeTruthy();
  });

  it('lists every refusal reason and refuses to confirm', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [target], page: 1, perPage: 50, total: 1 }),
    );
    api.previewCategoryImpact.mockImplementation(() =>
      success(impact({ allowed: false, blockers: ['DEPTH_EXCEEDED', 'SIBLING_LABEL_CONFLICT'] })),
    );
    const onConfirm = renderDialog('MERGE');

    await chooseTarget('Catégorie qui reçoit l’historique');

    expect(
      await screen.findByText('La branche dépasserait la profondeur maximale de l’arbre.'),
    ).toBeTruthy();
    expect(
      screen.getByText(
        'Un libellé identique existe déjà parmi les catégories sœurs actives visées.',
      ),
    ).toBeTruthy();
    const confirm = screen.getByRole('button', { name: 'Fusionner' });
    expect(confirm.getAttribute('disabled')).not.toBeNull();
    fireEvent.click(confirm);
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it('archives without asking for a target and confirms with the version it was opened on', async () => {
    api.previewCategoryImpact.mockImplementation(() =>
      success(
        impact({
          operation: 'ARCHIVE',
          targetId: null,
          targetLabel: null,
          redirectsHistory: false,
        }),
      ),
    );
    const onConfirm = renderDialog('ARCHIVE');

    expect(screen.queryByRole('combobox')).toBeNull();
    expect(await screen.findByText('Impact de l’opération')).toBeTruthy();

    const confirm = screen.getByRole('button', { name: 'Archiver' });
    await waitFor(() => expect(confirm.getAttribute('disabled')).toBeNull());
    fireEvent.click(confirm);

    expect(onConfirm).toHaveBeenCalledWith({
      effectiveFrom: '',
      operation: 'ARCHIVE',
      targetId: null,
    });
  });

  it('asks a dated replacement for both its target and its effective date', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [target], page: 1, perPage: 50, total: 1 }),
    );
    api.previewCategoryImpact.mockImplementation(() =>
      success(
        impact({
          operation: 'REPLACE',
          archivesSource: false,
          effectiveFrom: '2026-10-01',
          reparentedChildCount: 0,
        }),
      ),
    );
    const onConfirm = renderDialog('REPLACE');

    await chooseTarget('Catégorie qui prend le relais');
    expect(api.previewCategoryImpact).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText('Date d’effet'), { target: { value: '2026-10-01' } });
    expect(await screen.findByText('Impact de l’opération')).toBeTruthy();
    await waitFor(() =>
      expect(api.previewCategoryImpact.mock.calls[0]?.[0].query).toMatchObject({
        operation: 'REPLACE',
        targetId: target.id,
        effectiveFrom: '2026-10-01',
      }),
    );

    fireEvent.click(screen.getByRole('button', { name: 'Remplacer' }));
    expect(onConfirm).toHaveBeenCalledWith({
      effectiveFrom: '2026-10-01',
      operation: 'REPLACE',
      targetId: target.id,
    });
  });

  it('shows the reasons a confirmed operation was refused by the backend', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [target], page: 1, perPage: 50, total: 1 }),
    );
    api.previewCategoryImpact.mockImplementation(() => success(impact()));
    renderDialog(
      'MERGE',
      vi.fn(),
      new CategoryRequestError(
        422,
        { type: '/problems/category-operation-refused', title: 'x', status: 422, detail: 'y' },
        ['ALREADY_REDIRECTED'],
      ),
    );

    expect(
      screen.getByText('L’opération est refusée. Les raisons sont listées ci-dessous.'),
    ).toBeTruthy();
    expect(screen.getByText('Cette catégorie est déjà redirigée vers une autre.')).toBeTruthy();
  });

  it('surfaces a failing impact read instead of pretending the operation is safe', async () => {
    api.previewCategoryImpact.mockImplementation(() =>
      Promise.resolve({ error: undefined, response: new Response('{}', { status: 500 }) }),
    );
    renderDialog('ARCHIVE');

    expect(await screen.findByText('Impossible de calculer l’impact. Réessayez.')).toBeTruthy();
    expect(
      screen.getByRole('button', { name: 'Archiver' }).getAttribute('disabled'),
    ).not.toBeNull();
  });
});
