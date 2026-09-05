import type { AccountGroup } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountGroupsPage } from './AccountGroupsPage';

const api = vi.hoisted(() => ({
  archiveAccountGroup: vi.fn(),
  createAccountGroup: vi.fn(),
  listAccountGroups: vi.fn(),
  updateAccountGroup: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const group: AccountGroup = {
  id: '00000000-0000-7000-8000-0000000000b1',
  label: 'Épargne',
  parentId: null,
  parentLabel: null,
  sortOrder: 0,
  depth: 1,
  version: 1,
  hasChildren: false,
  canAcceptChildren: true,
  share: { ratio: null, percent: null, reason: 'MISSING_VALUATION' },
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

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <AccountGroupsPage />
    </QueryClientProvider>,
  );
}

describe('AccountGroupsPage', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('covers the empty state and creates a bounded group', async () => {
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.createAccountGroup.mockImplementation(({ body }) => success({ ...group, ...body }, 201));
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Aucun groupe' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Créer le premier groupe' }));
    expect(screen.getByRole('dialog', { name: 'Nouveau groupe' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Épargne' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(api.createAccountGroup).toHaveBeenCalledOnce());
    expect(api.createAccountGroup.mock.calls[0]?.[0].body).toMatchObject({
      label: 'Épargne',
      parentId: null,
      sortOrder: 0,
    });
    expect(await screen.findByText('Le groupe a été enregistré.')).toBeTruthy();
  });

  it('shows a share reason instead of 0%', async () => {
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [group], page: 1, perPage: 50, total: 1 }),
    );
    renderPage();

    expect(await screen.findByText('Valorisation manquante')).toBeTruthy();
    expect(screen.queryByText(/0\s*%/)).toBeNull();
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
        perPage: 50,
        total: query.includeArchived ? 1 : 0,
      }),
    );
    renderPage();

    await screen.findByRole('heading', { name: 'Aucun groupe' });
    fireEvent.click(screen.getByLabelText('Afficher les groupes archivés'));

    expect(await screen.findByText('<img src=x onerror=alert(1)>')).toBeTruthy();
    expect(document.querySelector('img')).toBeNull();
    expect(screen.getByText('Archivé')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Modifier/ }).hasAttribute('disabled')).toBe(true);
  });

  it('separates a taken label from a stale version', async () => {
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [group], page: 1, perPage: 50, total: 1 }),
    );
    api.updateAccountGroup.mockImplementation(() =>
      problem(409, '/problems/account-group-label-taken'),
    );
    renderPage();

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
      success({ items: [group], page: 1, perPage: 50, total: 1 }),
    );
    api.updateAccountGroup.mockImplementation(() => problem(409, '/problems/stale-version'));
    renderPage();

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
});
