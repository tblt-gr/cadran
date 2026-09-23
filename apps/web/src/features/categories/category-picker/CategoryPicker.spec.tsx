import type { Category } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { CategoryPicker } from './CategoryPicker';

const api = vi.hoisted(() => ({ listCategories: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const candidate: Category = {
  id: '00000000-0000-7000-8000-0000000000c2',
  type: 'EXPENSE',
  label: 'Sorties',
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

function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function renderPicker(onChange = vi.fn(), onCreateRequest?: (label: string) => void) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <CategoryPicker
        excludeId="00000000-0000-7000-8000-0000000000c1"
        label="Catégorie qui reçoit l’historique"
        onChange={onChange}
        onCreateRequest={onCreateRequest}
        type="EXPENSE"
        value=""
      />
    </QueryClientProvider>,
  );

  return onChange;
}

describe('CategoryPicker', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('narrows the choices from a bounded server search', async () => {
    api.listCategories.mockImplementation(({ query }) =>
      success({
        items: query.search === 'Sor' ? [candidate] : [],
        page: 1,
        perPage: 50,
        total: query.search === 'Sor' ? 1 : 0,
      }),
    );
    renderPicker();

    fireEvent.change(screen.getByLabelText('Catégorie qui reçoit l’historique'), {
      target: { value: 'Sor' },
    });

    expect(await screen.findByRole('option', { name: 'Sorties' })).toBeTruthy();
    await waitFor(() =>
      expect(api.listCategories).toHaveBeenCalledWith(
        expect.objectContaining({
          query: expect.objectContaining({ search: 'Sor', type: 'EXPENSE', parentEligible: false }),
        }),
      ),
    );
    // The list is a popup, so a search that matched must also say so in text for a
    // reader who never opens it.
    expect(await screen.findByText('1 catégorie correspondante.')).toBeTruthy();
  });

  it('says when a search matched nothing instead of looking inert', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    renderPicker();

    fireEvent.change(screen.getByLabelText('Catégorie qui reçoit l’historique'), {
      target: { value: 'Zzz' },
    });

    expect(
      await screen.findByText('Aucune catégorie ne correspond à cette recherche.'),
    ).toBeTruthy();
  });

  it('is driven entirely from the keyboard', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [candidate], page: 1, perPage: 50, total: 1 }),
    );
    const onChange = renderPicker();
    const combobox = screen.getByRole('combobox', { name: 'Catégorie qui reçoit l’historique' });

    fireEvent.change(combobox, { target: { value: 'Sor' } });
    await screen.findByRole('option', { name: 'Sorties' });
    expect(combobox.getAttribute('aria-expanded')).toBe('true');

    fireEvent.keyDown(combobox, { key: 'ArrowDown' });
    expect(combobox.getAttribute('aria-activedescendant')).toBeTruthy();
    fireEvent.keyDown(combobox, { key: 'Enter' });

    expect(onChange).toHaveBeenLastCalledWith(candidate.id, candidate);
    // The chosen label replaces the query, and the list closes.
    expect((combobox as HTMLInputElement).value).toBe('Sorties');
    expect(combobox.getAttribute('aria-expanded')).toBe('false');
    expect(screen.queryByRole('listbox')).toBeNull();
    expect(screen.queryByText('1 catégorie correspondante.')).toBeNull();
  });

  it('closes on Escape without choosing anything', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [candidate], page: 1, perPage: 50, total: 1 }),
    );
    const onChange = renderPicker();
    const combobox = screen.getByRole('combobox', { name: 'Catégorie qui reçoit l’historique' });

    fireEvent.change(combobox, { target: { value: 'Sor' } });
    await screen.findByRole('option', { name: 'Sorties' });
    fireEvent.keyDown(combobox, { key: 'Escape' });

    expect(screen.queryByRole('listbox')).toBeNull();
    expect(onChange).not.toHaveBeenCalledWith(candidate.id);
  });

  it('never offers the category the operation acts on', async () => {
    api.listCategories.mockImplementation(() =>
      success({
        items: [
          candidate,
          { ...candidate, id: '00000000-0000-7000-8000-0000000000c1', label: 'Restaurants' },
        ],
        page: 1,
        perPage: 50,
        total: 2,
      }),
    );
    renderPicker();
    fireEvent.change(screen.getByRole('combobox', { name: 'Catégorie qui reçoit l’historique' }), {
      target: { value: 'a' },
    });

    expect(await screen.findByRole('option', { name: 'Sorties' })).toBeTruthy();
    expect(screen.queryByRole('option', { name: 'Restaurants' })).toBeNull();
  });

  it('offers creation last and hands the searched label to the host', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [candidate], page: 1, perPage: 50, total: 1 }),
    );
    const onCreateRequest = vi.fn();
    renderPicker(vi.fn(), onCreateRequest);
    const picker = screen.getByRole('combobox', { name: 'Catégorie qui reçoit l’historique' });

    fireEvent.change(picker, { target: { value: ' Boulangerie ' } });

    const options = await screen.findAllByRole('option');
    expect(options.at(-1)?.textContent).toBe('Créer « Boulangerie »');
    fireEvent.mouseDown(options.at(-1) as HTMLElement);

    expect(onCreateRequest).toHaveBeenCalledWith('Boulangerie');
    expect(picker.getAttribute('aria-expanded')).toBe('false');

    // Focus coming back from the host's dialog leaves the list closed.
    picker.focus();
    expect(picker.getAttribute('aria-expanded')).toBe('false');
    fireEvent.mouseDown(picker);
    expect(picker.getAttribute('aria-expanded')).toBe('true');
  });

  it('does not offer to recreate the category already selected', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [candidate], page: 1, perPage: 50, total: 1 }),
    );
    const onCreateRequest = vi.fn();
    renderPicker(vi.fn(), onCreateRequest);
    const picker = screen.getByRole('combobox', { name: 'Catégorie qui reçoit l’historique' });

    fireEvent.focus(picker);
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Sorties' }));
    fireEvent.keyDown(picker, { key: 'ArrowDown' });
    fireEvent.mouseDown(screen.getByRole('option', { name: 'Créer une catégorie' }));

    expect(onCreateRequest).toHaveBeenCalledWith('');
  });

  it('groups both types when none is imposed, the preferred one first', async () => {
    const income: Category = {
      ...candidate,
      id: '00000000-0000-7000-8000-0000000000c3',
      type: 'INCOME',
      label: 'Salaire',
    };
    api.listCategories.mockImplementation(() =>
      success({ items: [candidate, income], page: 1, perPage: 50, total: 2 }),
    );
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <CategoryPicker label="Catégorie" onChange={vi.fn()} preferredType="INCOME" value="" />
      </QueryClientProvider>,
    );

    fireEvent.focus(screen.getByRole('combobox', { name: 'Catégorie' }));

    const listbox = await screen.findByRole('listbox');
    const groups = within(listbox).getAllByRole('group');
    expect(groups.map((group) => within(group).getAllByRole('option')[0]?.textContent)).toEqual([
      'Salaire',
      'Sorties',
    ]);
    expect(within(listbox).getByRole('group', { name: 'Revenus' })).toBe(groups[0]);
    expect(api.listCategories).toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ type: undefined }) }),
    );
  });

  it('marks the selection inside the field and carries the host message', () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <CategoryPicker
          error="Catégorie refusée."
          label="Catégorie"
          onChange={vi.fn()}
          selectedColor="#486DF0"
          selectedIcon="utensils"
          selectedLabel="Restaurants"
          value="00000000-0000-7000-8000-0000000000c1"
        />
      </QueryClientProvider>,
    );

    const combobox = screen.getByRole('combobox', { name: 'Catégorie' });
    // The field stays neutral; only the pill inside it carries the category colour, with
    // the glyph before the input in the same flex row, so the text cannot run under it.
    const chip = combobox.parentElement as HTMLElement;
    const control = chip.parentElement as HTMLElement;
    expect(chip.querySelector('[data-icon="utensils"]')?.getAttribute('aria-hidden')).toBe('true');
    expect(chip.style.background).not.toBe('');
    expect(chip.style.color).not.toBe('');
    expect(control.style.background).toBe('');
    expect(chip.firstElementChild?.getAttribute('data-icon')).toBe('utensils');
    expect(chip.firstElementChild?.nextElementSibling).toBe(combobox);
    expect((chip.firstElementChild as HTMLElement).style.background).toBe('');
    expect(control.nextElementSibling?.textContent).toBe('Catégorie refusée.');
    expect(combobox.getAttribute('aria-invalid')).toBe('true');
    expect(combobox.getAttribute('aria-describedby')).toBe(control.nextElementSibling?.id);
  });

  it('offers no creation without a host to handle it', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    renderPicker();

    fireEvent.change(screen.getByRole('combobox', { name: 'Catégorie qui reçoit l’historique' }), {
      target: { value: 'Boulangerie' },
    });

    expect(
      await screen.findByText('Aucune catégorie ne correspond à cette recherche.'),
    ).toBeTruthy();
    expect(screen.queryByRole('option')).toBeNull();
  });
});
