import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountOverview } from './AccountOverview';
import {
  buildAccount,
  buildHeading,
  buildOverview,
  GROUP_G,
  GROUP_P,
  share,
} from './overviewFixtures';

const api = vi.hoisted(() => ({
  moveAccount: vi.fn(),
  readAccountOverview: vi.fn(),
  readAccountOverviewRows: vi.fn(),
  listProducts: vi.fn(),
  listProductModels: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const A = '00000000-0000-7000-8000-0000000000d1';
const B = '00000000-0000-7000-8000-0000000000d2';
const C = '00000000-0000-7000-8000-0000000000d3';

function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function problem(status: number, type: string) {
  const body = { type, title: 'Conflit', status, detail: 'Conflit' };
  return Promise.resolve({
    data: undefined,
    error: body,
    response: new Response(JSON.stringify(body), { status }),
  });
}

function rows(items: Account[], nextCursor: string | null = null) {
  return success({ items, nextCursor, orderVersion: 3 });
}

const handlers = {
  onArchive: vi.fn(),
  onCreate: vi.fn(),
  onEdit: vi.fn(),
  onReconcile: vi.fn(),
  onRecordBalance: vi.fn(),
  onResetFilters: vi.fn(),
  onRules: vi.fn(),
};

function renderOverview(props: { includeClosed?: boolean; includeArchived?: boolean } = {}) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  api.listProducts.mockImplementation(() =>
    success({ items: [], page: 1, pageSize: 100, total: 0 }),
  );
  api.listProductModels.mockImplementation(() =>
    success({ items: [], page: 1, pageSize: 100, total: 0 }),
  );

  return render(
    <QueryClientProvider client={queryClient}>
      <AccountOverview
        includeArchived={props.includeArchived ?? false}
        includeClosed={props.includeClosed ?? false}
        {...handlers}
      />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('AccountOverview', () => {
  it('shows one table per group with its total, share and rollup marker, and Sans groupe last', async () => {
    api.readAccountOverview.mockImplementation(() =>
      success(
        buildOverview([
          buildHeading({
            groupId: GROUP_P,
            label: 'Patrimoine',
            isRollup: true,
            memberCount: 1,
            share: share('90.00'),
          }),
          buildHeading({ groupId: GROUP_G, label: 'Épargne', depth: 2, parentId: GROUP_P }),
          buildHeading({ groupId: null, label: null, memberCount: 1, share: share('10.00') }),
        ]),
      ),
    );
    api.readAccountOverviewRows.mockImplementation(({ path }) =>
      path.groupKey === GROUP_P
        ? rows([buildAccount(B, 'Compte B', { share: share('30.00') })])
        : path.groupKey === GROUP_G
          ? rows([buildAccount(A, 'Compte A')])
          : rows([buildAccount(C, 'Compte C')]),
    );
    renderOverview();

    expect(await screen.findByRole('heading', { name: /Patrimoine/ })).toBeTruthy();
    expect(screen.getByRole('heading', { name: /Épargne/ })).toBeTruthy();
    expect(screen.getByRole('heading', { name: /Sans groupe/ })).toBeTruthy();
    expect(screen.getByText('Cumul des sous-groupes')).toBeTruthy();
    expect(await screen.findByText('Compte A')).toBeTruthy();
    expect(screen.getByText('Compte B')).toBeTruthy();
    expect(screen.getByText('Compte C')).toBeTruthy();
    expect(screen.getByText('30,00 %')).toBeTruthy();
    expect(screen.getByText('90,00 %')).toBeTruthy();
    expect(screen.queryByRole('columnheader', { name: 'Mode de valorisation' })).toBeNull();
    expect(screen.queryByRole('columnheader', { name: 'Contribution' })).toBeNull();
    expect(api.readAccountOverviewRows).toHaveBeenCalledWith(
      expect.objectContaining({ path: { groupKey: 'ungrouped' } }),
    );
  });

  it('badges closed and archived accounts in words next to the name', async () => {
    api.readAccountOverview.mockImplementation(() =>
      success(buildOverview([buildHeading({ memberCount: 2 })])),
    );
    api.readAccountOverviewRows.mockImplementation(() =>
      rows([
        buildAccount(A, 'Compte clos', { status: 'CLOSED', closedOn: '2026-08-01' }),
        buildAccount(B, 'Compte rangé', { status: 'ARCHIVED' }),
      ]),
    );
    renderOverview({ includeArchived: true, includeClosed: true });

    const closed = (await screen.findByText('Compte clos')).closest('th')!;
    expect(within(closed).getByText('Clôturé')).toBeTruthy();
    const archived = screen.getByText('Compte rangé').closest('th')!;
    expect(within(archived).getByText('Archivé')).toBeTruthy();
    expect(api.readAccountOverview).toHaveBeenCalledWith(
      expect.objectContaining({
        query: expect.objectContaining({ includeArchived: true, includeClosed: true }),
      }),
    );
  });

  it('reads the next page of one group with its cursor', async () => {
    api.readAccountOverview.mockImplementation(() =>
      success(buildOverview([buildHeading({ memberCount: 2 })])),
    );
    api.readAccountOverviewRows.mockImplementation(({ query }) =>
      query?.cursor === 'next-1'
        ? rows([buildAccount(B, 'Compte B')])
        : rows([buildAccount(A, 'Compte A')], 'next-1'),
    );
    renderOverview();

    fireEvent.click(await screen.findByRole('button', { name: 'Afficher plus de comptes' }));

    expect(await screen.findByText('Compte B')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Afficher plus de comptes' })).toBeNull();
    expect(api.readAccountOverviewRows).toHaveBeenLastCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ cursor: 'next-1' }) }),
    );
  });

  it('moves an account down before the row after the next one and announces its visible position', async () => {
    let order = [
      buildAccount(A, 'Compte A'),
      buildAccount(B, 'Compte B'),
      buildAccount(C, 'Compte C'),
    ];
    api.readAccountOverview.mockImplementation(() =>
      success(buildOverview([buildHeading({ memberCount: 3 })])),
    );
    api.readAccountOverviewRows.mockImplementation(() => rows(order));
    // The API rank counts hidden members: the announcement must not repeat it.
    api.moveAccount.mockImplementation(() => {
      order = [order[1]!, order[2]!, order[0]!];
      return success({ groupId: GROUP_G, orderVersion: 4, rank: 7 });
    });
    renderOverview();

    fireEvent.click(await screen.findByRole('button', { name: 'Descendre le compte Compte A' }));

    await waitFor(() =>
      expect(api.moveAccount).toHaveBeenCalledWith(
        expect.objectContaining({
          path: { id: A },
          body: { groupId: GROUP_G, beforeAccountId: C, orderVersion: 3 },
        }),
      ),
    );
    expect(await screen.findByText('Compte A déplacé en position 3 sur 3.')).toBeTruthy();
  });

  it('moves the last row of a group to the end and the first one up before its predecessor', async () => {
    api.readAccountOverview.mockImplementation(() =>
      success(buildOverview([buildHeading({ memberCount: 2 })])),
    );
    api.readAccountOverviewRows.mockImplementation(() =>
      rows([buildAccount(A, 'Compte A'), buildAccount(B, 'Compte B')]),
    );
    api.moveAccount.mockImplementation(() =>
      success({ groupId: GROUP_G, orderVersion: 4, rank: 1 }),
    );
    renderOverview();

    expect(
      (await screen.findByRole('button', { name: 'Monter le compte Compte A' })).hasAttribute(
        'disabled',
      ),
    ).toBe(true);
    expect(
      screen.getByRole('button', { name: 'Descendre le compte Compte B' }).hasAttribute('disabled'),
    ).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: 'Monter le compte Compte B' }));
    await waitFor(() =>
      expect(api.moveAccount).toHaveBeenCalledWith(
        expect.objectContaining({
          body: { groupId: GROUP_G, beforeAccountId: A, orderVersion: 3 },
        }),
      ),
    );
  });

  it('keeps the saved order and offers a reload when the order version is stale', async () => {
    api.readAccountOverview.mockImplementation(() =>
      success(buildOverview([buildHeading({ memberCount: 2 })])),
    );
    api.readAccountOverviewRows.mockImplementation(() =>
      rows([buildAccount(A, 'Compte A'), buildAccount(B, 'Compte B')]),
    );
    api.moveAccount.mockImplementation(() => problem(409, '/problems/stale-account-order'));
    renderOverview();

    fireEvent.click(await screen.findByRole('button', { name: 'Monter le compte Compte B' }));

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('L’ordre a changé');
    const names = screen.getAllByRole('link').map((link) => link.textContent);
    expect(names).toEqual(['Compte A', 'Compte B']);
    const before = api.readAccountOverview.mock.calls.length;
    fireEvent.click(within(alert).getByRole('button', { name: 'Recharger l’ordre' }));
    await waitFor(() => expect(api.readAccountOverview.mock.calls.length).toBeGreaterThan(before));
  });

  it('gives the focus back to the control used once a move is refused', async () => {
    api.readAccountOverview.mockImplementation(() =>
      success(buildOverview([buildHeading({ memberCount: 2 })])),
    );
    api.readAccountOverviewRows.mockImplementation(() =>
      rows([buildAccount(A, 'Compte A'), buildAccount(B, 'Compte B')]),
    );
    api.moveAccount.mockImplementation(() =>
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
    );
    renderOverview();

    const up = await screen.findByRole('button', { name: 'Monter le compte Compte B' });
    up.focus();
    fireEvent.click(up);

    await screen.findByRole('alert');
    await waitFor(() => expect(document.activeElement).toBe(up));
  });

  it('offers a retry of the same move when the write fails', async () => {
    api.readAccountOverview.mockImplementation(() =>
      success(buildOverview([buildHeading({ memberCount: 2 })])),
    );
    api.readAccountOverviewRows.mockImplementation(() =>
      rows([buildAccount(A, 'Compte A'), buildAccount(B, 'Compte B')]),
    );
    api.moveAccount.mockImplementationOnce(() =>
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
    );
    api.moveAccount.mockImplementation(() =>
      success({ groupId: GROUP_G, orderVersion: 4, rank: 1 }),
    );
    renderOverview();

    fireEvent.click(await screen.findByRole('button', { name: 'Monter le compte Compte B' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Réessayer le déplacement' }));

    await waitFor(() => expect(api.moveAccount).toHaveBeenCalledTimes(2));
  });

  it('offers to create the first account when the workspace has none', async () => {
    api.readAccountOverview.mockImplementation(() => success(buildOverview([], 0)));
    renderOverview();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier compte' }));
    expect(handlers.onCreate).toHaveBeenCalled();
  });

  it('offers to reset the filters when they hide every account', async () => {
    api.readAccountOverview.mockImplementation(() =>
      success(buildOverview([buildHeading({ memberCount: 0 })], 2)),
    );
    renderOverview();

    fireEvent.click(await screen.findByRole('button', { name: 'Réinitialiser les filtres' }));
    expect(handlers.onResetFilters).toHaveBeenCalled();
  });

  it('names an unreadable overview and retries it', async () => {
    api.readAccountOverview.mockImplementationOnce(() =>
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
    );
    api.readAccountOverview.mockImplementation(() => success(buildOverview([], 0)));
    renderOverview();

    const alert = await screen.findByRole('alert');
    fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByRole('button', { name: 'Créer le premier compte' })).toBeTruthy();
  });

  it('shows a null group total as not calculable instead of zero', async () => {
    api.readAccountOverview.mockImplementation(() =>
      success(
        buildOverview([
          buildHeading({
            total: null,
            totalReason: 'MISSING_VALUATION',
            share: {
              ratio: null,
              percent: null,
              percentDisplay: null,
              reason: 'MISSING_VALUATION',
            },
          }),
        ]),
      ),
    );
    api.readAccountOverviewRows.mockImplementation(() => rows([buildAccount(A, 'Compte A')]));
    renderOverview();

    await screen.findByText('Compte A');
    expect(screen.getAllByText('Non calculable').length).toBeGreaterThan(0);
  });

  it('shows the page skeleton while the overview loads, with real column headers', () => {
    api.readAccountOverview.mockImplementation(() => new Promise(() => undefined));
    renderOverview();

    expect(screen.getByTestId('accounts-page-skeleton')).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(screen.getByRole('columnheader', { name: 'Libellé' })).toBeTruthy();
    const section = screen.getByTestId('accounts-page-skeleton').querySelector('section')!;
    expect(document.getElementById(section.getAttribute('aria-labelledby')!)?.tagName).toBe('H2');
  });

  it('marks each group heading with the identification color of the group', async () => {
    api.readAccountOverview.mockImplementation(() =>
      success(buildOverview([buildHeading({ resolvedColor: '#AABBCC' })])),
    );
    api.readAccountOverviewRows.mockImplementation(() => rows([buildAccount(A, 'Compte A')]));
    renderOverview();

    const heading = await screen.findByRole('heading', { name: /Épargne/ });
    await waitFor(() =>
      expect(heading.querySelector('rect')?.getAttribute('fill')).toBe('#AABBCC'),
    );
  });
});
