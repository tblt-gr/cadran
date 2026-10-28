import type { AccountGroup } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountGroupsPage } from './AccountGroupsPage';

const api = vi.hoisted(() => ({
  archiveAccountGroup: vi.fn(),
  createAccountGroup: vi.fn(),
  listAccountGroups: vi.fn(),
  listAccounts: vi.fn(),
  moveAccountGroup: vi.fn(),
  readNetWorth: vi.fn(),
  updateAccountGroup: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const group: AccountGroup = {
  color: null,
  colorMode: 'AUTO',
  resolvedColor: '#486DF0',
  defaultColor: '#486DF0',
  parentResolvedColor: null,
  id: '00000000-0000-7000-8000-0000000000b1',
  label: 'Épargne',
  parentId: null,
  parentLabel: null,
  sortOrder: 0,
  depth: 1,
  version: 1,
  hasChildren: false,
  canAcceptChildren: true,
  share: { ratio: null, percent: null, percentDisplay: null, reason: 'MISSING_VALUATION' },
  archivedAt: null,
};

function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function problem(status: number, type: string) {
  const body = { type, title: 'Conflit', status, detail: 'Conflit' };

  return Promise.resolve({
    data: undefined,
    error: body,
    response: new Response(JSON.stringify(body), { status }),
  });
}

/** The narrow no-break space Intl inserts between groups of digits. */
function openGroupActions(label: string) {
  fireEvent.click(screen.getByRole('button', { name: `Actions du groupe ${label}` }));
}

/** The skeleton draws the same tree: wait for it to leave before reading rows. */
async function loadedGroupsTree() {
  await waitFor(() => expect(screen.queryByTestId('account-groups-skeleton')).toBeNull());
  return screen.getByRole('list', { name: 'Groupes de comptes' });
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        <AccountGroupsPage />
      </QueryClientProvider>,
    ),
    queryClient,
  };
}

describe('AccountGroupsPage', () => {
  beforeEach(() => {});

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('replaces its one named cold-load skeleton and keeps groups during a refetch', async () => {
    let resolveGroups: ((value: unknown) => void) | undefined;
    api.listAccountGroups.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveGroups = resolve;
        }),
    );
    const { queryClient } = renderPage();

    expect(screen.getByTestId('account-groups-skeleton')).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    resolveGroups?.({
      data: { items: [group], page: 1, pageSize: 50, total: 1 },
      response: new Response('{}', { status: 200 }),
    });
    expect(await screen.findByText(group.label)).toBeTruthy();
    expect(screen.queryByTestId('account-groups-skeleton')).toBeNull();

    api.listAccountGroups.mockImplementation(() => new Promise(() => undefined));
    void queryClient.invalidateQueries({ queryKey: ['account-groups'] });
    await waitFor(() => expect(api.listAccountGroups).toHaveBeenCalledTimes(2));
    expect(screen.getByText(group.label)).toBeTruthy();
    expect(screen.queryByTestId('account-groups-skeleton')).toBeNull();
  });

  it('covers the empty state and creates a bounded group', async () => {
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    api.createAccountGroup.mockImplementation(({ body }) => success({ ...group, ...body }, 201));
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Aucun groupe' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Créer le premier groupe' }));
    expect(screen.getByRole('dialog', { name: 'Nouveau groupe' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Épargne' } });
    expect(screen.queryByLabelText(/Ordre/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(api.createAccountGroup).toHaveBeenCalledOnce());
    expect(api.createAccountGroup.mock.calls[0]?.[0].body).toMatchObject({
      label: 'Épargne',
      colorMode: 'AUTO',
      color: null,
      parentId: null,
    });
    expect(api.createAccountGroup.mock.calls[0]?.[0].body).not.toHaveProperty('sortOrder');
    expect(await screen.findByText('Le groupe a été enregistré.')).toBeTruthy();
  });

  it('retains a custom color after failure and resets to the stable default', async () => {
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [group], page: 1, pageSize: 50, total: 1 }),
    );
    api.updateAccountGroup.mockImplementation(() => problem(422, '/problems/validation'));
    renderPage();
    await loadedGroupsTree();
    openGroupActions('Épargne');
    fireEvent.click(screen.getByRole('button', { name: 'Modifier le groupe Épargne' }));
    const color = screen.getByLabelText('Couleur du groupe');
    fireEvent.change(color, { target: { value: '#ffffff' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.updateAccountGroup).toHaveBeenCalledOnce());
    expect(api.updateAccountGroup.mock.calls[0]?.[0].body).toMatchObject({
      colorMode: 'CUSTOM',
      color: '#FFFFFF',
    });
    expect((color as HTMLInputElement).value.toUpperCase()).toBe('#FFFFFF');
    await screen.findByRole('alert');
    fireEvent.click(screen.getByRole('button', { name: 'Rétablir la couleur par défaut' }));
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.updateAccountGroup).toHaveBeenCalledTimes(2));
    expect(api.updateAccountGroup.mock.calls[1]?.[0].body).toMatchObject({
      colorMode: 'AUTO',
      color: null,
    });
  });

  it('associates server validation with the color field and preserves its value', async () => {
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [group], page: 1, pageSize: 50, total: 1 }),
    );
    api.updateAccountGroup.mockResolvedValue({
      error: { type: '/problems/validation', errors: [{ field: 'color', code: 'invalid_color' }] },
      response: new Response('{}', { status: 422 }),
    });
    renderPage();
    await loadedGroupsTree();
    openGroupActions('Épargne');
    fireEvent.click(screen.getByRole('button', { name: 'Modifier le groupe Épargne' }));
    fireEvent.change(screen.getByLabelText('Couleur du groupe'), { target: { value: '#000000' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(screen.getByLabelText('Couleur du groupe').getAttribute('aria-invalid')).toBe('true'),
    );
    expect((screen.getByLabelText('Couleur du groupe') as HTMLInputElement).value).toBe('#000000');
    expect(screen.getByLabelText('Couleur du groupe').getAttribute('aria-describedby')).toBe(
      'account-group-color-error',
    );
  });

  it('keeps archived groups read-only and never renders crafted markup', async () => {
    const crafted = {
      ...group,
      label: '<img src=x onerror=alert(1)>',
      archivedAt: '2026-09-01T12:00:00+00:00',
    };
    api.listAccountGroups.mockImplementation(({ query }) =>
      success({
        items: query.includeArchived ? [crafted] : [],
        page: 1,
        pageSize: 50,
        total: query.includeArchived ? 1 : 0,
      }),
    );
    renderPage();

    await screen.findByRole('heading', { name: 'Aucun groupe' });
    fireEvent.click(screen.getByRole('button', { name: 'Afficher les groupes archivés' }));

    expect(await screen.findByText('<img src=x onerror=alert(1)>')).toBeTruthy();
    expect(document.querySelector('img')).toBeNull();
    expect(screen.getByText('Archivé')).toBeTruthy();
    openGroupActions('<img src=x onerror=alert(1)>');
    expect(screen.getByRole('button', { name: /Modifier/ }).hasAttribute('disabled')).toBe(true);
  });

  it('separates a taken label from a stale version', async () => {
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [group], page: 1, pageSize: 50, total: 1 }),
    );
    api.updateAccountGroup.mockImplementation(() =>
      problem(409, '/problems/account-group-label-taken'),
    );
    renderPage();

    await screen.findByRole('button', { name: 'Actions du groupe Épargne' });
    openGroupActions('Épargne');
    fireEvent.click(await screen.findByRole('button', { name: 'Modifier le groupe Épargne' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Enregistrer' }));
    expect(
      await screen.findByText(
        'Un groupe actif porte déjà ce libellé au même niveau. Choisissez-en un autre.',
      ),
    ).toBeTruthy();

    cleanup();
    vi.clearAllMocks();
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [group], page: 1, pageSize: 50, total: 1 }),
    );
    api.updateAccountGroup.mockImplementation(() => problem(409, '/problems/stale-version'));
    renderPage();

    await screen.findByRole('button', { name: 'Actions du groupe Épargne' });
    openGroupActions('Épargne');
    fireEvent.click(await screen.findByRole('button', { name: 'Modifier le groupe Épargne' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Enregistrer' }));
    expect(
      await screen.findByText(
        'Ce groupe a été modifié ailleurs entre-temps. La liste vient d’être rechargée : rouvrez le groupe puis réappliquez votre modification.',
      ),
    ).toBeTruthy();
  });

  it('shows an explicit unauthorized state', async () => {
    api.listAccountGroups.mockResolvedValue({
      error: { status: 401 },
      response: new Response('{}', { status: 401 }),
    });
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Session expirée' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Réessayer' })).toBeNull();
  });

  it('keeps one page skeleton while the groups are initially pending', async () => {
    api.listAccountGroups.mockImplementation(() => new Promise(() => undefined));
    renderPage();

    expect(await screen.findByTestId('account-groups-skeleton')).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(screen.queryByRole('button', { name: 'Treemap' })).toBeNull();
  });

  it('manages groups only: no account, net worth, total, share, column or pagination', async () => {
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [group], page: 1, pageSize: 50, total: 1 }),
    );
    renderPage();

    const tree = await loadedGroupsTree();
    expect(within(tree).getByText(group.label)).toBeTruthy();
    expect(screen.queryByRole('table')).toBeNull();
    expect(screen.queryByRole('columnheader')).toBeNull();
    expect(screen.queryByRole('navigation', { name: 'Pagination des groupes' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Treemap' })).toBeNull();
    expect(api.listAccounts).not.toHaveBeenCalled();
    expect(api.readNetWorth).not.toHaveBeenCalled();
  });

  it('loads every page of groups into one tree', async () => {
    api.listAccountGroups.mockImplementation(({ query }) =>
      success({
        items: [query.page === 1 ? group : { ...group, id: 'g2', label: 'Courant' }],
        page: query.page,
        pageSize: 1,
        total: 2,
      }),
    );
    renderPage();

    const tree = await loadedGroupsTree();
    await waitFor(() => expect(within(tree).getByText('Courant')).toBeTruthy());
    expect(within(tree).getByText('Épargne')).toBeTruthy();
  });

  it('offers a sub-group the shade and parent modes and drops them when it becomes a root', async () => {
    const child = {
      ...group,
      id: 'child',
      label: 'Livrets',
      parentId: group.id,
      parentLabel: 'Épargne',
      parentResolvedColor: '#486DF0',
      resolvedColor: '#7B96F4',
      defaultColor: '#7B96F4',
      depth: 2,
    };
    api.listAccountGroups.mockImplementation(({ query }) =>
      success({
        items: query.parentEligible ? [group] : [group, child],
        page: 1,
        pageSize: 50,
        total: query.parentEligible ? 1 : 2,
      }),
    );
    api.updateAccountGroup.mockImplementation(({ body }) => success({ ...child, ...body }));
    renderPage();

    await loadedGroupsTree();
    fireEvent.click(await screen.findByRole('button', { name: /sous-groupes de « Épargne »/ }));
    openGroupActions('Livrets');
    fireEvent.click(screen.getByRole('button', { name: 'Modifier le groupe Livrets' }));
    expect(screen.getByText('Nuance du groupe parent')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Utiliser la couleur du groupe parent' }));

    await waitFor(() =>
      expect((screen.getByLabelText('Groupe parent') as HTMLSelectElement).disabled).toBe(false),
    );
    fireEvent.change(screen.getByLabelText('Groupe parent'), { target: { value: '' } });
    expect(
      screen.queryByRole('button', { name: 'Utiliser la couleur du groupe parent' }),
    ).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(api.updateAccountGroup).toHaveBeenCalledOnce());
    expect(api.updateAccountGroup.mock.calls[0]?.[0].body).toMatchObject({
      colorMode: 'AUTO',
      color: null,
      parentId: null,
    });
  });
  describe('reordering', () => {
    const ids = ['a', 'b', 'c'].map(
      (key) => `00000000-0000-7000-8000-00000000000${key === 'a' ? 1 : key === 'b' ? 2 : 3}`,
    );
    const [idA, idB, idC] = ids as [string, string, string];
    const sibling = (id: string, label: string, extra: Partial<AccountGroup> = {}) => ({
      ...group,
      id,
      label,
      ...extra,
    });
    const initial = [sibling(idA, 'Alpha'), sibling(idB, 'Bravo'), sibling(idC, 'Charlie')];

    function serve(items: AccountGroup[]) {
      api.listAccountGroups.mockImplementation(() =>
        success({ items, page: 1, pageSize: 100, total: items.length }),
      );
    }

    async function startReordering() {
      await loadedGroupsTree();
      fireEvent.click(screen.getByRole('button', { name: 'Réorganiser les groupes' }));
    }

    it('shows the move controls only while reordering and disables the ends', async () => {
      serve(initial);
      renderPage();
      await loadedGroupsTree();
      expect(screen.queryByRole('button', { name: 'Monter Alpha' })).toBeNull();

      const toggle = screen.getByRole('button', { name: 'Réorganiser les groupes' });
      expect(toggle.getAttribute('aria-pressed')).toBe('false');
      fireEvent.click(toggle);

      expect(toggle.getAttribute('aria-pressed')).toBe('true');
      expect(toggle.getAttribute('aria-label')).toBe('Terminer la réorganisation');
      expect(screen.getByRole('button', { name: 'Monter Alpha' }).hasAttribute('disabled')).toBe(
        true,
      );
      expect(screen.getByRole('button', { name: 'Descendre Alpha' }).hasAttribute('disabled')).toBe(
        false,
      );
      expect(
        screen.getByRole('button', { name: 'Descendre Charlie' }).hasAttribute('disabled'),
      ).toBe(true);

      fireEvent.click(toggle);
      expect(screen.queryByRole('button', { name: 'Monter Alpha' })).toBeNull();
    });

    it('saves a move with the displayed sibling order, keeps the focus and announces the rank', async () => {
      serve(initial);
      api.moveAccountGroup.mockImplementation(() => {
        serve([initial[1]!, initial[0]!, initial[2]!]);
        return success({ parentId: null, siblingIds: [idB, idA, idC], rank: 2 });
      });
      renderPage();
      await startReordering();

      fireEvent.click(screen.getByRole('button', { name: 'Descendre Alpha' }));

      await waitFor(() => expect(api.moveAccountGroup).toHaveBeenCalledOnce());
      expect(api.moveAccountGroup.mock.calls[0]?.[0]).toMatchObject({
        path: { id: idA },
        body: { beforeGroupId: idC, expectedSiblingIds: [idA, idB, idC] },
      });
      expect(await screen.findByText('Alpha déplacé en position 2 sur 3.')).toBeTruthy();
      await waitFor(() =>
        expect(document.activeElement).toBe(
          screen.getByRole('button', { name: 'Descendre Alpha' }),
        ),
      );
      const labels = within(screen.getByRole('list', { name: 'Groupes de comptes' }))
        .getAllByText(/^(Alpha|Bravo|Charlie)$/)
        .map((node) => node.textContent);
      expect(labels).toEqual(['Bravo', 'Alpha', 'Charlie']);
    });

    it('moves a group last with a null anchor and never offers archived rows', async () => {
      serve([
        ...initial,
        sibling('00000000-0000-7000-8000-000000000004', 'Zeta', {
          archivedAt: '2026-09-01T12:00:00+00:00',
        }),
      ]);
      api.moveAccountGroup.mockImplementation(() =>
        success({ parentId: null, siblingIds: [idA, idC, idB], rank: 3 }),
      );
      renderPage();
      await loadedGroupsTree();
      fireEvent.click(screen.getByRole('button', { name: 'Afficher les groupes archivés' }));
      await screen.findByText('Zeta');
      fireEvent.click(screen.getByRole('button', { name: 'Réorganiser les groupes' }));

      expect(screen.queryByRole('button', { name: 'Monter Zeta' })).toBeNull();
      fireEvent.click(screen.getByRole('button', { name: 'Descendre Bravo' }));

      await waitFor(() => expect(api.moveAccountGroup).toHaveBeenCalledOnce());
      expect(api.moveAccountGroup.mock.calls[0]?.[0].body).toEqual({
        beforeGroupId: null,
        expectedSiblingIds: [idA, idB, idC],
      });
    });

    it('explains a stale order and reloads the groups on demand', async () => {
      serve(initial);
      api.moveAccountGroup.mockImplementation(() =>
        problem(409, '/problems/stale-account-group-order'),
      );
      renderPage();
      await startReordering();

      fireEvent.click(screen.getByRole('button', { name: 'Descendre Alpha' }));

      const alert = await screen.findByRole('alert');
      expect(alert.textContent).toContain(
        'L’ordre des groupes a changé. Rechargez pour réessayer.',
      );
      expect(within(alert).queryByRole('button', { name: 'Réessayer le déplacement' })).toBeNull();
      const before = api.listAccountGroups.mock.calls.length;
      fireEvent.click(within(alert).getByRole('button', { name: 'Recharger l’ordre' }));
      await waitFor(() => expect(api.listAccountGroups.mock.calls.length).toBeGreaterThan(before));
      await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
    });

    it('offers a retry after a failed request', async () => {
      serve(initial);
      api.moveAccountGroup
        .mockImplementationOnce(() => problem(500, '/problems/internal'))
        .mockImplementationOnce(() =>
          success({ parentId: null, siblingIds: [idB, idA, idC], rank: 2 }),
        );
      renderPage();
      await startReordering();

      fireEvent.click(screen.getByRole('button', { name: 'Descendre Alpha' }));
      const alert = await screen.findByRole('alert');
      expect(alert.textContent).toContain('Le déplacement n’a pas été enregistré.');
      fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer le déplacement' }));

      await waitFor(() => expect(api.moveAccountGroup).toHaveBeenCalledTimes(2));
      expect(api.moveAccountGroup.mock.calls[1]?.[0].body).toEqual(
        api.moveAccountGroup.mock.calls[0]?.[0].body,
      );
      await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
    });
  });
});
