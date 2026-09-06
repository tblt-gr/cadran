import type { NetWorth, NetWorthHistory } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { DashboardContextPanel } from './context-panel/DashboardContextPanel';
import { DashboardPage } from './DashboardPage';

const api = vi.hoisted(() => ({
  readNetWorth: vi.fn(),
  readNetWorthHistory: vi.fn(),
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
    },
    {
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
    },
  ],
};

const history: NetWorthHistory = {
  asOf: '2026-09-05',
  granularity: 'MONTH',
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

function renderDashboard() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <DashboardPage apiVersion="v1" />
      <DashboardContextPanel />
    </QueryClientProvider>,
  );
}

describe('DashboardPage', () => {
  afterEach(() => {
    cleanup();
    vi.resetAllMocks();
  });

  it('shows the backend total, delta and rate without recomputing them', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();

    const card = await screen.findByRole('heading', { name: 'Patrimoine net' });
    const section = card.closest('section');
    expect(section?.textContent).toContain(`124${NARROW}680,00${NBSP}€`);
    expect(section?.textContent).toContain(`1${NARROW}740,00${NBSP}€`);
    expect(section?.textContent).toContain('1,42 %');
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

  it('lists only the top-level exclusive groups in the allocation', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();

    const allocation = (await screen.findByRole('heading', { name: 'Allocation' })).closest(
      'section',
    ) as HTMLElement;
    fireEvent.click(within(allocation).getByRole('button', { name: 'Camembert' }));
    expect(allocation.textContent).toContain('Liquidités');
    expect(allocation.textContent).not.toContain('Livrets');
    expect(allocation.textContent).toContain('4,20 %');
    expect(allocation.textContent).not.toContain('4,20080440935498286906');
    const bars = Array.from(allocation.querySelectorAll<HTMLElement>('[style*="width"]'));
    expect(bars.map((bar) => bar.style.width)).toEqual(['4.2%']);
  });

  it('offers Treemap and Camembert toggles in the allocation section', async () => {
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();

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

    fireEvent.click(await screen.findByText('Voir les données sous forme de tableau'));
    const table = screen.getByRole('table', { name: 'Évolution du patrimoine' });
    expect(within(table).getByText('Juillet 2026')).toBeTruthy();
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

    const chart = await screen.findByRole('img');
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

  it('keeps the delta and drops the rate on a negative base', async () => {
    api.readNetWorth.mockReturnValue(
      success({
        ...netWorth,
        total: amount('-150000.00'),
        delta: {
          ...netWorth.delta,
          previousTotal: amount('-202050.00'),
          amount: amount('52050.00'),
          rate: null,
          ratePercent: null,
          ratePercentDisplay: null,
          rateReason: 'NEGATIVE_BASE',
        },
      } satisfies NetWorth),
    );
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();

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
        delta: {
          ...netWorth.delta,
          amount: amount('0.00'),
          rate: '0',
          ratePercent: '0',
          ratePercentDisplay: '0.00',
        },
      } satisfies NetWorth),
    );
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();

    const section = (await screen.findByRole('heading', { name: 'Patrimoine net' })).closest(
      'section',
    );
    expect(
      section?.querySelector('[aria-label="Patrimoine stable, aucune variation"]'),
    ).toBeTruthy();
    expect(section?.querySelector('[aria-label^="Hausse"]')).toBeNull();
  });

  it('speaks a decrease without a double negative', async () => {
    api.readNetWorth.mockReturnValue(
      success({
        ...netWorth,
        delta: {
          ...netWorth.delta,
          amount: amount('-1740.00'),
          rate: '-0.014153651371808036440540',
          ratePercent: '-1.415365137180803644054000',
          ratePercentDisplay: '-1.42',
        },
      } satisfies NetWorth),
    );
    api.readNetWorthHistory.mockReturnValue(success(history));

    renderDashboard();

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

  it('tells an outage apart from a missing valuation in the quality panel', async () => {
    api.readNetWorth.mockReturnValue(problem(500));
    api.readNetWorthHistory.mockReturnValue(problem(500));

    renderDashboard();

    const panel = (await screen.findByRole('heading', { name: 'Qualité des données' })).closest(
      'aside',
    );
    await waitFor(() => expect(panel?.textContent).toContain('Connexion interrompue'));
    expect(panel?.textContent).not.toContain('Valorisation manquante');
  });

  it('names an unauthorized caller instead of showing an empty portfolio', async () => {
    api.readNetWorth.mockReturnValue(problem(403));
    api.readNetWorthHistory.mockReturnValue(problem(403));

    renderDashboard();

    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByText('Patrimoine inaccessible')).toBeTruthy();
    expect(screen.queryByRole('heading', { name: 'Patrimoine net' })).toBeNull();
  });

  it('offers a retry when the read fails', async () => {
    api.readNetWorth.mockReturnValue(problem(500));
    api.readNetWorthHistory.mockReturnValue(problem(500));

    renderDashboard();

    const retry = await screen.findByRole('button', { name: 'Réessayer' });
    api.readNetWorth.mockReturnValue(success(netWorth));
    api.readNetWorthHistory.mockReturnValue(success(history));
    fireEvent.click(retry);

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Patrimoine net' })).toBeTruthy();
    });
  });
});
