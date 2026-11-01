import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MonthlyBudgetPage } from './MonthlyBudgetPage';

const api = vi.hoisted(() => ({
  createTransaction: vi.fn(),
  createTransfer: vi.fn(),
  recordPortfolioOperation: vi.fn(),
  getSession: vi.fn(),
  getTransaction: vi.fn(),
  listAccounts: vi.fn(),
  listCategories: vi.fn(),
  readMonthlyBounds: vi.fn(),
  readMonthlyLedger: vi.fn(),
  readMonthlyLedgerMovements: vi.fn(),
  readMonthlyProjection: vi.fn(),
  readMonthlyRecap: vi.fn(),
  readMonthlyRecapPreferences: vi.fn(),
  saveMonthlyRecapPreferences: vi.fn(),
  updateTransaction: vi.fn(),
}));

vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

const incomeId = '00000000-0000-7000-8000-000000000001';
const expenseId = '00000000-0000-7000-8000-000000000002';
const accountId = '00000000-0000-7000-8000-000000000003';
const emptyAccountId = '00000000-0000-7000-8000-000000000010';
const movementTransactionId = '00000000-0000-7000-8000-000000000005';
const projectionMetric = (value: string | null) => ({
  value,
  assetCode: value === null ? null : 'EUR',
  reason: null,
});
const projection = {
  month: '2026-03',
  periodStart: '2026-03-01',
  periodEnd: '2026-03-31',
  state: 'COMPLETE' as const,
  quality: 'CURRENT' as const,
  pendingCount: 0,
  cashIncome: projectionMetric('3000'),
  nonCashBenefits: projectionMetric('120.5'),
  benefitSpending: projectionMetric('80'),
  budgetExpenses: projectionMetric('-1000'),
  uncategorizedExpenses: projectionMetric('-25'),
  budgetSurplus: projectionMetric('2000'),
  savingsTransfers: projectionMetric('700'),
  cashSavingsRate: { value: '0.25', assetCode: null, reason: null },
  savingsInflows: projectionMetric('700'),
  savingsWithdrawals: projectionMetric('0'),
  netSavingsTransfers: projectionMetric('700'),
  netSavingsRate: { value: '0.25', assetCode: null, reason: null },
};
const ledger = {
  month: '2026-03',
  periodStart: '2026-03-01',
  periodEnd: '2026-03-31',
  axis: null,
  state: 'COMPLETE' as const,
  quality: 'CURRENT' as const,
  timezone: 'Europe/Paris',
  firstDataMonth: '2025-06',
  pendingCount: 2,
  closed: false,
  actionsAllowed: true,
  actionReason: null,
  incomeCategories: [
    {
      id: incomeId,
      label: 'Salaire',
      icon: 'briefcase',
      color: '#486DF0',
      budgetIncluded: true,
      archived: false,
      total: { value: '0', assetCode: 'EUR', reason: null },
      movementCount: 1,
      hasMovements: true,
    },
  ],
  expenseCategories: [
    {
      id: expenseId,
      label: 'Voyages',
      icon: 'plane',
      color: '#58B8C0',
      budgetIncluded: false,
      archived: false,
      total: { value: null, assetCode: null, reason: 'NO_MOVEMENTS' as const },
      movementCount: 0,
      hasMovements: false,
    },
  ],
  accounts: [
    {
      id: accountId,
      label: 'Compte courant',
      assetCode: 'EUR',
      kind: 'CURRENT' as const,
      total: { value: '-25.00', assetCode: 'EUR', reason: null },
      movementCount: 2,
      hasMovements: true,
    },
    {
      id: emptyAccountId,
      label: 'Livret vide',
      assetCode: 'EUR',
      kind: 'SAVINGS' as const,
      total: { value: null, assetCode: null, reason: 'NO_MOVEMENTS' as const },
      movementCount: 0,
      hasMovements: false,
    },
  ],
};

const account = {
  id: accountId,
  label: 'Compte courant',
  assetCode: 'EUR',
  status: 'ACTIVE',
  openedOn: '2020-01-01',
};

const recap = {
  month: '2026-03',
  metricPolicy: { version: 1, label: 'Définition de trésorerie' },
  previousAsOf: '2026-02-28',
  currentAsOf: '2026-03-15',
  provisional: true,
  state: 'PENDING' as const,
  quality: 'CURRENT' as const,
  portfolioCosts: null,
  accounts: [
    {
      accountId,
      label: 'Compte courant',
      institution: 'Banque Exemple',
      kind: 'CURRENT',
      netWorthSign: 1 as const,
      primaryGroupId: 'group-1',
      primaryGroupLabel: 'Liquidités',
      primaryGroupColor: '#FFFFFF',
      eligible: true,
      previousValue: {
        value: '100.00',
        assetCode: 'EUR',
        quality: 'CURRENT' as const,
        ageDays: 0,
        valuedOn: '2026-02-28',
      },
      currentValue: {
        value: null,
        assetCode: null,
        quality: 'MISSING' as const,
        ageDays: null,
        valuedOn: null,
      },
      share: {
        ratio: null,
        percent: null,
        percentDisplay: null,
        reason: 'MISSING_VALUATION' as const,
      },
    },
  ],
  groups: [
    {
      groupId: 'group-1',
      color: '#FFFFFF',
      label: 'Liquidités',
      parentId: null,
      depth: 1,
      value: null,
      previousValue: null,
      share: {
        ratio: null,
        percent: null,
        percentDisplay: null,
        reason: 'MISSING_VALUATION' as const,
      },
      directValue: null,
      directPreviousValue: null,
      directShare: {
        ratio: null,
        percent: null,
        percentDisplay: null,
        reason: 'MISSING_VALUATION' as const,
      },
      hasChildren: false,
    },
  ],
  netWorth: {
    previous: {
      value: '100.00',
      assetCode: 'EUR',
      display: { value: '100.00', assetCode: 'EUR' },
      belowDisplayStep: false,
    },
    previousReason: null,
    current: null,
    currentReason: 'MISSING_VALUATION' as const,
    difference: null,
    differenceReason: 'MISSING_VALUATION' as const,
    changeRatio: null,
    changePercent: null,
    changePercentDisplay: null,
    changeReason: 'MISSING_VALUATION' as const,
    previousQuality: 'CURRENT' as const,
    previousStalestAgeDays: 0,
    previousMissingValuationCount: 0,
    previousStaleValuationCount: 0,
    quality: 'MISSING' as const,
    stalestAgeDays: null,
    eligibleAccountCount: 1,
    missingValuationCount: 1,
    staleValuationCount: 0,
    previousSourceAccountIds: [accountId],
    currentSourceAccountIds: [],
    staleValuations: [],
    previousStaleValuations: [],
  },
  totals: {
    cashIncome: {
      kpi: 'cashIncome' as const,
      value: '1200.00',
      assetCode: 'EUR',
      reason: null,
      pendingCount: 0,
      sourceTransactionIds: [],
      sourceTransferIds: [],
    },
    budgetExpenses: {
      kpi: 'budgetExpenses' as const,
      value: '-400.00',
      assetCode: 'EUR',
      reason: null,
      pendingCount: 0,
      sourceTransactionIds: [],
      sourceTransferIds: [],
    },
    savingsInflows: {
      kpi: 'savingsInflows' as const,
      value: '50.00',
      assetCode: 'EUR',
      reason: null,
      pendingCount: 0,
      sourceTransactionIds: [],
      sourceTransferIds: [],
    },
    savingsWithdrawals: {
      kpi: 'savingsWithdrawals' as const,
      value: '0',
      assetCode: 'EUR',
      reason: null,
      pendingCount: 0,
      sourceTransactionIds: [],
      sourceTransferIds: [],
    },
    netSavingsTransfers: {
      kpi: 'netSavingsTransfers' as const,
      value: '-50.00',
      assetCode: 'EUR',
      reason: null,
      pendingCount: 0,
      sourceTransactionIds: [],
      sourceTransferIds: [],
    },
    netSavingsRate: {
      kpi: 'netSavingsRate' as const,
      value: '-0.041666',
      assetCode: null,
      reason: null,
      pendingCount: 0,
      sourceTransactionIds: [],
      sourceTransferIds: [],
    },
    expensesByAxis: [
      {
        axis: 'ESSENTIAL' as const,
        kpi: null,
        value: '-400.00',
        assetCode: 'EUR',
        reason: null,
        pendingCount: 0,
        sourceTransactionIds: [],
        sourceTransferIds: [],
      },
    ],
    categories: [
      {
        id: expenseId,
        label: 'Voyages',
        type: 'EXPENSE' as const,
        kpi: null,
        value: '400.00',
        assetCode: 'EUR',
        reason: null,
        pendingCount: 0,
        sourceTransactionIds: [],
        sourceTransferIds: [],
      },
    ],
  },
};

function ok<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function renderPage(periodKey = '2026-03') {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return {
    ...render(
      <QueryClientProvider client={client}>
        <MonthlyBudgetPage periodKey={periodKey} today="2026-03-15" />
      </QueryClientProvider>,
    ),
    queryClient: client,
  };
}

async function loaded() {
  await waitFor(() => expect(screen.queryByTestId('monthly-budget-skeleton')).toBeNull());
  await waitFor(() => expect(screen.queryByTestId('monthly-budget-skeleton')).toBeNull());
}

describe('MonthlyBudgetPage', () => {
  beforeEach(() => {
    window.localStorage.removeItem('cadran.budget.showEmptyTransferAccounts');
    window.history.replaceState({}, '', '/budget/2026/03');
    api.readMonthlyBounds.mockReturnValue(
      ok({ firstDataMonth: '2025-06', currentMonth: '2026-03' }),
    );
    api.readMonthlyLedger.mockReturnValue(ok(ledger));
    api.readMonthlyProjection.mockReturnValue(ok(projection));
    api.readMonthlyRecap.mockReturnValue(ok(recap));
    api.readMonthlyRecapPreferences.mockReturnValue(
      ok({ visibleCategoryIds: [expenseId], visibleAxes: ['ESSENTIAL'], version: 0 }),
    );
    api.listCategories.mockReturnValue(ok({ items: [], page: 1, pageSize: 100, total: 0 }));
    api.getSession.mockReturnValue(
      ok({
        authenticated: true,
        provisioned: true,
        user: { id: 'u1', email: 'owner@example.test', displayName: 'Owner' },
        workspace: { id: 'w1', role: 'OWNER', timeZone: 'Europe/Paris' },
      }),
    );
    api.listAccounts.mockReturnValue(ok({ items: [account], page: 1, pageSize: 100, total: 1 }));
    api.readMonthlyLedgerMovements.mockReturnValue(
      ok({
        items: [
          {
            id: '00000000-0000-7000-8000-000000000004',
            transactionId: '00000000-0000-7000-8000-000000000005',
            transferId: null,
            bookedOn: '2026-03-14',
            label: 'Paie mars',
            amount: { value: '2500.00', assetCode: 'EUR' },
            direction: null,
            counterpartAccountId: null,
            counterpartAccountLabel: null,
          },
        ],
        nextCursor: null,
        hasMore: false,
        pageSize: 50,
      }),
    );
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/');
  });

  it('closes the shared transfer modal once a transfer into a portfolio is saved, with no client-side deposit', async () => {
    const destination = {
      ...account,
      kind: 'PORTFOLIO' as const,
      valuationMode: 'PORTFOLIO' as const,
    };
    const source = { ...account, id: 'source', label: 'Banque' };
    api.listAccounts.mockReturnValue(
      ok({ items: [source, destination], page: 1, pageSize: 100, total: 2 }),
    );
    api.createTransfer.mockReturnValue(
      ok({
        target: {
          id: 'incoming',
          accountId: destination.id,
          amount: { value: '1500.00', assetCode: 'EUR' },
          bookedOn: '2026-03-10',
        },
      }),
    );
    renderPage();
    await loaded();
    fireEvent.click(
      screen.getByRole('button', { name: 'Ajouter un virement vers Compte courant' }),
    );
    const dialog = await screen.findByRole('dialog', { name: 'Nouveau virement' });
    await screen.findByLabelText('Compte source');
    fireEvent.change(within(dialog).getByLabelText('Compte source'), {
      target: { value: source.id },
    });
    fireEvent.change(within(dialog).getByLabelText('Montant (EUR)'), {
      target: { value: '1500.00' },
    });
    fireEvent.change(within(dialog).getByLabelText('Libellé'), {
      target: { value: 'Investissement mensuel' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer le virement' }));
    await waitFor(() =>
      expect(screen.queryByRole('dialog', { name: 'Nouveau virement' })).toBeNull(),
    );
    expect(api.createTransfer).toHaveBeenCalledOnce();
    expect(api.recordPortfolioOperation).not.toHaveBeenCalled();
  });

  it('replaces its one named cold-load skeleton and keeps the ledger during a refetch', async () => {
    let resolveLedger: ((value: unknown) => void) | undefined;
    api.readMonthlyLedger.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveLedger = resolve;
        }),
    );
    const { queryClient } = renderPage();

    expect(screen.getByTestId('monthly-budget-skeleton')).toBeTruthy();
    const coldPanel = screen.getByRole('region', { name: 'Revenus' });
    expect(within(coldPanel).queryByText(/2026/)).toBeNull();
    expect(within(coldPanel).queryByRole('button', { name: /Expliquer/ })).toBeNull();
    expect(
      within(coldPanel).getByRole('button', { name: 'Ajouter un revenu' }).hasAttribute('disabled'),
    ).toBe(true);
    const skeletonRow = coldPanel.querySelector('ul li')!;
    expect(skeletonRow.querySelectorAll('button')).toHaveLength(1);
    expect(skeletonRow.querySelector('[aria-expanded], [aria-controls]')).toBeNull();
    expect(
      screen
        .getByRole('button', { name: 'Afficher les comptes sans mouvement' })
        .hasAttribute('disabled'),
    ).toBe(true);
    const loadingSettings = screen.getByRole('button', { name: 'Configurer l’affichage' });
    expect(loadingSettings.textContent).toBe('');
    expect(loadingSettings.hasAttribute('disabled')).toBe(true);
    expect(screen.queryByRole('button', { name: 'Informations sur la synthèse' })).toBeNull();
    const loadingExplanation = screen.getByRole('button', {
      name: 'Expliquer : Revenus',
    });
    expect(loadingExplanation.textContent).toBe('Revenus');
    expect(loadingExplanation.hasAttribute('disabled')).toBe(true);
    expect(screen.getAllByRole('status')).toHaveLength(1);
    await waitFor(() => expect(resolveLedger).toBeDefined());
    resolveLedger?.({ data: ledger, response: new Response('{}', { status: 200 }) });
    expect(await screen.findByText('Salaire')).toBeTruthy();
    expect(screen.queryByTestId('monthly-budget-skeleton')).toBeNull();

    api.readMonthlyLedger.mockImplementation(() => new Promise(() => undefined));
    void queryClient.invalidateQueries({ queryKey: ['monthly-ledger'] });
    await waitFor(() => expect(api.readMonthlyLedger).toHaveBeenCalledTimes(2));
    expect(screen.getByRole('link', { name: 'Gérer les plans et objectifs' })).toBeTruthy();
    expect(screen.queryByTestId('monthly-budget-skeleton')).toBeNull();
  });

  it('replaces the recap skeleton and keeps the recap during a refetch', async () => {
    let resolveRecap: ((value: unknown) => void) | undefined;
    api.readMonthlyRecap.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveRecap = resolve;
        }),
    );
    const { queryClient } = renderPage();

    expect(await screen.findByTestId('monthly-recap-skeleton')).toBeTruthy();
    const incomePanel = screen.getByRole('region', { name: 'Revenus' });
    const pendingTotal = within(incomePanel).getByText('Total des revenus').parentElement!;
    expect(pendingTotal.querySelector('[data-visual-mask] > span > [data-skeleton]')).toBeTruthy();
    const recapSkeleton = screen.getByTestId('monthly-recap-skeleton');
    const totalsPanel = within(recapSkeleton)
      .getByRole('heading', { name: 'Synthèse des flux' })
      .closest('section')!;
    expect(totalsPanel.querySelectorAll('li > div > [data-visual-mask]')).toHaveLength(17);
    for (const amount of totalsPanel.querySelectorAll('li > div > [data-visual-mask]')) {
      expect(amount.querySelector('span > [aria-hidden="true"]')).toBeTruthy();
    }
    const balanceHeaders = within(recapSkeleton).getAllByRole('columnheader').slice(1, 3);
    expect(balanceHeaders.map((header) => header.textContent)).toEqual(['', '']);
    expect(
      balanceHeaders.every((header) => header.querySelector('[aria-hidden="true"]')),
    ).toBeTruthy();

    expect(
      screen.getByTestId('monthly-recap-skeleton').querySelectorAll(':scope > [role="status"]'),
    ).toHaveLength(1);
    resolveRecap?.({ data: recap, response: new Response('{}', { status: 200 }) });
    expect(await screen.findByText('Salaire')).toBeTruthy();
    expect(screen.queryByTestId('monthly-budget-skeleton')).toBeNull();

    api.readMonthlyRecap.mockImplementation(() => new Promise(() => undefined));
    void queryClient.invalidateQueries({ queryKey: ['monthly-recap'] });
    await waitFor(() => expect(api.readMonthlyRecap).toHaveBeenCalledTimes(2));
    expect(screen.getByText('Salaire')).toBeTruthy();
    expect(screen.queryByTestId('monthly-budget-skeleton')).toBeNull();
  });

  it('replaces the recap skeleton with its error and empty states', async () => {
    api.readMonthlyRecap.mockRejectedValue(new TypeError('offline'));
    const failed = renderPage();
    expect(
      await screen.findByRole('heading', { name: 'Synthèse mensuelle indisponible' }),
    ).toBeTruthy();
    expect(screen.queryByTestId('monthly-budget-skeleton')).toBeNull();
    const errorPanel = screen.getByRole('heading', {
      name: 'Synthèse mensuelle indisponible',
    }).parentElement!;
    expect(errorPanel.textContent).toContain('Mars 2026');
    expect(within(errorPanel).getByRole('button', { name: 'Réessayer' })).toBeTruthy();

    failed.unmount();
    api.readMonthlyRecap.mockReturnValue(ok({ ...recap, state: 'EMPTY' }));
    renderPage();
    expect(
      await screen.findByText('Aucune donnée comptabilisée pour cette synthèse.'),
    ).toBeTruthy();
    expect(screen.queryByTestId('monthly-budget-skeleton')).toBeNull();
  });

  it('renders exact zero, hides categories without movement and keeps every account', async () => {
    renderPage();

    expect(await screen.findByRole('button', { name: 'Ajouter un revenu' })).toBeTruthy();
    expect(await screen.findByText('Salaire')).toBeTruthy();
    expect(
      screen.getAllByText((_content, node) => node?.textContent === '-' && node.tagName === 'SPAN')
        .length,
    ).toBeGreaterThan(0);
    expect(screen.getByText('Compte courant')).toBeTruthy();
    expect(screen.queryByText('Livret vide')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Afficher les comptes sans mouvement' }));
    expect(screen.getByText('Livret vide')).toBeTruthy();
    expect(screen.getByText('Aucun mouvement')).toBeTruthy();
    expect(screen.queryByText('Voyages')).toBeNull();
    expect(screen.queryByText('Hors budget')).toBeNull();
    expect(screen.getByText('2 transactions en attente')).toBeTruthy();
  });

  it('shows the backend-owned totals in the ledger panel headers', async () => {
    renderPage();
    await loaded();

    const income = await screen.findByRole('region', { name: 'Revenus' });
    const expense = screen.getByRole('region', { name: 'Dépenses' });
    const transfers = screen.getByRole('region', { name: 'Virements' });

    await waitFor(() => {
      expect(within(income).getByText('Total des revenus').parentElement!.textContent).toContain(
        '1 200,00 €',
      );
      expect(within(expense).getByText('Total des dépenses').parentElement!.textContent).toContain(
        '-400,00 €',
      );
      expect(
        within(transfers).getByText('Total net des virements').parentElement!.textContent,
      ).toContain('-50,00 €');
    });
  });

  it('orders transfer accounts by movement and lets the owner hide empty targets', async () => {
    api.listAccounts.mockReturnValue(
      ok({
        items: [account, { ...account, id: emptyAccountId, label: 'Livret vide' }],
        page: 1,
        pageSize: 100,
        total: 2,
      }),
    );
    api.readMonthlyLedger.mockReturnValue(
      ok({ ...ledger, accounts: [...ledger.accounts].reverse() }),
    );
    renderPage();
    await loaded();
    const panel = screen.getByRole('region', { name: 'Virements' });
    expect(within(panel).getByText('Compte courant')).toBeTruthy();
    expect(within(panel).queryByText('Livret vide')).toBeNull();
    const toggle = within(panel).getByRole('button', {
      name: 'Afficher les comptes sans mouvement',
    });
    expect(toggle.getAttribute('aria-pressed')).toBe('false');
    expect(toggle.getAttribute('title')).toBe('Afficher les comptes sans mouvement');
    toggle.focus();
    expect(document.activeElement).toBe(toggle);
    fireEvent.click(toggle);
    expect(
      within(panel)
        .getAllByRole('listitem')
        .map((row) => row.textContent),
    ).toEqual([expect.stringContaining('Compte courant'), expect.stringContaining('Livret vide')]);
    expect(toggle.getAttribute('aria-pressed')).toBe('true');
    expect(toggle.getAttribute('title')).toBe('Masquer les comptes sans mouvement');
    expect(
      within(panel)
        .getByRole('button', { name: 'Ajouter un virement vers Livret vide' })
        .hasAttribute('disabled'),
    ).toBe(false);

    cleanup();
    renderPage();
    await loaded();
    const restored = screen.getByRole('region', { name: 'Virements' });
    expect(within(restored).getByText('Livret vide')).toBeTruthy();
    expect(
      within(restored)
        .getByRole('button', { name: 'Afficher les comptes sans mouvement' })
        .getAttribute('aria-pressed'),
    ).toBe('true');
  });

  it('renders plain colored category rows without a single-movement count and concise movement dates', async () => {
    renderPage();
    await loaded();
    const row = screen.getByText('Salaire').closest('li')!;
    expect(row.style.borderInlineStartColor).toBe('rgb(72, 109, 240)');
    expect(screen.queryByText('(1)')).toBeNull();
    expect(row.textContent).not.toContain('(1)');
    expect(screen.getByText('Salaire').getAttribute('title')).toBe('Salaire');
    expect(row.querySelector('[data-icon]')).toBeNull();
    const toggle = within(row).getByRole('button', { name: 'Afficher les mouvements de Salaire' });
    expect(toggle.textContent).toContain('Salaire');
    expect(toggle.textContent).not.toContain('(1)');
    expect(within(toggle).queryByRole('button')).toBeNull();
    expect(within(row).getByRole('button', { name: 'Ajouter un revenu dans Salaire' })).not.toBe(
      toggle,
    );
    fireEvent.click(toggle);
    const time = await within(row).findByText('14 mars');
    expect(row.style.borderInlineStartColor).toBe('rgb(72, 109, 240)');
    expect(time.tagName).toBe('TIME');
    expect(time.getAttribute('datetime')).toBe('2026-03-14');
    expect(
      within(row).getByRole('button', { name: /Modifier le mouvement .*14 mars 2026/ }),
    ).toBeTruthy();
  });

  it('offers no information button when the month has nothing pending', async () => {
    api.readMonthlyRecap.mockReturnValue(ok({ ...recap, state: 'COMPLETE', provisional: false }));
    renderPage();
    await loaded();
    await waitFor(() => expect(screen.queryByTestId('monthly-recap-skeleton')).toBeNull());
    expect(screen.queryByRole('button', { name: 'Informations sur la synthèse' })).toBeNull();
  });

  it('explains empty flow sections and exposes explanations through metric label controls', async () => {
    api.readMonthlyRecapPreferences.mockReturnValue(
      ok({ visibleCategoryIds: [], visibleAxes: [], version: 0 }),
    );
    renderPage();
    await loaded();
    await waitFor(() => expect(screen.queryByTestId('monthly-recap-skeleton')).toBeNull());
    const totals = screen.getByRole('region', { name: 'Synthèse des flux' });
    expect(within(totals).queryByRole('heading', { name: 'Dépenses par axe' })).toBeNull();
    expect(within(totals).queryByRole('heading', { name: 'Dépenses par catégorie' })).toBeNull();
    const settings = within(totals).getByRole('button', { name: 'Configurer l’affichage' });
    expect(settings.textContent).toBe('');
    expect(settings.getAttribute('title')).toBe('Configurer l’affichage');
    const explanation = within(totals).getByRole('button', {
      name: 'Expliquer : Revenus',
    });
    expect(explanation.textContent).toBe('Revenus');
    expect(explanation.querySelector('svg')).toBeNull();
    explanation.focus();
    expect(document.activeElement).toBe(explanation);
    fireEvent.click(explanation);
    expect(await screen.findByRole('dialog', { name: 'Expliquer : Revenus' })).toBeTruthy();
  });

  it('shows every flow indicator of the former monthly report in the flow summary', async () => {
    renderPage();
    await loaded();
    await waitFor(() => expect(screen.queryByTestId('monthly-recap-skeleton')).toBeNull());
    const totals = screen.getByRole('region', { name: 'Synthèse des flux' });
    for (const label of [
      'Revenus',
      'Avantages non monétaires',
      'Dépenses des avantages',
      'Dépenses',
      'Dépenses non catégorisées',
      'Excédent',
      'Virements vers l’épargne',
      'Taux d’épargne',
      'Entrées vers l’épargne',
      'Sorties de l’épargne',
      'Épargne nette par virements',
      'Taux d’épargne net',
    ]) {
      expect(within(totals).getByRole('button', { name: `Expliquer : ${label}` })).toBeTruthy();
    }
    const row = within(totals)
      .getByRole('button', { name: 'Expliquer : Avantages non monétaires' })
      .closest('li')!;
    expect(row.textContent).toMatch(/120,50/);
  });

  it('keeps the flow summary usable when the extra indicators cannot be read', async () => {
    api.readMonthlyProjection.mockReturnValue({
      data: undefined,
      response: { ok: false, status: 500 },
    });
    renderPage();
    await loaded();
    await waitFor(() => expect(screen.queryByTestId('monthly-recap-skeleton')).toBeNull());
    const totals = screen.getByRole('region', { name: 'Synthèse des flux' });
    const row = within(totals)
      .getByRole('button', { name: 'Expliquer : Avantages non monétaires' })
      .closest('li')!;
    expect(row.textContent).toMatch(/Non calculable/);
    expect(within(totals).getByRole('button', { name: 'Expliquer : Revenus' })).toBeTruthy();
  });

  it('sorts exact expense totals descending with stable ties and unavailable values last', async () => {
    const values = [
      '9007199254740992.000000000000000000000001',
      null,
      '9007199254740992.000000000000000000000002',
      '9007199254740992.000000000000000000000002',
    ];
    api.readMonthlyLedger.mockReturnValue(
      ok({
        ...ledger,
        expenseCategories: values.map((value, index) => ({
          ...ledger.expenseCategories[0],
          id: `expense-${index}`,
          label: `Expense ${index}`,
          hasMovements: true,
          movementCount: 1,
          total: { value, assetCode: 'EUR', reason: value === null ? 'MIXED_ASSETS' : null },
        })),
      }),
    );
    renderPage();
    await loaded();
    const panel = screen.getByRole('region', { name: 'Dépenses' });
    expect(
      within(panel)
        .getAllByRole('listitem')
        .map((row) => row.querySelector('button')?.getAttribute('aria-label')),
    ).toEqual([
      'Afficher les mouvements de Expense 2',
      'Afficher les mouvements de Expense 3',
      'Afficher les mouvements de Expense 0',
      'Afficher les mouvements de Expense 1',
    ]);
  });

  it('gives a row without movement no accordion toggle and mutes its text', async () => {
    renderPage();
    await loaded();

    const transfers = screen.getByRole('region', { name: 'Virements' });
    fireEvent.click(
      within(transfers).getByRole('button', { name: 'Afficher les comptes sans mouvement' }),
    );
    const emptyRow = within(transfers).getByText('Livret vide').closest('li')!;
    expect(
      screen.queryByRole('button', { name: 'Afficher les mouvements de Livret vide' }),
    ).toBeNull();
    expect(emptyRow.querySelector('[aria-controls]')).toBeNull();
    expect(emptyRow.className).toMatch(/rowEmpty/);
    expect(emptyRow.querySelector('button')?.getAttribute('aria-label')).toBe(
      'Ajouter un virement vers Livret vide',
    );

    const filledRow = within(transfers).getByText('Compte courant').closest('li')!;
    expect(
      screen.getByRole('button', { name: 'Afficher les mouvements de Compte courant' }),
    ).toBeTruthy();
    expect(filledRow.className).not.toMatch(/rowEmpty/);
  });

  it('shows the empty state of a panel whose categories all have no movement', async () => {
    api.readMonthlyLedger.mockReturnValue(ok({ ...ledger, incomeCategories: [] }));
    renderPage();
    await loaded();

    const income = await screen.findByRole('region', { name: 'Revenus' });
    expect(within(income).getByText('Aucune ligne active pour ce mois.')).toBeTruthy();
  });

  it('keeps an out-of-budget expense category that has movements', async () => {
    api.readMonthlyLedger.mockReturnValue(
      ok({
        ...ledger,
        expenseCategories: [
          {
            ...ledger.expenseCategories[0]!,
            total: { value: '-10.00', assetCode: 'EUR', reason: null },
            movementCount: 1,
            hasMovements: true,
          },
        ],
      }),
    );
    renderPage();

    expect(await screen.findByText('Voyages')).toBeTruthy();
    expect(screen.getByText('Hors budget')).toBeTruthy();
  });

  it('opens the transaction editor from a movement and refreshes the ledger after saving', async () => {
    const stored = {
      id: movementTransactionId,
      accountId,
      amount: { value: '2500.00', assetCode: 'EUR' },
      originalAmount: null,
      exchangeRate: null,
      nature: 'INCOME',
      state: 'BOOKED',
      source: 'MANUAL',
      sourceRef: null,
      bookedOn: '2026-03-14',
      valueOn: null,
      authorizedOn: null,
      rawLabel: 'Paie mars',
      counterparty: null,
      note: null,
      paymentMethod: null,
      mcc: null,
      maskedCard: null,
      bankReference: null,
      splits: [],
      version: 3,
      createdAt: '2026-03-14T09:00:00+01:00',
      updatedAt: '2026-03-14T09:00:00+01:00',
      voidedAt: null,
      transferId: null,
      refundOriginalId: null,
      refundOriginalLabel: null,
      refundedAmount: null,
      reviewReason: null,
      reconciliationCandidateIds: [],
      reconciledIntoId: null,
    };
    api.getTransaction.mockReturnValue(ok(stored));
    api.updateTransaction.mockImplementation(({ body }) => ok({ ...stored, ...body }));
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Afficher les mouvements de Salaire' }),
    );
    fireEvent.click(
      await screen.findByRole('button', {
        name: /^Modifier le mouvement Paie mars du .*, 2\s500,00\s€$/,
      }),
    );

    const label = await screen.findByRole('textbox', { name: 'Libellé' });
    const dialog = screen.getByRole('dialog', { name: 'Modifier la transaction' });
    expect(api.getTransaction).toHaveBeenCalledWith(
      expect.objectContaining({ path: { id: movementTransactionId } }),
    );
    const callsBeforeSave = api.readMonthlyLedger.mock.calls.length;
    const recapCallsBeforeSave = api.readMonthlyRecap.mock.calls.length;
    fireEvent.change(label, { target: { value: 'Paie mars corrigée' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(api.updateTransaction).toHaveBeenCalledOnce());
    expect(api.updateTransaction.mock.calls[0]?.[0]).toEqual(
      expect.objectContaining({ path: { id: movementTransactionId } }),
    );
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() =>
      expect(api.readMonthlyLedger.mock.calls.length).toBeGreaterThan(callsBeforeSave),
    );
    await waitFor(() =>
      expect(api.readMonthlyLedgerMovements.mock.calls.length).toBeGreaterThan(1),
    );
    await waitFor(() =>
      expect(api.readMonthlyRecap.mock.calls.length).toBeGreaterThan(recapCallsBeforeSave),
    );
    expect(await screen.findByText('Le mouvement a été enregistré.')).toBeTruthy();
  });

  it('offers a retry when the movement cannot be loaded', async () => {
    api.getTransaction.mockReturnValue(
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Afficher les mouvements de Salaire' }),
    );
    fireEvent.click(
      await screen.findByRole('button', { name: /^Modifier le mouvement Paie mars/ }),
    );

    const dialog = await screen.findByRole('dialog', { name: 'Modifier la transaction' });
    expect(await within(dialog).findByRole('alert')).toBeTruthy();
    expect(within(dialog).getByRole('button', { name: 'Réessayer' })).toBeTruthy();
  });

  it('does not turn a movement into an edit button while the period is closed', async () => {
    api.readMonthlyLedger.mockReturnValue(
      ok({
        ...ledger,
        closed: true,
        actionsAllowed: false,
        actionReason: 'PERIOD_CLOSED' as const,
      }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Afficher les mouvements de Salaire' }),
    );

    expect(await screen.findByText('Paie mars')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /^Modifier le mouvement/ })).toBeNull();
  });

  it('does not fetch row movements before its accessible accordion is opened', async () => {
    renderPage();

    const toggle = await screen.findByRole('button', {
      name: 'Afficher les mouvements de Salaire',
    });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(api.readMonthlyLedgerMovements).not.toHaveBeenCalled();

    fireEvent.click(toggle);

    await waitFor(() => expect(api.readMonthlyLedgerMovements).toHaveBeenCalledOnce());
    expect(api.readMonthlyLedgerMovements).toHaveBeenCalledWith(
      expect.objectContaining({
        path: { id: incomeId, kind: 'income' },
        query: { month: '2026-03' },
      }),
    );
    expect(await screen.findByText('Paie mars')).toBeTruthy();
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
  });

  it('continues an expanded row from the server cursor without loading every history', async () => {
    api.readMonthlyLedgerMovements
      .mockReturnValueOnce(
        ok({
          items: [
            {
              id: '00000000-0000-7000-8000-000000000004',
              transactionId: '00000000-0000-7000-8000-000000000005',
              transferId: null,
              bookedOn: '2026-03-14',
              label: 'Paie mars',
              amount: { value: '2500.00', assetCode: 'EUR' },
              direction: null,
              counterpartAccountId: null,
              counterpartAccountLabel: null,
            },
          ],
          nextCursor: 'next-page',
          hasMore: true,
          pageSize: 50,
        }),
      )
      .mockReturnValueOnce(ok({ items: [], nextCursor: null, hasMore: false, pageSize: 50 }));
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Afficher les mouvements de Salaire' }),
    );
    fireEvent.click(
      await screen.findByRole('button', { name: 'Afficher les mouvements suivants' }),
    );

    await waitFor(() => expect(api.readMonthlyLedgerMovements).toHaveBeenCalledTimes(2));
    expect(api.readMonthlyLedgerMovements.mock.calls[1]?.[0]).toEqual(
      expect.objectContaining({ query: { cursor: 'next-page', month: '2026-03' } }),
    );
  });

  it('preserves the selected axis in month links and ledger reads', async () => {
    window.history.replaceState({}, '', '/budget/2026/03?axis=ESSENTIAL');
    api.readMonthlyLedger.mockReturnValue(ok({ ...ledger, axis: 'ESSENTIAL' as const }));
    renderPage();
    await loaded();

    expect(((await screen.findByLabelText('Axe analytique')) as HTMLSelectElement).value).toBe(
      'ESSENTIAL',
    );
    expect(screen.getByRole('link', { name: 'Février' }).getAttribute('href')).toBe(
      '/budget/2026/02?axis=ESSENTIAL',
    );
    expect(api.readMonthlyLedger).toHaveBeenCalledWith(
      expect.objectContaining({ query: { axis: 'ESSENTIAL', month: '2026-03' } }),
    );
  });

  it('preselects the primary account in a budget transaction creation', async () => {
    api.listAccounts.mockReturnValue(
      ok({
        items: [{ ...account, isPrimary: true, kind: 'CURRENT' }],
        page: 1,
        pageSize: 100,
        total: 1,
      }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter un revenu dans Salaire' }));
    const dialog = await screen.findByRole('dialog', { name: 'Nouvelle transaction' });
    await waitFor(() =>
      expect(
        (within(dialog).getByRole('combobox', { name: 'Compte' }) as HTMLSelectElement).value,
      ).toBe(accountId),
    );
  });

  it('keeps the real draft frozen while account options load', async () => {
    let resolveAccounts: ((value: Awaited<ReturnType<typeof ok>>) => void) | undefined;
    api.listAccounts.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveAccounts = resolve;
        }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter un revenu dans Salaire' }));
    const skeleton = await screen.findByTestId('budget-creation-skeleton');
    expect(skeleton.querySelector('fieldset[disabled][inert]')).toBeTruthy();
    const frozenForm = skeleton.querySelector('form')!;
    const tags = Array.from(
      frozenForm.querySelectorAll('*:not(option)'),
      (element) => element.tagName,
    );
    expect(within(skeleton).getAllByRole('status')).toHaveLength(1);
    resolveAccounts?.(await ok({ items: [account], page: 1, pageSize: 100, total: 1 }));
    await waitFor(() => expect(screen.queryByTestId('budget-creation-skeleton')).toBeNull());
    const form = screen
      .getByRole('dialog', { name: 'Nouvelle transaction' })
      .querySelector('form')!;
    expect(
      Array.from(form.querySelectorAll('*:not(option)'), (element) => element.tagName),
    ).toEqual(tags);
    expect((screen.getByRole('combobox', { name: 'Catégorie' }) as HTMLInputElement).value).toBe(
      'Salaire',
    );
  });

  it('opens the shared transaction modal with month, nature and category prefilled', async () => {
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter un revenu dans Salaire' }));

    expect(await screen.findByRole('dialog', { name: 'Nouvelle transaction' })).toBeTruthy();
    expect(((await screen.findByLabelText('Date comptable')) as HTMLInputElement).value).toBe(
      '2026-03-15',
    );
    expect((screen.getByLabelText('Nature') as HTMLSelectElement).value).toBe('INCOME');
    expect((screen.getByRole('combobox', { name: 'Compte' }) as HTMLSelectElement).value).toBe('');
    expect((screen.getByRole('combobox', { name: 'Catégorie' }) as HTMLInputElement).value).toBe(
      'Salaire',
    );
  });

  it('adds a transaction from an empty panel header with no category preselected', async () => {
    api.readMonthlyLedger.mockReturnValue(ok({ ...ledger, expenseCategories: [] }));
    renderPage();
    await loaded();

    const expense = await screen.findByRole('region', { name: 'Dépenses' });
    expect(within(expense).getByText('Aucune ligne active pour ce mois.')).toBeTruthy();
    fireEvent.click(within(expense).getByRole('button', { name: 'Ajouter une dépense' }));

    expect(await screen.findByRole('dialog', { name: 'Nouvelle transaction' })).toBeTruthy();
    expect(((await screen.findByLabelText('Nature')) as HTMLSelectElement).value).toBe('EXPENSE');
    expect((screen.getByRole('combobox', { name: 'Catégorie' }) as HTMLInputElement).value).toBe(
      '',
    );
  });

  it('disables the panel free-add buttons while the period is closed', async () => {
    api.readMonthlyLedger.mockReturnValue(
      ok({
        ...ledger,
        closed: true,
        actionsAllowed: false,
        actionReason: 'PERIOD_CLOSED' as const,
      }),
    );
    renderPage();

    await screen.findByRole('region', { name: 'Revenus' });
    expect(
      screen.getByRole('button', { name: 'Ajouter un revenu' }).getAttribute('disabled'),
    ).not.toBeNull();
  });

  it('opens the shared transfer modal with destination and date prefilled but no source', async () => {
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Ajouter un virement vers Compte courant' }),
    );

    expect(await screen.findByRole('dialog', { name: 'Nouveau virement' })).toBeTruthy();
    expect(((await screen.findByLabelText('Date')) as HTMLInputElement).value).toBe('2026-03-15');
    expect((screen.getByLabelText('Compte source') as HTMLSelectElement).value).toBe('');
    expect((screen.getByLabelText('Compte de destination') as HTMLSelectElement).value).toBe(
      accountId,
    );
  });

  it('keeps closed-period creation disabled with a readable reason', async () => {
    api.readMonthlyLedger.mockReturnValue(
      ok({
        ...ledger,
        closed: true,
        actionsAllowed: false,
        actionReason: 'PERIOD_CLOSED' as const,
      }),
    );
    renderPage();

    const add = await screen.findByRole('button', { name: 'Ajouter un revenu dans Salaire' });
    expect(add.getAttribute('disabled')).not.toBeNull();
    expect(add.getAttribute('aria-describedby')).toBeTruthy();
    expect(screen.getAllByText('Cette période est clôturée.').length).toBeGreaterThan(0);
  });

  it('keeps the year selector and month tabs when the ledger fails to load', async () => {
    api.readMonthlyLedger.mockReturnValue(
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
    );
    renderPage();

    expect((await screen.findByRole('alert')).textContent).toContain('Réessayer');
    expect(screen.getByLabelText('Année')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Février' })).toBeTruthy();
  });

  it('keeps the month tabs while the ledger is loading', async () => {
    api.readMonthlyLedger.mockReturnValue(new Promise(() => undefined));
    renderPage();

    expect(screen.getByRole('status')).toBeTruthy();
    expect(screen.getByLabelText('Année')).toBeTruthy();
    expect(await screen.findByRole('link', { name: 'Février' })).toBeTruthy();
  });

  it('composes the transfer sentence from direction and counterpart account', async () => {
    api.readMonthlyLedgerMovements.mockReturnValue(
      ok({
        items: [
          {
            id: '00000000-0000-7000-8000-000000000006',
            transactionId: '00000000-0000-7000-8000-000000000007',
            transferId: '00000000-0000-7000-8000-000000000008',
            bookedOn: '2026-03-14',
            label: 'Livret A',
            amount: { value: '-300.00', assetCode: 'EUR' },
            direction: 'OUT',
            counterpartAccountId: '00000000-0000-7000-8000-000000000009',
            counterpartAccountLabel: 'Livret A',
          },
        ],
        nextCursor: null,
        hasMore: false,
        pageSize: 50,
      }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Afficher les mouvements de Compte courant' }),
    );

    expect(await screen.findByText('Sortie vers Livret A')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /^Modifier le mouvement/ })).toBeNull();
  });

  it('disables the transfer action for an account that is not active', async () => {
    api.listAccounts.mockReturnValue(
      ok({ items: [{ ...account, status: 'CLOSED' }], page: 1, pageSize: 100, total: 1 }),
    );
    renderPage();

    await waitFor(() => {
      const add = screen.getByRole('button', {
        name: 'Ajouter un virement vers Compte courant',
      });
      expect(add.getAttribute('disabled')).not.toBeNull();
      expect(add.getAttribute('aria-describedby')).toBeTruthy();
    });
    expect(
      screen.getAllByText("Ce compte est clos : aucun virement n'est possible.").length,
    ).toBeGreaterThan(0);
  });

  it('uses the ledger timezone, not a hard-coded one, to decide the current month', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-03-31T20:00:00Z'));
    api.readMonthlyLedger.mockReturnValue(ok({ ...ledger, timezone: 'Pacific/Auckland' }));
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    try {
      render(
        <QueryClientProvider client={client}>
          <MonthlyBudgetPage periodKey="2026-03" />
        </QueryClientProvider>,
      );

      expect(await screen.findByRole('link', { name: 'Avril' })).toBeTruthy();
    } finally {
      vi.useRealTimers();
    }
  });

  it('keeps the portfolio costs panel hidden until portfolio taxes are implemented', async () => {
    renderPage();
    await loaded();
    expect(screen.queryByRole('heading', { name: 'Coûts de portefeuille' })).toBeNull();
    cleanup();

    api.readMonthlyRecap.mockReturnValue(
      ok({
        ...recap,
        portfolioCosts: {
          items: [
            {
              accountId,
              label: 'PEA',
              assetCode: 'EUR',
              fees: { value: '8.98', assetCode: 'EUR' },
              taxes: { value: '4.95', assetCode: 'EUR' },
            },
          ],
          totalFees: { value: '8.98', assetCode: 'EUR' },
          totalTaxes: { value: '4.95', assetCode: 'EUR' },
          assetCode: 'EUR',
        },
      }),
    );
    renderPage();
    await loaded();
    expect(screen.queryByRole('heading', { name: 'Coûts de portefeuille' })).toBeNull();
  });

  it('keeps the metric policy and pending notice behind the recap info button', async () => {
    renderPage();

    await waitFor(() => expect(screen.queryByTestId('monthly-budget-skeleton')).toBeNull());
    await waitFor(() => expect(screen.queryByTestId('monthly-recap-skeleton')).toBeNull());
    const info = await screen.findByRole('button', { name: 'Informations sur la synthèse' });
    expect(screen.queryByText('Politique v1')).toBeNull();
    expect(screen.queryByText(/peuvent encore modifier/)).toBeNull();
    fireEvent.click(info);
    const dialog = await screen.findByRole('dialog', { name: 'Informations sur la synthèse' });
    expect(within(dialog).getByText('Politique v1')).toBeTruthy();
    expect(
      within(dialog).getByText('Des opérations en attente peuvent encore modifier cette synthèse.'),
    ).toBeTruthy();
  });

  it('shows provisional non-calculable recap values, links accounts, and saves owner preferences', async () => {
    api.listCategories.mockReturnValue(
      ok({
        items: [{ id: expenseId, label: 'Voyages', type: 'EXPENSE' }],
        page: 1,
        pageSize: 100,
        total: 1,
      }),
    );
    api.saveMonthlyRecapPreferences.mockReturnValue(
      ok({ visibleCategoryIds: [], visibleAxes: ['ESSENTIAL'], version: 1 }),
    );
    renderPage();

    expect(
      await screen.findByRole('img', { name: 'Valeurs provisoires à la date du jour.' }),
    ).toBeTruthy();
    expect(
      screen.queryByRole('button', { name: 'Valeurs provisoires à la date du jour.' }),
    ).toBeNull();
    expect(screen.getByText('Liquidités').closest('tr')?.style.borderInlineStartColor).toBe(
      'rgb(255, 255, 255)',
    );
    expect(
      screen.getByRole('link', { name: 'Compte courant' }).closest('tr')?.style
        .borderInlineStartColor,
    ).toBe('rgb(255, 255, 255)');
    expect(screen.getByRole('link', { name: 'Compte courant' }).getAttribute('href')).toBe(
      `/accounts/${accountId}`,
    );
    expect(screen.getAllByText('Non calculable').length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole('button', { name: 'Configurer l’affichage' }));
    const dialog = await screen.findByRole('dialog', { name: 'Affichage de la synthèse' });
    expect(screen.getAllByText('Voyages').length).toBeGreaterThan(0);
    fireEvent.click(await within(dialog).findByLabelText('Voyages'));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.saveMonthlyRecapPreferences).toHaveBeenCalledWith(
        expect.objectContaining({
          body: { visibleCategoryIds: [], visibleAxes: ['ESSENTIAL'], version: 0 },
        }),
      ),
    );
  });

  it('loads every income and expense category page for recap preferences', async () => {
    const firstPage = Array.from({ length: 100 }, (_, index) => ({
      id: `category-${index + 1}`,
      label: `Category ${index + 1}`,
      type: 'EXPENSE' as const,
    }));
    const incomeCategory = { id: 'category-101', label: 'Income 101', type: 'INCOME' as const };
    api.listCategories.mockImplementation(({ query }) =>
      ok(
        query?.page === 2
          ? { items: [incomeCategory], page: 2, pageSize: 100, total: 101 }
          : { items: firstPage, page: 1, pageSize: 100, total: 101 },
      ),
    );
    api.readMonthlyRecap.mockReturnValue(
      ok({
        ...recap,
        totals: {
          ...recap.totals,
          categories: [
            ...recap.totals.categories,
            { ...recap.totals.categories[0], id: incomeCategory.id },
          ],
        },
      }),
    );
    renderPage();
    await loaded();

    fireEvent.click(await screen.findByRole('button', { name: 'Configurer l’affichage' }));
    const dialog = await screen.findByRole('dialog', { name: 'Affichage de la synthèse' });
    expect(await within(dialog).findByLabelText('Income 101')).toBeTruthy();
    expect(api.listCategories).toHaveBeenLastCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ page: 2, pageSize: 100 }) }),
    );
  });

  it('tells spending outside the treasury perimeter apart on a category row, and nothing when null', async () => {
    api.readMonthlyRecap.mockReturnValue(
      ok({
        ...recap,
        totals: {
          ...recap.totals,
          categories: [
            { ...recap.totals.categories[0], benefitSpending: '12.00', benefitAssetCode: 'EUR' },
          ],
        },
      }),
    );
    renderPage();
    await loaded();
    const totals = await screen.findByRole('region', { name: 'Synthèse des flux' });
    const row = (
      await within(totals).findByRole('button', { name: 'Expliquer : Voyages' })
    ).closest('li')!;
    expect(row.textContent).toMatch(/dont avantages :\s*12,00/);
    // The total itself is untouched.
    expect(row.textContent).toMatch(/400,00/);
    cleanup();
    api.readMonthlyRecap.mockReturnValue(ok(recap));
    renderPage();
    await loaded();
    const plain = (
      await within(await screen.findByRole('region', { name: 'Synthèse des flux' })).findByRole(
        'button',
        { name: 'Expliquer : Voyages' },
      )
    ).closest('li')!;
    expect(plain.textContent).not.toMatch(/avantages/);
  });

  it('offers the display settings as tabs, categories then axes', async () => {
    renderPage();
    await loaded();

    fireEvent.click(await screen.findByRole('button', { name: 'Configurer l’affichage' }));
    const dialog = await screen.findByRole('dialog', { name: 'Affichage de la synthèse' });
    const tabs = within(dialog).getAllByRole('tab');
    expect(tabs.map((tab) => tab.textContent)).toEqual(['Catégories', 'Axes analytiques']);
    expect(tabs[0]!.getAttribute('aria-selected')).toBe('true');
    fireEvent.click(tabs[1]!);
    const axis = within(dialog).getByRole('tabpanel', { name: 'Axes analytiques' });
    expect(within(axis).getByLabelText('Essentiel')).toBeTruthy();
  });

  it('offers an archived category when it is represented by the recap', async () => {
    const archivedCategory = {
      id: expenseId,
      label: 'Voyages archivés',
      archived: true,
      type: 'EXPENSE' as const,
    };
    api.listCategories.mockReturnValue(
      ok({ items: [archivedCategory], page: 1, pageSize: 100, total: 1 }),
    );
    renderPage();
    await loaded();

    fireEvent.click(await screen.findByRole('button', { name: 'Configurer l’affichage' }));
    const dialog = await screen.findByRole('dialog', { name: 'Affichage de la synthèse' });
    expect(await within(dialog).findByLabelText('Voyages archivés')).toBeTruthy();
    expect(api.listCategories).toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ includeArchived: true }) }),
    );
  });

  it.each([
    ['25.00', '25', '+25,00', '+25,00 %', 'positive'],
    ['-25.00', '-25', '−25,00', '−25,00 %', 'negative'],
    ['0.00', '0', '-', '0,00 %', 'neutral'],
  ])(
    'presents canonical wealth variation %s with its sign and comparison dates',
    async (value, rate, amount, percent, tone) => {
      api.readMonthlyRecap.mockReturnValue(
        ok({
          ...recap,
          netWorth: {
            ...recap.netWorth,
            difference: { ...recap.netWorth.previous, value, display: { value, assetCode: 'EUR' } },
            differenceReason: null,
            changePercent: rate,
            changePercentDisplay: rate,
            changeReason: null,
          },
        }),
      );
      renderPage();
      await loaded();
      const panel = screen.getByRole('region', { name: 'Évolution du patrimoine' });
      const difference = within(panel).getByText('Variation').nextElementSibling!;
      const change = within(panel).getByText('Évolution').nextElementSibling!;
      expect(difference.textContent).toContain(amount);
      expect(change.textContent).toBe(percent);
      expect(difference.className).toContain(tone);
      expect(change.className).toContain(tone);
      expect(panel.textContent).toContain('Patrimoine février 2026');
      expect(panel.textContent).toContain('Patrimoine mars 2026');
      expect(
        within(panel)
          .getByRole('img', { name: 'Valeurs provisoires à la date du jour.' })
          .getAttribute('title'),
      ).toBe('Valeurs provisoires à la date du jour.');
      expect(
        within(panel).queryByRole('button', { name: 'Valeurs provisoires à la date du jour.' }),
      ).toBeNull();
    },
  );

  it('keeps null wealth metrics neutral with independent reasons', async () => {
    api.readMonthlyRecap.mockReturnValue(
      ok({
        ...recap,
        netWorth: {
          ...recap.netWorth,
          differenceReason: 'MIXED_ASSETS',
          changeReason: 'ZERO_BASE',
          changePercent: '5',
        },
      }),
    );
    renderPage();
    await loaded();
    const panel = screen.getByRole('region', { name: 'Évolution du patrimoine' });
    const difference = within(panel).getByText('Variation').nextElementSibling!;
    const change = within(panel).getByText('Évolution').nextElementSibling!;
    expect(difference.className).toContain('neutral');
    expect(change.className).toContain('neutral');
    expect(difference.textContent).not.toMatch(/[0-9]/);
    expect(change.textContent).not.toMatch(/[0-9]/);
    expect(difference.querySelector('[title]')?.getAttribute('title')).toContain(
      'plusieurs unités',
    );
    expect(change.querySelector('[title]')?.getAttribute('title')).toContain('nul');
  });

  it('reloads only expense rows when the analytic axis changes', async () => {
    renderPage();
    await loaded();
    expect(screen.getByText('Salaire')).toBeTruthy();

    let resolveNext: ((value: unknown) => void) | undefined;
    api.readMonthlyLedger.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveNext = resolve;
        }),
    );
    fireEvent.change(screen.getByLabelText('Axe analytique'), { target: { value: 'FIXED' } });
    await waitFor(() => expect(api.readMonthlyLedger).toHaveBeenCalledTimes(2));

    expect(screen.queryByTestId('monthly-budget-skeleton')).toBeNull();
    expect(screen.getByText('Salaire')).toBeTruthy();
    expect(
      within(screen.getByRole('region', { name: 'Virements' })).getByText('Compte courant'),
    ).toBeTruthy();
    const expense = screen.getByRole('region', { name: 'Dépenses' });
    expect(expense.querySelector('[data-skeleton]')).toBeTruthy();
    expect(within(expense).getByRole('combobox')).toBeTruthy();

    resolveNext?.({
      data: { ...ledger, axis: 'FIXED' },
      response: new Response('{}', { status: 200 }),
    });
    await waitFor(() => expect(expense.querySelector('[data-skeleton]')).toBeNull());
    expect(screen.queryByTestId('monthly-budget-skeleton')).toBeNull();
  });

  it('shows the selected axis total in the expense panel header', async () => {
    window.history.replaceState({}, '', '/budget/2026/03?axis=ESSENTIAL');
    renderPage();
    await loaded();
    const panel = screen.getByRole('region', { name: 'Dépenses' });
    const header = panel.querySelector('header')!;
    const actions = header.children[1] as HTMLElement;
    expect(within(panel).getByRole('combobox').getAttribute('aria-label')).toBe('Axe analytique');
    expect(actions.firstElementChild?.querySelector('select')).toBe(
      within(actions).getByRole('combobox'),
    );
    expect(actions.lastElementChild).toBe(
      within(actions).getByRole('button', { name: 'Ajouter une dépense' }),
    );
    expect(within(panel).getByText('Total des dépenses').parentElement?.textContent).toContain(
      '-400,00',
    );
    expect(within(panel).queryByRole('button', { name: /Expliquer/ })).toBeNull();
  });

  it('shows one stale valuation marker beside the accounts title', async () => {
    api.readMonthlyRecap.mockReturnValue(
      ok({
        ...recap,
        accounts: [
          {
            ...recap.accounts[0],
            currentValue: {
              value: '250.00',
              assetCode: 'EUR',
              quality: 'STALE' as const,
              ageDays: 5,
              valuedOn: '2026-03-10',
            },
            share: {
              ratio: '0.1234',
              percent: '12.34',
              percentDisplay: '12.34',
              reason: null,
            },
          },
        ],
      }),
    );
    renderPage();
    await loaded();

    const note = 'Valorisation vieille de 5 jours';
    const marker = screen.getByRole('img', { name: note });
    expect(marker.getAttribute('title')).toBe(note);
    expect(marker.closest('td')).toBeNull();
    expect(screen.getAllByRole('img', { name: note })).toHaveLength(1);
    expect(screen.getByText('12,34 %').closest('td')?.className).toContain('share');
  });

  it('reports N−1 valuation staleness even when the current value is current', async () => {
    api.readMonthlyRecap.mockReturnValue(
      ok({
        ...recap,
        netWorth: {
          ...recap.netWorth,
          current: recap.netWorth.previous,
          currentReason: null,
          difference: recap.netWorth.previous,
          differenceReason: null,
          changePercentDisplay: '0',
          changeReason: null,
          previousQuality: 'STALE' as const,
          previousStalestAgeDays: 3,
          previousStaleValuationCount: 1,
          quality: 'CURRENT' as const,
          missingValuationCount: 0,
          staleValuationCount: 0,
        },
      }),
    );
    renderPage();

    expect(await screen.findByText('Au N−1, 1 valorisation est ancienne (3 jours).')).toBeTruthy();
  });

  it('reloads preferences after a stale version refusal', async () => {
    api.saveMonthlyRecapPreferences.mockReturnValue(
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 409 }) }),
    );
    renderPage();
    await loaded();
    fireEvent.click(await screen.findByRole('button', { name: 'Configurer l’affichage' }));
    const dialog = await screen.findByRole('dialog', { name: 'Affichage de la synthèse' });
    fireEvent.click(await within(dialog).findByRole('button', { name: 'Enregistrer' }));
    expect(
      await within(dialog).findByRole('button', { name: 'Recharger les préférences' }),
    ).toBeTruthy();
    const readsBeforeReload = api.readMonthlyRecapPreferences.mock.calls.length;
    fireEvent.click(within(dialog).getByRole('button', { name: 'Recharger les préférences' }));
    await waitFor(() =>
      expect(api.readMonthlyRecapPreferences.mock.calls.length).toBeGreaterThan(readsBeforeReload),
    );
  });

  it('opens source transactions by label and amount for configured axes and categories', async () => {
    api.readMonthlyRecap.mockReturnValue(
      ok({
        ...recap,
        totals: {
          ...recap.totals,
          expensesByAxis: [
            {
              ...recap.totals.expensesByAxis[0],
              sourceTransactionIds: ['axis-transaction'],
              sourceTransactions: [
                {
                  id: 'axis-transaction',
                  bookedOn: '2026-03-01',
                  label: 'Loyer',
                  amount: { value: '-400.00', assetCode: 'EUR' },
                  state: 'BOOKED',
                },
              ],
            },
          ],
          categories: [
            {
              ...recap.totals.categories[0],
              sourceTransactionIds: ['category-transaction'],
              sourceTransactions: [
                {
                  id: 'category-transaction',
                  bookedOn: '2026-03-02',
                  label: 'Courses',
                  amount: { value: '-42.50', assetCode: 'EUR' },
                  state: 'BOOKED',
                },
              ],
            },
          ],
        },
      }),
    );
    renderPage();

    await loaded();
    await waitFor(() => expect(screen.queryByTestId('monthly-recap-skeleton')).toBeNull());
    fireEvent.click(
      await screen.findByRole('button', { name: 'Expliquer : Essentiel' }, { timeout: 5000 }),
    );
    const axisDialog = await screen.findByRole('dialog', { name: 'Expliquer : Essentiel' });
    expect(within(axisDialog).getByText('Loyer')).toBeTruthy();
    expect(axisDialog.textContent).toContain('-400,00 €');
    expect(within(axisDialog).queryByText('axis-transaction')).toBeNull();
    fireEvent.click(within(axisDialog).getByRole('button', { name: 'Fermer' }));
    fireEvent.click(screen.getByRole('button', { name: 'Expliquer : Voyages' }));
    const categoryDialog = await screen.findByRole('dialog', { name: 'Expliquer : Voyages' });
    expect(within(categoryDialog).getByText('Courses')).toBeTruthy();
    expect(categoryDialog.textContent).toContain('-42,50 €');
    expect(within(categoryDialog).queryByText('category-transaction')).toBeNull();
  });

  it('retries loading recap preferences and preference categories after errors', async () => {
    api.readMonthlyRecapPreferences.mockReturnValueOnce(
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
    );
    renderPage();
    const retry = await screen.findByRole('button', { name: 'Réessayer' });
    const preferenceReads = api.readMonthlyRecapPreferences.mock.calls.length;
    fireEvent.click(retry);
    await waitFor(() =>
      expect(api.readMonthlyRecapPreferences.mock.calls.length).toBeGreaterThan(preferenceReads),
    );

    api.listCategories.mockReturnValueOnce(
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Configurer l’affichage' }));
    const dialog = await screen.findByRole('dialog', { name: 'Affichage de la synthèse' });
    const categoryReads = api.listCategories.mock.calls.length;
    fireEvent.click(await within(dialog).findByRole('button', { name: 'Réessayer' }));
    await waitFor(() =>
      expect(api.listCategories.mock.calls.length).toBeGreaterThan(categoryReads),
    );
  });

  it('shows a recoverable error for malformed and future month routes without querying', () => {
    const { rerender } = renderPage('not-a-month');

    expect(screen.getByRole('alert').textContent).toContain('Période invalide');
    expect(api.readMonthlyLedger).not.toHaveBeenCalled();

    rerender(
      <QueryClientProvider client={new QueryClient()}>
        <MonthlyBudgetPage periodKey="2026-04" today="2026-03-15" />
      </QueryClientProvider>,
    );
    expect(screen.getByRole('alert').textContent).toContain('Période future');
  });

  describe('history bounds', () => {
    function navigationEvents() {
      const listener = vi.fn();
      window.addEventListener('popstate', listener);
      return { listener, stop: () => window.removeEventListener('popstate', listener) };
    }

    it('replaces a month before the first data month once, keeps the axis and announces it', async () => {
      api.readMonthlyBounds.mockReturnValue(
        ok({ firstDataMonth: '2023-11', currentMonth: '2026-03' }),
      );
      window.history.replaceState({}, '', '/budget/2023/01?axis=FIXED');
      const events = navigationEvents();
      const length = window.history.length;
      renderPage('2023-01');

      await waitFor(() =>
        expect(window.location.pathname + window.location.search).toBe(
          '/budget/2023/11?axis=FIXED',
        ),
      );
      expect(window.history.length).toBe(length);
      expect(events.listener).toHaveBeenCalledTimes(1);
      expect(
        await screen.findByText(/précède l’historique : affichage du mois de novembre 2023/),
      ).toBeTruthy();
      expect(api.readMonthlyLedger).not.toHaveBeenCalled();
      events.stop();
    });

    it('does not redirect a month on or after the first data month, gaps included', async () => {
      api.readMonthlyBounds.mockReturnValue(
        ok({ firstDataMonth: '2023-11', currentMonth: '2026-03' }),
      );
      window.history.replaceState({}, '', '/budget/2024/05');
      const events = navigationEvents();
      renderPage('2024-05');
      await loaded();

      expect(window.location.pathname).toBe('/budget/2024/05');
      expect(events.listener).not.toHaveBeenCalled();
      expect(screen.getByRole('link', { name: 'Juin' })).toBeTruthy();
      events.stop();
    });

    it('lists disabled months before the boundary with a reason', async () => {
      api.readMonthlyBounds.mockReturnValue(
        ok({ firstDataMonth: '2023-11', currentMonth: '2026-03' }),
      );
      window.history.replaceState({}, '', '/budget/2023/12');
      renderPage('2023-12');
      await loaded();

      expect(
        screen.getAllByRole('button', { name: /avant le début de l’historique/ }),
      ).toHaveLength(10);
      expect(screen.getByRole('link', { name: 'Novembre' })).toBeTruthy();
      const years = within(screen.getByRole('combobox', { name: 'Année' })).getAllByRole('option');
      expect(years.map((option) => option.textContent)).toEqual(['2026', '2025', '2024', '2023']);
    });

    it('does not redirect an empty workspace and still offers the current month', async () => {
      api.readMonthlyBounds.mockReturnValue(ok({ firstDataMonth: null, currentMonth: '2026-03' }));
      window.history.replaceState({}, '', '/budget/2026/03');
      const events = navigationEvents();
      renderPage('2026-03');
      await loaded();

      expect(events.listener).not.toHaveBeenCalled();
      expect(screen.getByRole('link', { name: 'Mars' })).toBeTruthy();
      expect(screen.queryByText(/précède l’historique/)).toBeNull();
      events.stop();
    });

    it('keeps the invalid and future route cards untouched by the bounds', async () => {
      renderPage('not-a-month');
      expect(screen.getByRole('alert').textContent).toContain('Période invalide');
      cleanup();
      renderPage('2026-04');
      expect(screen.getByRole('alert').textContent).toContain('Période future');
      expect(window.location.pathname).toBe('/budget/2026/03');
    });

    it('disables the month controls while the bounds load and never fetches the ledger', async () => {
      api.readMonthlyBounds.mockReturnValue(new Promise(() => undefined));
      renderPage('2026-03');

      const year = await screen.findByRole('combobox', { name: 'Année' });
      expect((year as HTMLSelectElement).disabled).toBe(true);
      expect(screen.queryAllByRole('link', { name: 'Mars' })).toHaveLength(0);
      expect(api.readMonthlyLedger).not.toHaveBeenCalled();
    });

    it('offers a retry without guessing a date or redirecting when the bounds fail', async () => {
      api.readMonthlyBounds.mockReturnValue(
        Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
      );
      window.history.replaceState({}, '', '/budget/2023/01');
      renderPage('2023-01');

      fireEvent.click(await screen.findByRole('button', { name: 'Réessayer' }));
      await waitFor(() => expect(api.readMonthlyBounds).toHaveBeenCalledTimes(2));
      expect(window.location.pathname).toBe('/budget/2023/01');
      expect(api.readMonthlyLedger).not.toHaveBeenCalled();
    });
  });
});
