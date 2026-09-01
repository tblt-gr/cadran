import type { Category } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { ParentCategoryField } from './ParentCategoryField';

const api = vi.hoisted(() => ({ listCategories: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const parent: Category = {
  id: '00000000-0000-7000-8000-0000000000d1',
  type: 'EXPENSE',
  label: 'Logement',
  parentId: null,
  parentLabel: null,
  icon: null,
  color: null,
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
};

describe('ParentCategoryField', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('searches eligible parents independently from the current category page', async () => {
    api.listCategories.mockImplementation(({ query }) =>
      Promise.resolve({
        data: {
          items: query.search === 'Loge' ? [parent] : [],
          page: 1,
          perPage: 50,
          total: query.search === 'Loge' ? 1 : 0,
        },
        response: new Response('{}', { status: 200 }),
      }),
    );
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <ParentCategoryField onChange={vi.fn()} type="EXPENSE" value="" />
      </QueryClientProvider>,
    );

    fireEvent.change(screen.getByLabelText('Rechercher une catégorie parente'), {
      target: { value: 'Loge' },
    });

    expect(await screen.findByRole('option', { name: 'Logement' })).toBeTruthy();
    expect(api.listCategories).toHaveBeenCalledWith(
      expect.objectContaining({
        query: expect.objectContaining({
          parentEligible: true,
          search: 'Loge',
          type: 'EXPENSE',
          perPage: 50,
        }),
      }),
    );
  });
});
