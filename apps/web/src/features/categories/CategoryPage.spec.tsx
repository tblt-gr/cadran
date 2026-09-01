import type { Category } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { CategoryPage } from './CategoryPage';

const api = vi.hoisted(() => ({
  createCategory: vi.fn(),
  listCategories: vi.fn(),
  updateCategory: vi.fn(),
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
  icon: 'utensils',
  color: '#AABBCC',
  defaultAnalyticAxes: ['DISCRETIONARY', 'VARIABLE'],
  budgetIncluded: true,
  sortOrder: 10,
  depth: 1,
  version: 1,
  used: false,
  typeEditable: true,
  typeEditReason: null,
  canAcceptChildren: true,
  archivedAt: null,
};

function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <CategoryPage />
    </QueryClientProvider>,
  );
}

describe('CategoryPage', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('covers the empty state and creates a bounded category', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.createCategory.mockImplementation(({ body }) => success({ ...category, ...body }, 201));
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Aucune catégorie' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Créer la première catégorie' }));
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Restaurants' } });
    fireEvent.click(screen.getByLabelText('Discrétionnaire'));
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(api.createCategory).toHaveBeenCalledOnce());
    expect(api.createCategory.mock.calls[0]?.[0].body).toMatchObject({
      type: 'EXPENSE',
      label: 'Restaurants',
      parentId: null,
      defaultAnalyticAxes: ['DISCRETIONARY'],
      budgetIncluded: true,
    });
    expect(await screen.findByText('La catégorie a été enregistrée.')).toBeTruthy();
  });

  it('leaves the create form type field without a dangling description', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer la première catégorie' }));

    expect(screen.getByRole('combobox', { name: 'Type' }).getAttribute('aria-describedby')).toBe(
      null,
    );
  });

  it('names the parent from the response even when it sits on another page', async () => {
    api.listCategories.mockImplementation(() =>
      success({
        items: [
          {
            ...category,
            id: '00000000-0000-7000-8000-0000000000c2',
            label: 'Livraison',
            parentId: '00000000-0000-7000-8000-0000000000c1',
            parentLabel: 'Restaurants',
            depth: 2,
          },
        ],
        page: 1,
        perPage: 50,
        total: 1,
      }),
    );
    renderPage();

    expect(await screen.findByText('Restaurants')).toBeTruthy();
    expect(screen.queryByText('Parent non affiché')).toBeNull();
  });

  it('returns to a readable page when the last page becomes empty', async () => {
    api.listCategories.mockImplementation(({ query }) =>
      success({
        items:
          query.page === 1
            ? Array.from({ length: 50 }, (_, index) => ({
                ...category,
                id: `00000000-0000-7000-8000-${String(index).padStart(12, '0')}`,
                label: `Catégorie ${index}`,
              }))
            : [],
        page: query.page,
        perPage: 50,
        total: query.page === 1 ? 60 : 50,
      }),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Suivant' }));

    expect(await screen.findByText('Catégorie 0')).toBeTruthy();
    expect(screen.queryByRole('heading', { name: 'Aucune catégorie' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Précédent' }).hasAttribute('disabled')).toBe(true);
  });

  it('renders labels as text and keeps archived categories read-only', async () => {
    const crafted = {
      ...category,
      label: '<img src=x onerror=alert(1)>',
      archivedAt: '2026-09-01T12:00:00+00:00',
    };
    api.listCategories.mockImplementation(({ query }) =>
      success({
        items: query.includeArchived ? [crafted] : [],
        page: 1,
        perPage: 50,
        total: query.includeArchived ? 1 : 0,
      }),
    );
    renderPage();

    await screen.findByRole('heading', { name: 'Aucune catégorie' });
    fireEvent.click(screen.getByLabelText('Afficher les catégories archivées'));

    expect(await screen.findByText('<img src=x onerror=alert(1)>')).toBeTruthy();
    expect(document.querySelector('img')).toBeNull();
    expect(screen.getByText('Archivée')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Modifier' }).hasAttribute('disabled')).toBe(true);
  });

  it('shows an explicit unauthorized state', async () => {
    api.listCategories.mockResolvedValue({
      error: { status: 401 },
      response: new Response('{}', { status: 401 }),
    });
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Session expirée' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Réessayer' })).toBeNull();
  });

  it('explains a backend type-edit restriction', async () => {
    api.listCategories.mockImplementation(() =>
      success({
        items: [{ ...category, typeEditable: false, typeEditReason: 'HAS_CHILDREN' }],
        page: 1,
        perPage: 50,
        total: 1,
      }),
    );
    renderPage();

    await screen.findByText('Restaurants');
    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));

    expect(screen.getByRole('combobox', { name: 'Type' }).hasAttribute('disabled')).toBe(true);
    expect(
      screen.getByText('Le type est verrouillé car cette catégorie possède des sous-catégories.'),
    ).toBeTruthy();
  });
});
