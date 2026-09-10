import type { Category, CategoryPage } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createRef } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { QuickCategoryDialog } from './QuickCategoryDialog';

const api = vi.hoisted(() => ({ createCategory: vi.fn(), listCategories: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const category: Category = {
  id: '00000000-0000-7000-8000-0000000000c9',
  type: 'EXPENSE',
  label: 'Boulangerie',
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
  replacement: null,
};

function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function renderDialog(queryClient = new QueryClient(), parents: Category[] = []) {
  const close = vi.fn();
  const onCreated = vi.fn();
  api.listCategories.mockImplementation(({ query }) => {
    const items = parents.filter((parent) => parent.type === query.type);
    return success({ items, page: 1, perPage: 50, total: items.length });
  });
  render(
    <QueryClientProvider client={queryClient}>
      <QuickCategoryDialog
        close={close}
        initialLabel="Boulangerie"
        initialType="EXPENSE"
        onCreated={onCreated}
        returnFocus={createRef<HTMLElement>()}
      />
    </QueryClientProvider>,
  );

  return { close, onCreated };
}

describe('QuickCategoryDialog', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it.each([
    [403, 'Votre session a expiré ou n’autorise pas cette création.'],
    [404, 'La création a échoué.'],
    [409, 'Ce libellé existe déjà sous ce parent.'],
    [422, 'Vérifiez le libellé, le type et la catégorie parente.'],
  ])('keeps the dialog open with an actionable message after a %i', async (status, message) => {
    api.createCategory.mockResolvedValue({
      error: { type: '/problems/category-request' },
      response: new Response('{}', { status }),
    });
    const { close, onCreated } = renderDialog();

    fireEvent.click(screen.getByRole('button', { name: 'Créer' }));

    expect((await screen.findByRole('alert')).textContent).toContain(message);
    expect(screen.getByRole('dialog', { name: 'Nouvelle catégorie' })).toBeTruthy();
    expect(close).not.toHaveBeenCalled();
    expect(onCreated).not.toHaveBeenCalled();
  });

  it('keeps the dialog open after a network failure', async () => {
    api.createCategory.mockRejectedValue(new TypeError('offline'));
    const { close } = renderDialog();

    fireEvent.click(screen.getByRole('button', { name: 'Créer' }));

    expect((await screen.findByRole('alert')).textContent).toContain('Vérifiez la connexion');
    expect(screen.getByRole('dialog', { name: 'Nouvelle catégorie' })).toBeTruthy();
    expect(close).not.toHaveBeenCalled();
  });

  it('validates the label locally without issuing a request', async () => {
    const queryClient = new QueryClient();
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    render(
      <QueryClientProvider client={queryClient}>
        <QuickCategoryDialog
          close={vi.fn()}
          initialLabel=""
          initialType="EXPENSE"
          onCreated={vi.fn()}
          returnFocus={createRef<HTMLElement>()}
        />
      </QueryClientProvider>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Créer' }));

    expect(screen.getByText('Le libellé doit contenir entre 1 et 80 caractères.')).toBeTruthy();
    expect(api.createCategory).not.toHaveBeenCalled();
  });

  it('publishes the created category to list and picker caches before closing', async () => {
    const queryClient = new QueryClient();
    const emptyPage: CategoryPage = { items: [], page: 1, perPage: 50, total: 0 };
    const listKey = ['categories', false, 1] as const;
    const pickerKey = ['category-candidates', 'EXPENSE', false, 'Boulangerie'] as const;
    queryClient.setQueryData(listKey, emptyPage);
    queryClient.setQueryData(pickerKey, emptyPage);
    api.createCategory.mockImplementation(() => success(category, 201));
    const { close, onCreated } = renderDialog(queryClient);

    fireEvent.click(screen.getByRole('button', { name: 'Créer' }));

    await waitFor(() => expect(onCreated).toHaveBeenCalledWith(category));
    expect(queryClient.getQueryData<CategoryPage>(listKey)?.items).toEqual([category]);
    expect(queryClient.getQueryData<CategoryPage>(pickerKey)?.items).toEqual([category]);
    expect(close).toHaveBeenCalledOnce();
  });

  it('can be closed while its request is still pending', async () => {
    api.createCategory.mockImplementation(() => new Promise(() => undefined));
    const { close } = renderDialog();

    fireEvent.click(screen.getByRole('button', { name: 'Créer' }));
    expect(await screen.findByRole('button', { name: 'Création…' })).toBeTruthy();
    fireEvent.keyDown(document, { key: 'Escape' });

    expect(close).toHaveBeenCalledOnce();
  });

  it('drops the chosen parent, on screen and in the payload, when the type changes', async () => {
    const parent: Category = {
      ...category,
      id: '00000000-0000-7000-8000-0000000000c1',
      label: 'Alimentation',
    };
    api.createCategory.mockImplementation(() => success(category, 201));
    renderDialog(new QueryClient(), [parent]);
    const parentField = screen.getByRole('combobox', { name: 'Catégorie parente' });

    parentField.focus();
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Alimentation' }));
    expect((parentField as HTMLInputElement).value).toBe('Alimentation');
    fireEvent.change(screen.getByRole('combobox', { name: /^Type/ }), {
      target: { value: 'INCOME' },
    });

    expect(
      (screen.getByRole('combobox', { name: 'Catégorie parente' }) as HTMLInputElement).value,
    ).toBe('');
    fireEvent.click(screen.getByRole('button', { name: 'Créer' }));
    await waitFor(() => expect(api.createCategory).toHaveBeenCalledOnce());
    expect(api.createCategory.mock.calls[0]?.[0].body).toMatchObject({
      type: 'INCOME',
      parentId: null,
    });
  });

  it('warns before creation when the type no longer matches the draft', () => {
    renderDialog();
    const type = screen.getByRole('combobox', { name: 'Type' });

    fireEvent.change(type, { target: { value: 'INCOME' } });

    const hint = screen.getByText(
      'Ce mouvement attend une catégorie de type Dépense : celle-ci sera créée sans être sélectionnée.',
    );
    expect(type.getAttribute('aria-describedby')).toBe(hint.id);
  });
});
