import type { Category } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { SplitEditor } from './SplitEditor';
import { emptySplitRow } from './splitAllocation';
import type { SplitRowValues } from './SplitRow';

const api = vi.hoisted(() => ({
  listAssets: vi.fn(),
  listCategories: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function renderEditor(
  rows: SplitRowValues[],
  props: { showErrors?: boolean; total?: string } = {},
) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const onChange = vi.fn();
  const view = render(
    <QueryClientProvider client={queryClient}>
      <SplitEditor
        assetCode="EUR"
        categoryType="EXPENSE"
        onChange={onChange}
        rows={rows}
        showErrors={props.showErrors ?? false}
        total={props.total ?? '-87.40'}
      />
    </QueryClientProvider>,
  );

  return { onChange, ...view };
}

describe('SplitEditor', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('shows the exact remaining amount as rows are added', async () => {
    api.listAssets.mockImplementation(() =>
      success({ items: [{ code: 'EUR', displayPrecision: 2 }], page: 1, perPage: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );

    renderEditor([
      { ...emptySplitRow(), amount: '-62.10' },
      { ...emptySplitRow(), amount: '-18.30' },
    ]);

    const remaining = await screen.findByRole('status');
    expect(remaining.textContent).toContain('7,00');
  });

  it('adds an empty row on click of the add-row button', async () => {
    api.listAssets.mockImplementation(() =>
      success({ items: [{ code: 'EUR', displayPrecision: 2 }], page: 1, perPage: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );

    const rows = [{ ...emptySplitRow(), amount: '-62.10' }];
    const { onChange } = renderEditor(rows);

    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter une ligne' }));
    // The new row gets its own generated key, so compare everything else.
    const [calledWith] = onChange.mock.calls.at(-1) as [SplitRowValues[]];
    expect(calledWith).toHaveLength(2);
    expect(calledWith[0]).toEqual(rows[0]);
    expect(calledWith[1]).toMatchObject({
      amount: '',
      analyticAxes: null,
      categoryId: '',
      note: '',
    });
  });

  it('disables the add-row button once the set reaches the twenty-row bound', async () => {
    api.listAssets.mockImplementation(() =>
      success({ items: [{ code: 'EUR', displayPrecision: 2 }], page: 1, perPage: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );

    const rows = Array.from({ length: 20 }, () => emptySplitRow());
    renderEditor(rows);

    const addButton = await screen.findByRole('button', { name: 'Ajouter une ligne' });
    expect((addButton as HTMLButtonElement).disabled).toBe(true);
  });

  it('removes a row on click of its remove button', async () => {
    api.listAssets.mockImplementation(() =>
      success({ items: [{ code: 'EUR', displayPrecision: 2 }], page: 1, perPage: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );

    const rows = [
      { ...emptySplitRow(), amount: '-62.10' },
      { ...emptySplitRow(), amount: '-25.30' },
    ];
    const { onChange } = renderEditor(rows);

    fireEvent.click(await screen.findByRole('button', { name: 'Supprimer la ligne 1' }));
    expect(onChange).toHaveBeenCalledWith([rows[1]]);
  });

  it('announces a non-zero remainder as an error once submission is attempted', async () => {
    api.listAssets.mockImplementation(() =>
      success({ items: [{ code: 'EUR', displayPrecision: 2 }], page: 1, perPage: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );

    renderEditor([{ ...emptySplitRow(), amount: '-62.10' }], { showErrors: true });

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(
      'La répartition doit atteindre exactement le montant de la transaction',
    );
  });

  it('assigns the remainder to a row so the allocation balances exactly', async () => {
    api.listAssets.mockImplementation(() =>
      success({ items: [{ code: 'EUR', displayPrecision: 2 }], page: 1, perPage: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );

    const rows = [
      { ...emptySplitRow(), amount: '-62.10' },
      { ...emptySplitRow(), amount: '-10.00' },
    ];
    const { onChange } = renderEditor(rows);

    const assignButtons = await screen.findAllByRole('button', {
      name: 'Assigner le reste à cette ligne',
    });
    fireEvent.click(assignButtons[1]);
    expect(onChange).toHaveBeenCalledWith([rows[0], { ...rows[1], amount: '-25.30' }]);
  });

  it('splits evenly across the rows once the asset precision is known', async () => {
    api.listAssets.mockImplementation(() =>
      success({ items: [{ code: 'EUR', displayPrecision: 2 }], page: 1, perPage: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );

    const rows = [emptySplitRow(), emptySplitRow(), emptySplitRow()];
    const { onChange } = renderEditor(rows, { total: '-100.00' });

    const splitButton = (await screen.findByRole('button', {
      name: 'Répartir également',
    })) as HTMLButtonElement;
    await waitFor(() => expect(splitButton.disabled).toBe(false));
    fireEvent.click(splitButton);

    expect(onChange).toHaveBeenCalledWith([
      { ...rows[0], amount: '-33.34' },
      { ...rows[1], amount: '-33.33' },
      { ...rows[2], amount: '-33.33' },
    ]);
  });

  it('keeps each remaining row on its own category after a middle row is removed', async () => {
    const categoryA = { id: 'cat-a', label: 'Alimentation', archivedAt: null } as Category;
    const categoryB = { id: 'cat-b', label: 'Beauté', archivedAt: null } as Category;
    const categoryC = { id: 'cat-c', label: 'Culture', archivedAt: null } as Category;
    api.listAssets.mockImplementation(() =>
      success({ items: [{ code: 'EUR', displayPrecision: 2 }], page: 1, perPage: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [categoryA, categoryB, categoryC], page: 1, perPage: 50, total: 3 }),
    );

    function ControlledEditor() {
      const [rows, setRows] = useState([
        { ...emptySplitRow(), amount: '-10.00' },
        { ...emptySplitRow(), amount: '-10.00' },
        { ...emptySplitRow(), amount: '-10.00' },
      ]);

      return (
        <SplitEditor
          assetCode="EUR"
          categoryType="EXPENSE"
          onChange={setRows}
          rows={rows}
          showErrors={false}
          total="-30.00"
        />
      );
    }

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <ControlledEditor />
      </QueryClientProvider>,
    );

    async function pick(rowLabel: string, categoryLabel: string) {
      const combobox = await screen.findByRole('combobox', { name: rowLabel });
      fireEvent.change(combobox, { target: { value: categoryLabel } });
      fireEvent.mouseDown(await screen.findByRole('option', { name: categoryLabel }));
    }

    await pick('Catégorie de la ligne 1', 'Alimentation');
    await pick('Catégorie de la ligne 2', 'Beauté');
    await pick('Catégorie de la ligne 3', 'Culture');

    fireEvent.click(screen.getByRole('button', { name: 'Supprimer la ligne 1' }));

    const remainingFirst = (await screen.findByRole('combobox', {
      name: 'Catégorie de la ligne 1',
    })) as HTMLInputElement;
    const remainingSecond = screen.getByRole('combobox', {
      name: 'Catégorie de la ligne 2',
    }) as HTMLInputElement;
    expect(remainingFirst.value).toBe('Beauté');
    expect(remainingSecond.value).toBe('Culture');
  });
});
