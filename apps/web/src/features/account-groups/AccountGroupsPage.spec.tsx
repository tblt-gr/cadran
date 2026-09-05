import type { Account, AccountGroup, NetWorth } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountGroupsPage } from './AccountGroupsPage';

const api = vi.hoisted(() => ({
  archiveAccountGroup: vi.fn(),
  createAccountGroup: vi.fn(),
  listAccountGroups: vi.fn(),
  listAccounts: vi.fn(),
  readNetWorth: vi.fn(),
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

const LIVRETS_ID = '00000000-0000-7000-8000-0000000000b2';
const INVEST_ID = '00000000-0000-7000-8000-0000000000b3';

/** The narrow no-break space Intl inserts between groups of digits. */
const NARROW = '\u202f';
/** The no-break space Intl inserts before the currency sign. */
const NBSP = '\u00a0';

function amount(value: string) {
  return {
    value,
    assetCode: 'EUR',
    display: { value, assetCode: 'EUR' },
    belowDisplayStep: false,
  };
}

function account(overrides: Partial<Account> & Pick<Account, 'id' | 'label'>): Account {
  return {
    assetCode: 'EUR',
    kind: 'SAVINGS',
    productCode: null,
    productModelId: null,
    institution: null,
    maskedIdentifier: null,
    valuationMode: 'SNAPSHOTS',
    liquidityLevel: 'IMMEDIATE',
    includeInNetWorth: true,
    includeInEmergencyFund: false,
    openedOn: '2026-01-10',
    closedOn: null,
    status: 'ACTIVE',
    netWorthSign: 1,
    used: false,
    editable: true,
    kindEditable: true,
    kindEditReason: null,
    version: 1,
    archivedAt: null,
    primaryGroupId: null,
    tagGroupIds: [],
    share: { ratio: null, percent: null, percentDisplay: null, reason: 'MISSING_VALUATION' },
    valuation: {
      accountId: overrides.id,
      requestedOn: '2026-09-05',
      asOf: '2026-09-05',
      amount: { value: '1000.00', assetCode: 'EUR' },
      display: { value: '1000.00', assetCode: 'EUR' },
      belowDisplayStep: false,
      source: 'MANUAL',
      ageDays: 0,
      quality: 'CURRENT',
      reconciliationStatus: 'UNRECONCILED',
      snapshotId: '00000000-0000-7000-8000-0000000000c1',
      version: 1,
    },
    ...overrides,
  };
}

function netWorth(overrides: Partial<NetWorth> = {}): NetWorth {
  return {
    asOf: '2026-09-05',
    total: amount('84100.00'),
    reason: null,
    quality: 'CURRENT',
    stalestAgeDays: 0,
    eligibleAccountCount: 2,
    valuedAccountCount: 2,
    missingValuationCount: 0,
    staleValuationCount: 0,
    delta: {
      comparedOn: '2026-08-05',
      previousTotal: amount('80000.00'),
      amount: amount('4100.00'),
      amountReason: null,
      rate: '0.051250000000000000000000',
      ratePercent: '5.125000000000000000000000',
      ratePercentDisplay: '5.13',
      rateReason: null,
    },
    contributions: [],
    allocation: [],
    ...overrides,
  };
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
    api.readNetWorth.mockImplementation(() => success(netWorth({ allocation: [] })));
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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

  it('lists the exclusive total and primary accounts of each group', async () => {
    const livrets: AccountGroup = { ...group, id: LIVRETS_ID, label: 'Livrets' };
    const investissements: AccountGroup = { ...group, id: INVEST_ID, label: 'Investissements' };
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [livrets, investissements], page: 1, perPage: 50, total: 2 }),
    );
    api.readNetWorth.mockImplementation(() =>
      success(
        netWorth({
          allocation: [
            {
              groupId: LIVRETS_ID,
              label: 'Livrets',
              parentId: null,
              depth: 1,
              value: amount('42100.00'),
              share: {
                ratio: null,
                percent: '24.12',
                percentDisplay: '24.12',
                reason: null,
              },
            },
            {
              groupId: INVEST_ID,
              label: 'Investissements',
              parentId: null,
              depth: 1,
              value: amount('42000.00'),
              share: {
                ratio: null,
                percent: '24.06',
                percentDisplay: '24.06',
                reason: null,
              },
            },
          ],
        }),
      ),
    );
    api.listAccounts.mockImplementation(() =>
      success({
        items: [
          account({
            id: '00000000-0000-7000-8000-0000000000d1',
            label: 'Livret A',
            primaryGroupId: LIVRETS_ID,
            valuation: {
              accountId: '00000000-0000-7000-8000-0000000000d1',
              requestedOn: '2026-09-05',
              asOf: '2026-09-05',
              amount: { value: '20100.00', assetCode: 'EUR' },
              display: { value: '20100.00', assetCode: 'EUR' },
              belowDisplayStep: false,
              source: 'MANUAL',
              ageDays: 0,
              quality: 'CURRENT',
              reconciliationStatus: 'UNRECONCILED',
              snapshotId: '00000000-0000-7000-8000-0000000000c1',
              version: 1,
            },
          }),
          account({
            id: '00000000-0000-7000-8000-0000000000d2',
            label: 'LDDS',
            primaryGroupId: LIVRETS_ID,
          }),
          account({
            id: '00000000-0000-7000-8000-0000000000d3',
            label: 'PEA',
            kind: 'PORTFOLIO',
            primaryGroupId: INVEST_ID,
          }),
        ],
        page: 1,
        perPage: 50,
        total: 3,
      }),
    );
    renderPage();

    const livretsRow = await screen.findByRole('row', { name: /Livrets/ });
    await waitFor(() => {
      expect(livretsRow.textContent).toContain(`42${NARROW}100,00${NBSP}€`);
    });
    expect(within(livretsRow).getByText('Livret A')).toBeTruthy();
    expect(within(livretsRow).getByText('LDDS')).toBeTruthy();
    expect(within(livretsRow).queryByText('PEA')).toBeNull();
    expect(livretsRow.textContent).toContain(`20${NARROW}100,00${NBSP}€`);

    const investRow = screen.getByRole('row', { name: /Investissements/ });
    expect(investRow.textContent).toContain(`42${NARROW}000,00${NBSP}€`);
    expect(within(investRow).getByText('PEA')).toBeTruthy();
    expect(within(investRow).queryByText('Livret A')).toBeNull();
  });

  it('does not treat pending members or totals as an empty group', async () => {
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [group], page: 1, perPage: 50, total: 1 }),
    );
    api.listAccounts.mockImplementation(() => new Promise(() => undefined));
    api.readNetWorth.mockImplementation(() => new Promise(() => undefined));
    renderPage();

    expect(await screen.findByRole('row', { name: /Épargne/ })).toBeTruthy();
    expect(screen.queryByText('Aucun compte dans ce groupe')).toBeNull();
    expect(screen.queryByText('Non calculable')).toBeNull();
    expect(screen.getByText('Chargement des comptes…')).toBeTruthy();
    expect(screen.getByText('Chargement du total…')).toBeTruthy();
  });

  it('never prints 0 for a group whose exclusive total is absent', async () => {
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [group], page: 1, perPage: 50, total: 1 }),
    );
    api.readNetWorth.mockImplementation(() =>
      success(
        netWorth({
          total: null,
          reason: 'MISSING_VALUATION',
          allocation: [
            {
              groupId: group.id,
              label: group.label,
              parentId: null,
              depth: 1,
              value: null,
              share: {
                ratio: null,
                percent: null,
                percentDisplay: null,
                reason: 'MISSING_VALUATION',
              },
            },
          ],
        }),
      ),
    );
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    renderPage();

    expect(await screen.findByText('Non calculable')).toBeTruthy();
    expect(screen.queryByText(/^0/)).toBeNull();
    expect(screen.getByText('Aucun compte dans ce groupe')).toBeTruthy();
  });
});
