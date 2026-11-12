import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { annualPreferences } from './annualFixtures';
import { ColumnPickerModal } from './ColumnPickerModal';

const api = vi.hoisted(() => ({
  saveAnnualReportPreferences: vi.fn(),
  listCategories: vi.fn(),
  listAccountGroups: vi.fn(),
  listAccounts: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

const ok = <T,>(data: T) => ({ data, response: { ok: true, status: 200 } });
const page = (label: string) => ok({ items: [{ id: `${label}-1`, label }], total: 1 });

beforeEach(() => {
  HTMLElement.prototype.scrollIntoView = vi.fn();
  api.saveAnnualReportPreferences.mockResolvedValue(ok({ ...annualPreferences, version: 4 }));
  api.listCategories.mockResolvedValue(page('Courses'));
  api.listAccountGroups.mockResolvedValue(page('Épargne'));
  api.listAccounts.mockResolvedValue(page('Compte courant'));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function renderModal(preferences = annualPreferences) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const close = vi.fn();
  const onConflict = vi.fn();
  render(
    <QueryClientProvider client={client}>
      <ColumnPickerModal close={close} onConflict={onConflict} preferences={preferences} />
    </QueryClientProvider>,
  );
  return { close, onConflict };
}

describe('ColumnPickerModal', () => {
  it('splits the choices into tabs: indicators, axis, categories, accounts, groups', () => {
    renderModal();
    const tabs = within(screen.getByRole('tablist')).getAllByRole('tab');
    expect(tabs.map((tab) => tab.textContent)).toEqual([
      'Indicateurs',
      'Axe',
      'Catégories',
      'Comptes',
      'Groupes',
    ]);
    expect(screen.queryByRole('group')).toBeNull(); // no per-section fieldset any more
  });

  it('shows the placeholders of the workspace entries while they load, then the entries', async () => {
    renderModal();
    fireEvent.click(screen.getByRole('tab', { name: 'Comptes' }));
    expect(await screen.findByLabelText('Compte courant')).toBeTruthy();
    fireEvent.click(screen.getByRole('tab', { name: 'Groupes' }));
    expect(await screen.findByLabelText('Épargne')).toBeTruthy();
  });

  it('labels each group with its full path, in saved depth-first order', async () => {
    const group = (id: string, label: string, parentId: string | null, sortOrder: number) => ({
      id,
      label,
      parentId,
      sortOrder,
    });
    api.listAccountGroups.mockResolvedValue(
      ok({
        items: [
          group('bourse', 'Bourse', 'inv', 20),
          group('cash', 'Liquidités', null, 20),
          group('immo', 'Immobilier', 'inv', 10),
          group('inv', 'Investissement', null, 10),
        ],
        total: 4,
        pageSize: 100,
      }),
    );
    renderModal();
    fireEvent.click(screen.getByRole('tab', { name: 'Groupes' }));

    const bourse = await screen.findByLabelText('Investissement › Bourse');
    expect(bourse.closest('label')?.textContent).toBe('Bourse (Investissement)');
    const labels = screen
      .getAllByRole('checkbox', {
        name: (name) => name !== '' && !name.startsWith('Afficher les objectifs'),
      })
      .map((box) => box.getAttribute('aria-label'));
    expect(labels).toEqual([
      'Investissement',
      'Investissement › Immobilier',
      'Investissement › Bourse',
      'Liquidités',
    ]);
    expect(screen.getByLabelText('Liquidités').closest('label')?.textContent).toBe('Liquidités');
  });

  it('preserves and saves an existing enabled goal colouring preference', async () => {
    renderModal();
    const box = screen.getByRole('checkbox', {
      name: /^Afficher les objectifs/,
    }) as HTMLInputElement;
    expect(box.checked).toBe(true);
    fireEvent.click(box);
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.saveAnnualReportPreferences).toHaveBeenCalledWith(
        expect.objectContaining({ body: expect.objectContaining({ goalMarks: false }) }),
      ),
    );
  });

  it('keeps the selection across tabs and saves it all at once', async () => {
    renderModal();
    fireEvent.click(screen.getByRole('tab', { name: 'Catégories' }));
    fireEvent.click(await screen.findByLabelText('Courses'));
    fireEvent.click(screen.getByRole('tab', { name: 'Comptes' }));
    fireEvent.click(await screen.findByLabelText('Compte courant'));
    fireEvent.click(screen.getByRole('tab', { name: 'Catégories' }));
    expect((screen.getByLabelText('Courses') as HTMLInputElement).checked).toBe(true);
    const before = annualPreferences.columns.length;
    expect(screen.getByText(`${before + 2} / 40`)).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.saveAnnualReportPreferences).toHaveBeenCalledWith(
        expect.objectContaining({
          body: expect.objectContaining({
            columns: [
              ...annualPreferences.columns,
              'category:Courses-1',
              'account:Compte courant-1',
            ],
          }),
        }),
      ),
    );
  });

  it('offers a retry inside the tab when the catalogue is unavailable', async () => {
    api.listCategories.mockResolvedValue({ data: undefined, response: { ok: false, status: 500 } });
    renderModal();
    fireEvent.click(screen.getByRole('tab', { name: 'Catégories' }));
    expect((await screen.findAllByText('Le catalogue est indisponible.')).length).toBeGreaterThan(
      0,
    );
    expect(screen.getAllByRole('button', { name: 'Réessayer' }).length).toBeGreaterThan(0);
  });

  it('lists the selected columns in one ordered list, with move and remove buttons', async () => {
    renderModal();
    const list = screen.getByRole('list');
    expect(within(list).getAllByRole('listitem')).toHaveLength(annualPreferences.columns.length);
    expect(
      within(
        screen.getByRole('heading', { name: 'Colonnes sélectionnées' }).parentElement!,
      ).getAllByRole('button').length,
    ).toBe(annualPreferences.columns.length * 4);
  });

  it('moves a column up or down and saves the chosen order', async () => {
    renderModal();
    const items = () =>
      within(screen.getByRole('list'))
        .getAllByRole('listitem')
        .map((item) => item.textContent);
    const [first, second] = items();
    // The first row cannot go up, the last cannot go down.
    const rows = within(screen.getByRole('list')).getAllByRole('listitem');
    expect(
      (within(rows[0]!).getByRole('button', { name: /Monter/ }) as HTMLButtonElement).disabled,
    ).toBe(true);
    expect(
      (
        within(rows[rows.length - 1]!).getByRole('button', {
          name: /Descendre/,
        }) as HTMLButtonElement
      ).disabled,
    ).toBe(true);

    fireEvent.click(within(rows[0]!).getByRole('button', { name: /Descendre/ }));
    expect(items().slice(0, 2)).toEqual([second, first]);

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.saveAnnualReportPreferences).toHaveBeenCalledWith(
        expect.objectContaining({
          body: expect.objectContaining({
            columns: [
              annualPreferences.columns[1],
              annualPreferences.columns[0],
              ...annualPreferences.columns.slice(2),
            ],
          }),
        }),
      ),
    );
  });

  it('removes a column from the selection and lets it come back at the end', async () => {
    renderModal();
    const rows = within(screen.getByRole('list')).getAllByRole('listitem');
    fireEvent.click(within(rows[0]!).getByRole('button', { name: /Retirer/ }));
    expect(within(screen.getByRole('list')).getAllByRole('listitem')).toHaveLength(
      annualPreferences.columns.length - 1,
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Catégories' }));
    fireEvent.click(await screen.findByLabelText('Courses'));
    const selectedList = within(
      screen.getByRole('heading', { name: 'Colonnes sélectionnées' }).parentElement!,
    ).getByRole('list');
    const labels = within(selectedList).getAllByRole('listitem');
    expect(labels[labels.length - 1]!.textContent).toContain('Courses');
  });
  it('draws the saved categories as a tree and keeps archived selections and the 40 limit', async () => {
    const entry = (id: string, label: string, parentId: string | null, sortOrder: number) => ({
      id,
      label,
      parentId,
      sortOrder,
      effectiveColor: '#336699',
    });
    api.listCategories.mockResolvedValue(
      ok({
        items: [
          entry('child', 'Courses', 'root', 1),
          entry('root', 'Alimentation', null, 1),
          entry('old', 'Ancienne', null, 2),
        ],
        total: 3,
      }),
    );
    const columns = [
      ...Array.from({ length: 38 }, (_, index) => `account:filler-${index}`),
      'category:old',
      'category:root',
    ];
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <ColumnPickerModal
          close={vi.fn()}
          onConflict={vi.fn()}
          preferences={{ ...annualPreferences, columns }}
        />
      </QueryClientProvider>,
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Catégories' }));
    const child = (await screen.findByRole('checkbox', {
      name: 'Alimentation › Courses',
    })) as HTMLInputElement;
    expect(screen.getByText('40 / 40')).toBeTruthy();
    expect(child.disabled).toBe(true);
    const parent = screen.getByRole('checkbox', { name: 'Alimentation' }) as HTMLInputElement;
    expect(parent.checked).toBe(true);
    expect((screen.getByRole('checkbox', { name: 'Ancienne' }) as HTMLInputElement).checked).toBe(
      true,
    );
    fireEvent.click(parent);
    expect(child.checked).toBe(false);
    expect(child.disabled).toBe(false);
  });
});

describe('reordered column drafts', () => {
  const preferences = {
    ...annualPreferences,
    columns: ['cashIncome', 'budgetExpenses', 'cashSavingsRate', 'endNetWorth'],
  };
  const moved = ['budgetExpenses', 'cashSavingsRate', 'endNetWorth', 'cashIncome'];
  function moveToEnd() {
    fireEvent.keyDown(screen.getByRole('button', { name: 'Déplacer : Revenus' }), { key: 'End' });
  }

  it('saves a long-distance move without changing settings or the concurrency version', async () => {
    renderModal(preferences);
    moveToEnd();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.saveAnnualReportPreferences).toHaveBeenCalledWith(
        expect.objectContaining({ body: { ...preferences, columns: moved } }),
      ),
    );
  });

  it('discards an unsaved reorder on cancel', () => {
    const { close } = renderModal(preferences);
    moveToEnd();
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }));
    expect(close).toHaveBeenCalledOnce();
    expect(api.saveAnnualReportPreferences).not.toHaveBeenCalled();
  });

  it('retains the reordered draft after save failure and retries the exact same payload', async () => {
    api.saveAnnualReportPreferences.mockResolvedValueOnce({ response: { ok: false, status: 500 } });
    const { close } = renderModal(preferences);
    moveToEnd();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByRole('alert')).toHaveProperty(
      'textContent',
      'L’enregistrement a échoué. Vos modifications sont conservées : réessayez d’enregistrer.',
    );
    expect(close).not.toHaveBeenCalled();
    const rows = screen.getAllByRole('listitem');
    expect(rows.at(-1)?.textContent).toBe('Revenus');
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(close).toHaveBeenCalledOnce());
    expect(api.saveAnnualReportPreferences).toHaveBeenCalledTimes(2);
    expect(api.saveAnnualReportPreferences.mock.calls[0]?.[0].body).toEqual({
      ...preferences,
      columns: moved,
    });
    expect(api.saveAnnualReportPreferences.mock.calls[1]?.[0].body).toEqual({
      ...preferences,
      columns: moved,
    });
  });

  it('retains version-conflict handling instead of overwriting a newer order', async () => {
    api.saveAnnualReportPreferences.mockResolvedValueOnce({ response: { ok: false, status: 409 } });
    const { close, onConflict } = renderModal(preferences);
    moveToEnd();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(onConflict).toHaveBeenCalledOnce());
    expect(close).toHaveBeenCalledOnce();
    expect(api.saveAnnualReportPreferences).toHaveBeenCalledTimes(1);
  });
});
