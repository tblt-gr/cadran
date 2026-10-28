import type { NetWorth, NetWorthHistory } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { DashboardPage } from './DashboardPage';

const api = vi.hoisted(() => ({
  readNetWorth: vi.fn(),
  readNetWorthHistory: vi.fn(),
  readMonthlyProjection: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

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

const netWorth: NetWorth = {
  asOf: '2026-09-05',
  total: amount('124680.00'),
  reason: null,
  quality: 'CURRENT',
  stalestAgeDays: 0,
  eligibleAccountCount: 2,
  valuedAccountCount: 2,
  missingValuationCount: 0,
  staleValuationCount: 0,
  delta: {
    comparedOn: '2026-08-05',
    previousTotal: amount('122940.00'),
    previousReason: null,
    amount: amount('1740.00'),
    amountReason: null,
    rate: '0.014153651371808036440540',
    ratePercent: '1.415365137180803644054000',
    ratePercentDisplay: '1.42',
    rateReason: null,
  },
  contributions: [
    {
      accountId: '00000000-0000-7000-8000-0000000000d1',
      label: 'Compte courant',
      kind: 'CURRENT',
      netWorthSign: 1,
      primaryGroupId: '00000000-0000-7000-8000-0000000000b1',
      primaryGroupColor: '#486DF0',
      primaryGroupLabel: 'Liquidités',
      eligible: true,
      amount: amount('60104.00'),
      signedAmount: amount('60104.00'),
      quality: 'CURRENT',
      ageDays: 0,
      valuedOn: '2026-09-05',
      share: {
        ratio: null,
        percent: '48.200000000000000000000000',
        percentDisplay: '48.20',
        reason: null,
      },
    },
  ],
  allocation: [
    {
      color: '#486DF0',
      groupId: '00000000-0000-7000-8000-0000000000b1',
      label: 'Liquidités',
      parentId: null,
      depth: 1,
      value: amount('60104.00'),
      share: {
        ratio: null,
        percent: '4.20080440935498286906',
        percentDisplay: '4.20',
        reason: null,
      },
      directValue: amount('40104.00'),
      directShare: { ratio: null, percent: '2.80', percentDisplay: '2.80', reason: null },
      directPreviousValue: null,
      hasChildren: true,
    },
    {
      color: '#486DF0',
      groupId: '00000000-0000-7000-8000-0000000000b2',
      label: 'Livrets',
      parentId: '00000000-0000-7000-8000-0000000000b1',
      depth: 2,
      value: amount('20000.00'),
      share: {
        ratio: null,
        percent: '16.000000000000000000000000',
        percentDisplay: '16.00',
        reason: null,
      },
      directValue: amount('20000.00'),
      directShare: { ratio: null, percent: '16.00', percentDisplay: '16.00', reason: null },
      directPreviousValue: null,
      hasChildren: false,
    },
  ],
};

const history: NetWorthHistory = {
  asOf: '2026-09-05',
  granularity: 'MONTH',
  delta: netWorth.delta,
  period: 'LAST_12_MONTHS',
  firstDataMonth: '2026-07',
  truncated: false,
  points: [
    { on: '2026-07-31', total: null, reason: 'MISSING_VALUATION', quality: 'MISSING' },
    { on: '2026-08-31', total: amount('122940.00'), reason: null, quality: 'STALE' },
    { on: '2026-09-05', total: amount('124680.00'), reason: null, quality: 'CURRENT' },
  ],
};

function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function problem(status: number) {
  const body = { type: 'about:blank', title: 'Refus', status, detail: 'Refus' };

  return Promise.resolve({
    data: undefined,
    error: body,
    response: new Response(JSON.stringify(body), { status }),
  });
}

function metric(value: string | null, assetCode: string | null) {
  return { value, assetCode, reason: value === null ? 'ZERO_BASE' : null, pendingCount: 0 };
}

function monthlyReport() {
  return {
    cashIncome: metric('4320.00', 'EUR'),
    budgetExpenses: metric('2860.00', 'EUR'),
    cashSavingsRate: metric('0.338', null),
  };
}

function renderDashboard(report: object = monthlyReport()) {
  api.readMonthlyProjection.mockImplementation(() => success(report));
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        <DashboardPage apiVersion="v1" />
      </QueryClientProvider>,
    ),
    queryClient,
  };
}

async function loaded() {
  await waitFor(() => expect(screen.queryByTestId('dashboard-skeleton')).toBeNull());
}

describe('DashboardPage', () => {
  afterEach(() => {
    cleanup();
    vi.resetAllMocks();
    window.history.replaceState({}, '', '/');
    window.localStorage.clear();
  });

  it('replaces its one named cold-load skeleton and keeps data during a refetch', async () => {
    let resolveNetWorth:
      ((value: Awaited<ReturnType<typeof success<NetWorth>>>) => void) | undefined;
    api.readNetWorth.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveNetWorth = resolve;
        }),
    );
    api.readNetWorthHistory.mockReturnValue(success(history));

    const { queryClient } = renderDashboard();

    const skeleton = screen.getByTestId('dashboard-skeleton');
    expect(within(skeleton).getAllByRole('status')).toHaveLength(1);
    resolveNetWorth?.(await success(netWorth));
    await loaded();
    expect(screen.getByRole('heading', { name: 'Patrimoine net' })).toBeTruthy();

    api.readNetWorth.mockImplementation(() => new Promise(() => undefined));
    void queryClient.invalidateQueries({ queryKey: ['net-worth'] });
    await waitFor(() => expect(api.readNetWorth).toHaveBeenCalledTimes(2));
    expect(screen.getByRole('heading', { name: 'Patrimoine net' })).toBeTruthy();
    expect(screen.queryByTestId('dashboard-skeleton')).toBeNull();
  });

  it.each([
    ['', '12 mois', 'LAST_12_MONTHS'],
    ['?periode=ytd', 'Depuis janvier', 'YTD'],
    ['?periode=tout', 'Tout', 'ALL'],
    ['?periode=invalid', '12 mois', 'LAST_12_MONTHS'],
  ])('restores %s from the URL', async (query, label, period) => {
    window.history.replaceState({}, '', `/${query}`);
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));
    renderDashboard();
    await loaded();
    expect(screen.getByRole('group', { name: 'Période du graphique' })).toBeTruthy();
    expect(screen.getByRole('button', { name: label, pressed: true })).toBeTruthy();
    expect(api.readNetWorthHistory).toHaveBeenCalledWith(
      expect.objectContaining({ query: { period } }),
    );
  });

  it('keeps the previous series busy until the selected period arrives and updates its table', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValueOnce(success(history));
    let resolveHistory:
      ((value: Awaited<ReturnType<typeof success<NetWorthHistory>>>) => void) | undefined;
    api.readNetWorthHistory.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveHistory = resolve;
        }),
    );
    renderDashboard();
    await loaded();
    await screen.findByRole('img', { name: /Évolution du patrimoine net/ });
    fireEvent.click(screen.getByRole('button', { name: 'Tout' }));
    expect(window.location.search).toBe('?periode=tout');
    const chart = screen.getByRole('figure', { name: 'Évolution du patrimoine' });
    expect(chart.closest('[aria-busy]')?.getAttribute('aria-busy')).toBe('true');
    expect(chart.textContent).toContain('31 juillet 2026');
    expect(screen.getByText('1,42 %').closest('[aria-busy]')?.getAttribute('aria-busy')).toBe(
      'true',
    );
    await waitFor(() => expect(resolveHistory).toBeDefined());
    resolveHistory?.(
      await success({ ...history, period: 'ALL', truncated: true, points: [history.points[2]] }),
    );
    await waitFor(() =>
      expect(chart.closest('[aria-busy]')?.getAttribute('aria-busy')).toBe('false'),
    );
    expect(chart.textContent).not.toContain('31 juillet 2026');
    expect(chart.querySelectorAll('tbody tr')).toHaveLength(1);
    expect(screen.getByText('Seuls les 60 derniers mois sont affichés.')).toBeTruthy();
  });

  it('shows each server comparison for its selected period instead of the standalone monthly delta', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    const comparisons = {
      LAST_12_MONTHS: { value: '400.00', rate: '50.00', on: '2025-09-05' },
      ALL: { value: '600.00', rate: '100.00', on: '2020-03-12' },
      YTD: { value: '200.00', rate: '20.00', on: '2025-12-31' },
    };
    api.readNetWorthHistory.mockImplementation(
      ({ query }: { query: { period: keyof typeof comparisons } }) => {
        const comparison = comparisons[query.period];
        return success({
          ...history,
          delta: {
            ...history.delta,
            comparedOn: comparison.on,
            amount: amount(comparison.value),
            ratePercentDisplay: comparison.rate,
          },
        });
      },
    );
    renderDashboard();
    await loaded();
    const card = screen.getByRole('region', { name: 'Patrimoine net' });
    await waitFor(() => expect(within(card).getByText('50,00 %')).toBeTruthy());
    expect(within(card).getByRole('img', { name: `Hausse de 400,00${NBSP}€` })).toBeTruthy();
    expect(card.textContent).toContain('depuis le 5 septembre 2025');
    fireEvent.click(screen.getByRole('button', { name: 'Tout' }));
    await waitFor(() => expect(within(card).getByText('100,00 %')).toBeTruthy());
    expect(within(card).getByRole('img', { name: `Hausse de 600,00${NBSP}€` })).toBeTruthy();
    expect(card.textContent).toContain('depuis le 12 mars 2020');
    fireEvent.click(screen.getByRole('button', { name: 'Depuis janvier' }));
    await waitFor(() => expect(within(card).getByText('20,00 %')).toBeTruthy());
    expect(within(card).getByRole('img', { name: `Hausse de 200,00${NBSP}€` })).toBeTruthy();
    expect(card.textContent).toContain('depuis le 31 décembre 2025');
    expect(card.textContent).not.toContain('1,42 %');
  });

  it('keeps the history comparison pending and reports its failure without borrowing the standalone delta', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    let resolveHistory: ((value: Awaited<ReturnType<typeof problem>>) => void) | undefined;
    api.readNetWorthHistory.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveHistory = resolve;
        }),
    );
    renderDashboard();
    await loaded();
    const card = screen.getByRole('region', { name: 'Patrimoine net' });
    expect(card.textContent).not.toContain('1,42 %');
    expect(card.querySelector('[data-skeleton]')).toBeTruthy();
    resolveHistory?.(await problem(500));
    await screen.findByText('Évolution indisponible pour le moment.');
    expect(card.textContent).not.toContain('1,42 %');
  });

  it('preserves unrelated URL state and responds to browser navigation', async () => {
    window.history.replaceState({}, '', '/?other=kept#chart');
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));
    renderDashboard();
    await loaded();
    fireEvent.click(screen.getByRole('button', { name: 'Depuis janvier' }));
    expect(window.location.search).toBe('?other=kept&periode=ytd');
    expect(window.location.hash).toBe('#chart');
    window.history.replaceState({}, '', '/?periode=tout');
    fireEvent(window, new PopStateEvent('popstate'));
    expect(screen.getByRole('button', { name: 'Tout', pressed: true })).toBeTruthy();
  });

  it('allows another selection after a history request fails', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValueOnce(problem(500));
    renderDashboard();
    await loaded();
    await screen.findByText('Historique indisponible pour le moment.');
    api.readNetWorthHistory.mockReturnValue(success(history));
    fireEvent.click(screen.getByRole('button', { name: 'Tout' }));
    await screen.findByRole('figure', { name: 'Évolution du patrimoine' });
  });

  it('renders the selected disabled period buttons in the first-load skeleton', () => {
    window.history.replaceState({}, '', '/?periode=ytd');
    api.readNetWorth.mockImplementation(() => new Promise(() => undefined));
    api.readNetWorthHistory.mockImplementation(() => new Promise(() => undefined));
    renderDashboard();
    const radios = within(screen.getByTestId('dashboard-skeleton')).getAllByRole('button');
    expect(radios.map((radio) => radio.textContent)).toEqual(['Tout', '12 mois', 'Depuis janvier']);
    expect(radios.every((radio) => (radio as HTMLButtonElement).disabled)).toBe(true);
    expect(screen.getByRole('button', { name: 'Depuis janvier', pressed: true })).toBeTruthy();
    const table = within(screen.getByTestId('dashboard-skeleton')).getByRole('table', {
      name: 'Évolution du patrimoine',
    });
    expect(within(table).getByRole('columnheader', { name: 'Date' })).toBeTruthy();
    expect(within(table).getByRole('columnheader', { name: 'Patrimoine net' })).toBeTruthy();
  });

  it('shows the backend total, delta and rate without recomputing them', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();
    await loaded();

    const card = await screen.findByRole('heading', { name: 'Patrimoine net' });
    const section = card.closest('section');
    expect(section?.textContent).toContain(`124${NARROW}680,00${NBSP}€`);
    expect(section?.textContent).toContain(`1${NARROW}740,00${NBSP}€`);
    expect(section?.textContent).toContain('1,42 %');
    expect(section?.textContent).not.toContain('Au 5 septembre 2026');
    expect(section?.textContent).not.toContain('1,415365137180803644054 %');
    expect(
      section
        ?.querySelector(`[aria-label="Hausse de 1${NARROW}740,00${NBSP}€"]`)
        ?.getAttribute('data-tone'),
    ).toBe('up');
    expect(
      within(section as HTMLElement)
        .getByText('1,42 %')
        .getAttribute('data-tone'),
    ).toBe('up');
  });

  it('lists only the top-level exclusive groups in the allocation in parent mode', async () => {
    window.localStorage.setItem('cadran.synthese.grouping', 'PARENT');
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();
    await loaded();

    const allocation = (await screen.findByRole('heading', { name: 'Allocation' })).closest(
      'section',
    ) as HTMLElement;
    fireEvent.click(within(allocation).getByRole('button', { name: 'Camembert' }));
    expect(allocation.textContent).toContain('Liquidités');
    expect(allocation.textContent).not.toContain('Livrets');
    expect(allocation.textContent).toContain('4,20 %');
    expect(allocation.textContent).not.toContain('4,20080440935498286906');
    const bars = Array.from(allocation.querySelectorAll('svg[width$="%"]'));
    expect(bars.map((bar) => bar.getAttribute('width'))).toEqual(['4.2%']);
  });

  it('defaults to sub-group mode with direct values, then switches and remembers parent mode', async () => {
    window.localStorage.clear();
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();
    await loaded();

    const allocation = (await screen.findByRole('heading', { name: 'Allocation' })).closest(
      'section',
    ) as HTMLElement;
    const toggle = within(allocation).getByRole('button', { name: 'Détailler par sous-groupe' });
    expect(toggle.getAttribute('aria-pressed')).toBe('true');
    fireEvent.click(within(allocation).getByRole('button', { name: 'Camembert' }));
    expect(allocation.textContent).toContain('Livrets');
    expect(allocation.textContent).toContain('(Liquidités)');
    expect(allocation.textContent).toContain('2,80 %');
    expect(allocation.textContent).toContain('16,00 %');
    expect(allocation.textContent).not.toContain('4,20 %');

    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-pressed')).toBe('false');
    expect(window.localStorage.getItem('cadran.synthese.grouping')).toBe('PARENT');
    expect(allocation.textContent).not.toContain('Livrets');
    expect(allocation.textContent).toContain('4,20 %');
    expect(api.readNetWorth).toHaveBeenCalledTimes(1);
  });

  it('shows the reason in the legend in sub-group mode when nothing is valued', async () => {
    window.localStorage.clear();
    const share = { ratio: null, percent: null, percentDisplay: null, reason: null };
    api.readNetWorth.mockReturnValue(
      success({
        ...netWorth,
        total: null,
        reason: 'MISSING_VALUATION',
        allocation: netWorth.allocation.map((entry) => ({
          ...entry,
          value: null,
          share,
          directValue: null,
          directShare: share,
        })),
      }),
    );
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();
    await loaded();

    const allocation = (await screen.findByRole('heading', { name: 'Allocation' })).closest(
      'section',
    ) as HTMLElement;
    fireEvent.click(within(allocation).getByRole('button', { name: 'Camembert' }));
    expect(allocation.textContent).toContain('Livrets');
    expect(allocation.textContent).toContain('Liquidités');
    expect(allocation.querySelectorAll('ul li').length).toBe(2);
    expect(allocation.querySelector('ul')?.textContent).not.toMatch(/\d,\d\d %/);
  });

  it('offers Treemap and Camembert toggles in the allocation section', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();
    await loaded();

    const allocation = (await screen.findByRole('heading', { name: 'Allocation' })).closest(
      'section',
    ) as HTMLElement;
    expect(within(allocation).getByRole('button', { name: 'Treemap' })).toBeTruthy();
    expect(within(allocation).getByRole('button', { name: 'Camembert' })).toBeTruthy();
  });

  it('labels the wealth chart axes and shows date plus primary value on hover', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();
    await loaded();

    await screen.findByRole('img', { name: /Évolution du patrimoine net/ });

    const yAxis = screen.getByRole('group', { name: 'Patrimoine net' });
    expect(yAxis.textContent).toContain(`122${NARROW}940,00${NBSP}€`);
    expect(yAxis.textContent).toContain(`124${NARROW}680,00${NBSP}€`);

    const xAxis = screen.getByRole('group', { name: 'Date' });
    expect(xAxis.textContent).toContain('31/07/2026');
    expect(xAxis.textContent).toContain('05/09/2026');

    fireEvent.mouseEnter(screen.getByRole('button', { name: /31\/08\/2026/ }));
    const tooltip = screen.getByRole('tooltip');
    expect(tooltip.textContent).toContain('31/08/2026');
    expect(tooltip.textContent).toContain(`122${NARROW}940,00${NBSP}€`);
  });

  it('offers a tabular alternative that names an uncomputable month instead of showing zero', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();
    await loaded();

    expect(screen.queryByText('Voir les données sous forme de tableau')).toBeNull();
    const table = screen.getByRole('table', { name: 'Évolution du patrimoine' });
    expect(within(table).getByText('31 juillet 2026')).toBeTruthy();
    expect(within(table).getAllByText('Non calculable').length).toBe(1);
    expect(table.textContent).toContain(`124${NARROW}680,00${NBSP}€`);
  });

  it('marks a lone computable month so it does not read as no data at all', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(
      success({
        ...history,
        points: [
          { on: '2026-07-31', total: null, reason: 'NO_ELIGIBLE_ACCOUNT', quality: 'MISSING' },
          { on: '2026-08-31', total: null, reason: 'MISSING_VALUATION', quality: 'MISSING' },
          { on: '2026-09-05', total: amount('124680.00'), reason: null, quality: 'CURRENT' },
        ],
      } satisfies NetWorthHistory),
    );

    renderDashboard();
    await loaded();

    const chart = await screen.findByRole('img', { name: /Évolution du patrimoine net/ });
    expect(chart.querySelectorAll('circle')).toHaveLength(1);
  });

  it('states why a total is missing rather than publishing a zero', async () => {
    api.readNetWorth.mockReturnValue(
      success({
        ...netWorth,
        total: null,
        reason: 'MISSING_VALUATION',
        quality: 'MISSING',
        missingValuationCount: 1,
        delta: { ...netWorth.delta, amount: null, amountReason: 'MISSING_VALUATION' },
      } satisfies NetWorth),
    );
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();
    await loaded();

    const section = (await screen.findByRole('heading', { name: 'Patrimoine net' })).closest(
      'section',
    );
    expect(section?.textContent).toContain(
      'Au moins un compte inclus n’a pas de valorisation à cette date',
    );
    expect(section?.textContent).toContain('1 valorisation manquante');
    // The headline states its reason instead of borrowing the shape of a zero.
    expect(within(section as HTMLElement).getAllByText('Non calculable').length).toBeGreaterThan(0);
  });

  it('opens the accounts behind the missing-valuation badge', async () => {
    const missing = { ...netWorth.contributions[0]!, quality: 'MISSING' as const, amount: null };
    api.readNetWorth.mockReturnValue(
      success({
        ...netWorth,
        quality: 'MISSING',
        missingValuationCount: 1,
        contributions: [missing],
      } satisfies NetWorth),
    );
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();
    await loaded();

    fireEvent.click(await screen.findByRole('button', { name: /1 valorisation manquante/ }));

    const dialog = await screen.findByRole('dialog', { name: '1 compte sans valorisation' });
    expect(
      within(dialog).getByRole('link', { name: 'Voir le compte « Compte courant »' }),
    ).toBeTruthy();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Fermer' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('keeps the delta and drops the rate on a negative base', async () => {
    api.readNetWorth.mockReturnValue(
      success({
        ...netWorth,
        total: amount('-150000.00'),
      } satisfies NetWorth),
    );
    api.readNetWorthHistory.mockReturnValue(
      success({
        ...history,
        delta: {
          ...netWorth.delta,
          previousTotal: amount('-202050.00'),
          amount: amount('52050.00'),
          rate: null,
          ratePercent: null,
          ratePercentDisplay: null,
          rateReason: 'NEGATIVE_BASE',
        },
      }),
    );

    renderDashboard();
    await loaded();

    const section = (await screen.findByRole('heading', { name: 'Patrimoine net' })).closest(
      'section',
    );
    expect(section?.textContent).toContain(`52${NARROW}050,00${NBSP}€`);
    expect(section?.textContent).toContain('Taux non calculable sur une base négative');
    expect(within(section as HTMLElement).queryByText(/%$/)).toBeNull();
  });

  it('presents a flat month as stable rather than as a rise', async () => {
    api.readNetWorth.mockReturnValue(
      success({
        ...netWorth,
      } satisfies NetWorth),
    );
    api.readNetWorthHistory.mockReturnValue(
      success({
        ...history,
        delta: {
          ...netWorth.delta,
          amount: amount('0.00'),
          rate: '0',
          ratePercent: '0',
          ratePercentDisplay: '0.00',
        },
      }),
    );

    renderDashboard();
    await loaded();

    const section = (await screen.findByRole('heading', { name: 'Patrimoine net' })).closest(
      'section',
    );
    expect(
      section?.querySelector('[aria-label="Patrimoine stable, aucune variation"]'),
    ).toBeTruthy();
    expect(section?.querySelector('[aria-label^="Hausse"]')).toBeNull();
    expect(screen.getByRole('img', { name: 'Patrimoine stable, aucune variation' })).toBeTruthy();
  });

  it('speaks a decrease without a double negative', async () => {
    api.readNetWorth.mockReturnValue(
      success({
        ...netWorth,
      } satisfies NetWorth),
    );
    api.readNetWorthHistory.mockReturnValue(
      success({
        ...history,
        delta: {
          ...netWorth.delta,
          amount: amount('-1740.00'),
          rate: '-0.014153651371808036440540',
          ratePercent: '-1.415365137180803644054000',
          ratePercentDisplay: '-1.42',
        },
      }),
    );

    renderDashboard();
    await loaded();

    const section = (await screen.findByRole('heading', { name: 'Patrimoine net' })).closest(
      'section',
    );
    expect(
      section
        ?.querySelector(`[aria-label="Baisse de 1${NARROW}740,00${NBSP}€"]`)
        ?.getAttribute('data-tone'),
    ).toBe('down');
    expect(
      within(section as HTMLElement)
        .getByText('−1,42 %')
        .getAttribute('data-tone'),
    ).toBe('down');
  });

  it('names an unauthorized caller instead of showing an empty portfolio', async () => {
    api.readNetWorth.mockReturnValue(problem(403));
    api.readNetWorthHistory.mockReturnValue(problem(403));

    renderDashboard();
    await loaded();

    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByText('Patrimoine inaccessible')).toBeTruthy();
    expect(screen.queryByRole('heading', { name: 'Patrimoine net' })).toBeNull();
  });

  it('offers a retry when the read fails', async () => {
    api.readNetWorth.mockReturnValue(problem(500));
    api.readNetWorthHistory.mockReturnValue(problem(500));

    renderDashboard();
    await loaded();

    const retry = await screen.findByRole('button', { name: 'Réessayer' });
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));
    fireEvent.click(retry);

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Patrimoine net' })).toBeTruthy();
    });
  });

  it('shows the current month figures from the monthly projection and names the month', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();
    await loaded();

    const income = (await screen.findByRole('heading', { name: 'Revenus' })).closest('section');
    await waitFor(() => expect(income?.textContent).toContain(`4${NARROW}320,00${NBSP}€`));
    const month = new Date().toISOString().slice(0, 7);
    expect(api.readMonthlyProjection).toHaveBeenCalledWith(
      expect.objectContaining({ query: { month } }),
    );
    expect(income?.textContent).toContain('Mois en cours :');
    const spending = screen.getByRole('heading', { name: 'Dépenses' }).closest('section');
    expect(spending?.textContent).toContain(`2${NARROW}860,00${NBSP}€`);
    const saving = screen.getByRole('heading', { name: 'Taux d’épargne' }).closest('section');
    expect(saving?.textContent).toContain('33,80 %');
  });

  it('keeps a non computable month as a dash-like label, never zero', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard({ ...monthlyReport(), cashSavingsRate: metric(null, null) });
    await loaded();

    const saving = (await screen.findByRole('heading', { name: 'Taux d’épargne' })).closest(
      'section',
    );
    await waitFor(() => expect(saving?.textContent).toContain('Non calculable'));
  });

  it('no longer renders the data quality panel', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();
    await loaded();

    expect(screen.queryByRole('heading', { name: 'Qualité des données' })).toBeNull();
    expect(screen.queryByRole('complementary')).toBeNull();
  });
});
