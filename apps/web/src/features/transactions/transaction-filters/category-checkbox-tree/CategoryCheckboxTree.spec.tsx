import type { Category } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
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

function renderTree(categoryIds: string[] = [], onCategoryIdsChange = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <CategoryCheckboxTree
        categoryIds={categoryIds}
        includeDescendants
        onCategoryIdsChange={onCategoryIdsChange}
        onIncludeDescendantsChange={vi.fn()}
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
});
