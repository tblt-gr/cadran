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
  api.saveAnnualReportPreferences.mockResolvedValue(ok({ ...annualPreferences, version: 4 }));
  api.listCategories.mockResolvedValue(page('Courses'));
  api.listAccountGroups.mockResolvedValue(page('Épargne'));
  api.listAccounts.mockResolvedValue(page('Compte courant'));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function renderModal() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const close = vi.fn();
  render(
    <QueryClientProvider client={client}>
      <ColumnPickerModal close={close} onConflict={vi.fn()} preferences={annualPreferences} />
    </QueryClientProvider>,
  );
  return { close };
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
    ).toBe(annualPreferences.columns.length * 3);
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
    const labels = within(screen.getByRole('list')).getAllByRole('listitem');
    expect(labels[labels.length - 1]!.textContent).toContain('Courses');
  });
});
