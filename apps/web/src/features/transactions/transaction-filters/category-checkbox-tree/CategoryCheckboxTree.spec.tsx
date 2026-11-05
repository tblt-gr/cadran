import type { Category } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import type { OptionCounts } from '@/features/transactions/transaction-filters/facet-count/optionCount';
import { CategoryCheckboxTree } from './CategoryCheckboxTree';

const api = vi.hoisted(() => ({ listCategories: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

function category(overrides: Partial<Category>): Category {
  return {
    id: 'root',
    type: 'EXPENSE',
    label: 'Alimentation',
    parentId: null,
    parentLabel: null,
    icon: null,
    color: null,
    effectiveColor: '#486DF0',
    effectiveIcon: null,
    defaultAnalyticAxes: [],
    budgetIncluded: true,
    sortOrder: 0,
    depth: 1,
    version: 1,
    used: false,
    typeEditable: true,
    typeEditReason: null,
    canAcceptChildren: true,
    archivedAt: null,
    replacement: null,
    ...overrides,
  } as Category;
}

function respond(items: Category[]) {
  api.listCategories.mockImplementation(() =>
    Promise.resolve({
      data: { items, page: 1, pageSize: 100, total: items.length },
      response: new Response(null, { status: 200 }),
    }),
  );
}

function renderTree(
  categoryIds: string[] = [],
  onCategoryIdsChange = vi.fn(),
  uncategorized = false,
  onUncategorizedChange = vi.fn(),
  counts?: OptionCounts,
) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <CategoryCheckboxTree
        categoryIds={categoryIds}
        counts={counts}
        includeDescendants
        onCategoryIdsChange={onCategoryIdsChange}
        onIncludeDescendantsChange={vi.fn()}
        onUncategorizedChange={onUncategorizedChange}
        uncategorized={uncategorized}
      />
    </QueryClientProvider>,
  );
  return onCategoryIdsChange;
}

describe('CategoryCheckboxTree', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('nests sub-categories under their parent and checks one', async () => {
    respond([
      category({}),
      category({ id: 'child', label: 'Courses', parentId: 'root', depth: 2 }),
    ]);
    const onChange = renderTree();

    const child = await screen.findByRole('checkbox', { name: 'Courses' });
    expect(child.closest('ul')?.closest('li')?.textContent).toContain('Alimentation');

    fireEvent.click(child);
    expect(onChange).toHaveBeenCalledWith(['child']);
  });

  it('keeps archived categories out unless one is already selected', async () => {
    respond([
      category({}),
      category({ id: 'old', label: 'Ancienne', archivedAt: '2026-01-01T00:00:00Z' }),
      category({ id: 'gone', label: 'Disparue', archivedAt: '2026-01-01T00:00:00Z' }),
    ]);
    renderTree(['old']);

    expect(await screen.findByRole('checkbox', { name: 'Ancienne' })).toHaveProperty(
      'checked',
      true,
    );
    expect(screen.queryByRole('checkbox', { name: 'Disparue' })).toBeNull();
  });

  it('shows skeleton rows while loading and a retry when the lookup fails', async () => {
    api.listCategories.mockImplementation(() =>
      Promise.resolve({ data: undefined, response: new Response(null, { status: 500 }) }),
    );
    renderTree();
    expect(document.querySelectorAll('[data-skeleton]')).toHaveLength(3);

    expect(await screen.findByRole('alert')).toBeTruthy();
    respond([category({})]);
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByRole('checkbox', { name: 'Alimentation' })).toBeTruthy();
  });

  it('folds the top-level categories past six', async () => {
    respond(
      Array.from({ length: 8 }, (_, index) =>
        category({ id: `c${index}`, label: `Catégorie ${index}`, sortOrder: index }),
      ),
    );
    renderTree();

    await screen.findByRole('checkbox', { name: 'Catégorie 0' });
    expect(screen.queryByRole('checkbox', { name: 'Catégorie 7' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Afficher plus (2)' }));
    expect(screen.getByRole('checkbox', { name: 'Catégorie 7' })).toBeTruthy();
  });

  it('heads the tree with an uncategorized checkbox', async () => {
    respond([category({})]);
    const onUncategorizedChange = vi.fn();
    renderTree([], vi.fn(), false, onUncategorizedChange);

    const uncategorized = screen.getByRole('checkbox', { name: 'Sans catégorie' });
    await screen.findByRole('checkbox', { name: 'Alimentation' });
    expect(
      uncategorized.compareDocumentPosition(screen.getByRole('checkbox', { name: 'Alimentation' })),
    ).toBe(Node.DOCUMENT_POSITION_FOLLOWING);

    fireEvent.click(uncategorized);
    expect(onUncategorizedChange).toHaveBeenCalledWith(true);
  });

  describe('counts', () => {
    const tree = () =>
      respond([
        category({}),
        category({ id: 'child', label: 'Courses', parentId: 'root', depth: 2 }),
        category({ id: 'other', label: 'Transport', sortOrder: 1 }),
      ]);

    it('shows no count where the tree is given none', async () => {
      tree();
      renderTree();

      expect(await screen.findByRole('checkbox', { name: 'Courses' })).toBeTruthy();
      expect(screen.getByRole('group').textContent).not.toMatch(/\d/);
    });

    it('reads the count of every category after its label, nested ones included', async () => {
      tree();
      renderTree([], vi.fn(), false, vi.fn(), { root: 4, child: 3, other: 1 });

      expect(
        await screen.findByRole('checkbox', { name: 'Alimentation, 4 transactions' }),
      ).toBeTruthy();
      expect(screen.getByRole('checkbox', { name: 'Courses, 3 transactions' })).toBeTruthy();
      expect(screen.getByRole('checkbox', { name: 'Transport, 1 transaction' })).toBeTruthy();
    });

    it('mutes a category that keeps nothing without disabling it', async () => {
      tree();
      const onChange = renderTree([], vi.fn(), false, vi.fn(), { root: 4 });

      const empty = await screen.findByRole('checkbox', { name: 'Courses, 0 transaction' });
      expect(empty.closest('label')?.getAttribute('data-empty')).toBe('true');
      expect(empty).toHaveProperty('disabled', false);
      expect(
        screen
          .getByRole('checkbox', { name: 'Alimentation, 4 transactions' })
          .closest('label')
          ?.hasAttribute('data-empty'),
      ).toBe(false);

      fireEvent.click(empty);
      expect(onChange).toHaveBeenCalledWith(['child']);
    });

    it('counts neither the uncategorized choice nor the sub-category switch', async () => {
      tree();
      renderTree([], vi.fn(), false, vi.fn(), { root: 4, child: 3, other: 1 });
      await screen.findByRole('checkbox', { name: 'Alimentation, 4 transactions' });

      expect(screen.getByRole('checkbox', { name: 'Sans catégorie' })).toBeTruthy();
      expect(screen.getByRole('checkbox', { name: 'Inclure les sous-catégories' })).toBeTruthy();
    });

    it('shows a skeleton count on every category while the first answer is pending', async () => {
      tree();
      const onChange = renderTree([], vi.fn(), false, vi.fn(), null);

      const first = await screen.findByRole('checkbox', { name: 'Alimentation' });
      expect(document.querySelectorAll('[data-skeleton]')).toHaveLength(3);
      expect(first).toHaveProperty('disabled', false);

      fireEvent.click(first);
      expect(onChange).toHaveBeenCalledWith(['root']);
    });

    it('gives the loading rows a count placeholder only when counts are asked for', () => {
      api.listCategories.mockImplementation(() => new Promise(() => undefined));
      renderTree();
      expect(document.querySelectorAll('[data-skeleton]')).toHaveLength(3);
      cleanup();

      renderTree([], vi.fn(), false, vi.fn(), null);
      expect(document.querySelectorAll('[data-skeleton]')).toHaveLength(6);
    });
  });
});
