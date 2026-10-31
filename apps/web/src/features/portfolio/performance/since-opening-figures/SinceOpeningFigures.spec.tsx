import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { performanceFixture } from '@/features/portfolio/performance/performanceFixtures';
import { SinceOpeningFigures } from './SinceOpeningFigures';

const api = vi.hoisted(() => ({ getSession: vi.fn(), readPortfolioPerformance: vi.fn() }));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const ok = <T,>(data: T) =>
  Promise.resolve({ data, response: new Response('{}', { status: 200 }) });

function renderFigures(frozen = false, omit: ('gain' | 'moneyWeighted')[] = []) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <SinceOpeningFigures accountId="account-1" asOf="2026-03-10" frozen={frozen} omit={omit} />
    </QueryClientProvider>,
  );
}

const title = 'Depuis le premier relevé';
const region = () => screen.getByRole('region', { name: title });
const labels = () =>
  within(region())
    .queryAllByRole('heading', { level: 3 })
    .map((heading) => heading.textContent)
    .filter((text) => text !== title);

const refused = (status: number, body: object) =>
  Promise.resolve({ error: body, response: new Response('{}', { status }) });

async function loaded() {
  await waitFor(() =>
    expect(
      within(region())
        .getByRole('button', { name: 'Expliquer : Gain total' })
        .hasAttribute('disabled'),
    ).toBe(false),
  );
  return region();
}

beforeEach(() => {
  window.localStorage.clear();
  window.history.replaceState({}, '', '/portfolios/account-1?tab=performance&year=2025');
  api.readPortfolioPerformance.mockImplementation(() => ok(performanceFixture()));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('SinceOpeningFigures', () => {
  it('reads the INCEPTION scope, shows the gain and personal return by default', async () => {
    renderFigures();
    const section = await loaded();
    expect(api.readPortfolioPerformance).toHaveBeenCalledWith(
      expect.objectContaining({ query: { scope: 'INCEPTION' } }),
    );
    expect(labels()).toEqual(['Gain total', 'Rendement personnel (MWR)']);
    expect(within(section).queryByText(/Du /)).toBeNull();
    expect(within(section).getByText('Réalisé et latent, revenus et frais compris')).toBeTruthy();
    fireEvent.click(within(section).getByRole('button', { name: 'Expliquer : Gain total' }));
    expect(await screen.findByRole('dialog', { name: 'Gain total' })).toBeTruthy();
  });

  it('lets the reader choose the figures and remembers the choice', async () => {
    renderFigures();
    await loaded();
    fireEvent.click(
      screen.getByRole('button', { name: 'Choisir les indicateurs depuis le premier relevé' }),
    );
    const dialog = screen.getByRole('dialog', { name: 'Indicateurs depuis le premier relevé' });
    fireEvent.click(within(dialog).getByRole('checkbox', { name: /Rendement des placements/ }));
    fireEvent.click(within(dialog).getByRole('checkbox', { name: /Gain total/ }));
    expect(labels()).toEqual(['Rendement des placements (TWR) 2026', 'Rendement personnel (MWR)']);
    cleanup();
    renderFigures();
    await waitFor(() =>
      expect(labels()).toEqual([
        'Rendement des placements (TWR) 2026',
        'Rendement personnel (MWR)',
      ]),
    );
  });

  it('shows the performance-tab year TWR when since-opening TWR is not calculable', async () => {
    api.readPortfolioPerformance.mockImplementation((request: { query: { scope: string } }) => {
      const fixture = performanceFixture();
      if (request.query.scope === 'INCEPTION') {
        fixture.timeWeighted = {
          ...fixture.timeWeighted,
          value: null,
          quality: 'NOT_CALCULABLE',
          reasons: [{ code: 'MISSING_FLOW_VALUATION', dates: ['2023-11-09'] }],
        };
      }
      return ok(fixture);
    });
    renderFigures();
    await loaded();
    fireEvent.click(
      screen.getByRole('button', { name: 'Choisir les indicateurs depuis le premier relevé' }),
    );
    fireEvent.click(screen.getByRole('checkbox', { name: /Rendement des placements/ }));
    expect(
      await screen.findByRole('heading', { name: 'Rendement des placements (TWR) 2026' }),
    ).toBeTruthy();
    expect(await screen.findByText(/2,38/)).toBeTruthy();
    expect(screen.queryByText('Non calculable')).toBeNull();
    expect(api.readPortfolioPerformance).toHaveBeenCalledWith(
      expect.objectContaining({ query: { scope: 'YEAR', period: '2026' } }),
    );
  });

  it('never offers a figure the page already shows', async () => {
    renderFigures(false, ['gain']);
    await waitFor(() => expect(labels()).toEqual(['Rendement personnel (MWR)']));
    fireEvent.click(
      screen.getByRole('button', { name: 'Choisir les indicateurs depuis le premier relevé' }),
    );
    expect(screen.queryByRole('checkbox', { name: /Gain total/ })).toBeNull();
  });

  it('keeps the title and sends no request while frozen', () => {
    renderFigures(true);
    expect(screen.getByRole('heading', { name: title })).toBeTruthy();
    expect(api.readPortfolioPerformance).not.toHaveBeenCalled();
  });

  it('keeps the labels and value blocks while the INCEPTION query is pending', () => {
    api.readPortfolioPerformance.mockImplementation(() => new Promise(() => undefined));
    renderFigures();
    expect(labels()).toEqual(['Gain total', 'Rendement personnel (MWR)']);
    expect(within(region()).queryByRole('alert')).toBeNull();
    expect(
      within(region())
        .getByRole('button', { name: 'Expliquer : Gain total' })
        .hasAttribute('disabled'),
    ).toBe(true);
  });

  it('shows an error with Réessayer when INCEPTION fails', async () => {
    api.readPortfolioPerformance.mockImplementationOnce(() => refused(500, {}));
    renderFigures();
    const alert = await within(region()).findByRole('alert');
    expect(alert.textContent).toContain('La performance depuis le premier relevé est indisponible');
    expect(labels()).toEqual([]);
    fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));
    await loaded();
  });

  it('keeps a successful null figure as non-calculable with its reason, not an error', async () => {
    const fixture = performanceFixture();
    fixture.gain = {
      ...fixture.gain,
      value: null,
      quality: 'NOT_CALCULABLE',
      reasons: [{ code: 'NO_END_VALUATION', dates: [] }],
    };
    api.readPortfolioPerformance.mockImplementation(() => ok(fixture));
    renderFigures();
    const section = await loaded();
    expect(within(section).queryByRole('alert')).toBeNull();
    expect(within(section).getByText('Non calculable')).toBeTruthy();
    expect(within(section).queryByText('Réalisé et latent, revenus et frais compris')).toBeNull();
  });

  it('opens a source in the operation or valuation detail and keeps year', async () => {
    renderFigures();
    await loaded();
    fireEvent.click(screen.getByRole('button', { name: 'Expliquer : Gain total' }));
    expect(await screen.findByRole('dialog', { name: 'Gain total' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Valorisation du 31/12/2025' }));
    expect(screen.queryByRole('dialog', { name: 'Gain total' })).toBeNull();
    const params = new URLSearchParams(window.location.search);
    expect(params.get('asOf')).toBe('2026-03-10');
    expect(params.get('tab')).toBe('valuations');
    expect(params.get('valuation')).toBe('valuation-1');
    expect(params.get('year')).toBe('2025');
  });
});
