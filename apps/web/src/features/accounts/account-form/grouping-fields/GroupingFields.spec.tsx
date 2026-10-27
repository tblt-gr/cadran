import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GroupingFields } from './GroupingFields';

const api = vi.hoisted(() => ({ listAccountGroups: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const share = { percentage: null, status: 'EXCLUDED' };

function group(id: string, label: string, parentId: string | null, sortOrder: number) {
  return { id, label, parentId, sortOrder, depth: parentId === null ? 1 : 2, share };
}

const groups = [
  group('bourse', 'Bourse', 'inv', 20),
  group('cash', 'Liquidités', null, 20),
  group('immo', 'Immobilier', 'inv', 10),
  group('inv', 'Investissement', null, 10),
];

function page(items: unknown[], pageNumber: number, total: number) {
  return Promise.resolve({
    data: { items, page: pageNumber, pageSize: 100, total },
    response: new Response('{}', { status: 200 }),
  });
}

function renderFields(props: { includeInNetWorth?: boolean; primaryGroupId?: string } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <GroupingFields
        includeInNetWorth={props.includeInNetWorth ?? true}
        onPrimaryChange={vi.fn()}
        primaryGroupId={props.primaryGroupId ?? 'inv'}
      />
    </QueryClientProvider>,
  );
}

describe('GroupingFields', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('lists every group as a depth-first tree with its full path as accessible name', async () => {
    api.listAccountGroups.mockImplementation(() => page(groups, 1, 4));
    renderFields();

    const bourse = await screen.findByRole('option', { name: 'Investissement › Bourse' });
    expect(bourse.textContent).toBe('  Bourse');
    const options = screen.getAllByRole('option').map((option) => option.getAttribute('value'));
    expect(options).toEqual(['inv', 'immo', 'bourse', 'cash']);
    expect(screen.getByRole('option', { name: 'Investissement' }).textContent).toBe(
      'Investissement',
    );
  });

  it('loads every page of groups', async () => {
    api.listAccountGroups.mockImplementation(({ query }) =>
      query.page === 1 ? page(groups.slice(0, 2), 1, 150) : page(groups.slice(2), 2, 150),
    );
    renderFields();

    await screen.findByRole('option', { name: 'Liquidités' });
    expect(api.listAccountGroups).toHaveBeenCalledTimes(2);
  });

  it('shows no tags fieldset and explains the most precise sub-group rule', async () => {
    api.listAccountGroups.mockImplementation(() => page(groups, 1, 4));
    renderFields();

    await screen.findByRole('option', { name: 'Liquidités' });
    expect(screen.queryByText(/Étiquettes/)).toBeNull();
    expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    expect(
      screen.getByText(
        'Choisissez le sous-groupe le plus précis : le compte compte aussi dans tous ses groupes parents.',
      ),
    ).toBeTruthy();
  });

  it('keeps the select disabled while loading and reports a load error', async () => {
    api.listAccountGroups.mockImplementation(() => new Promise(() => undefined));
    const { unmount } = renderFields();
    expect((screen.getByLabelText('Groupe') as HTMLSelectElement).disabled).toBe(true);
    unmount();

    api.listAccountGroups.mockImplementation(() =>
      Promise.resolve({ response: new Response('{}', { status: 500 }) }),
    );
    renderFields();
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('groupes'));
  });
});
