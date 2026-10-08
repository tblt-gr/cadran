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

function renderFigures(frozen = false) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <SinceOpeningFigures accountId="account-1" asOf="2026-03-10" frozen={frozen} />
    </QueryClientProvider>,
  );
}

const refused = (status: number, body: object) =>
  Promise.resolve({ error: body, response: new Response('{}', { status }) });

async function loaded() {
  const section = screen.getByRole('region', { name: "Depuis l'ouverture" });
  await waitFor(() =>
    expect(
      within(section)
        .getByRole('button', { name: /Gain total/ })
        .hasAttribute('disabled'),
    ).toBe(false),
  );
  return section;
}

beforeEach(() => {
  window.history.replaceState({}, '', '/portfolios/account-1?tab=performance&year=2025');
  api.readPortfolioPerformance.mockImplementation(() => ok(performanceFixture()));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('SinceOpeningFigures', () => {
  it('reads the INCEPTION scope and opens the explanation of a figure', async () => {
    renderFigures();
    const section = screen.getByRole('region', { name: "Depuis l'ouverture" });
    await waitFor(() =>
      expect(
        within(section)
          .getByRole('button', { name: /Gain total/ })
          .hasAttribute('disabled'),
      ).toBe(false),
    );
    expect(api.readPortfolioPerformance).toHaveBeenCalledWith(
      expect.objectContaining({ query: { scope: 'INCEPTION' } }),
    );
    fireEvent.click(within(section).getByRole('button', { name: /Gain total/ }));
    expect(await screen.findByRole('dialog', { name: 'Expliquer : Gain total' })).toBeTruthy();
  });

  it('keeps the title and sends no request while frozen', () => {
    renderFigures(true);
    expect(screen.getByRole('heading', { name: "Depuis l'ouverture" })).toBeTruthy();
    expect(api.readPortfolioPerformance).not.toHaveBeenCalled();
  });

  it('keeps the title and value skeletons while the INCEPTION query is pending', () => {
    api.readPortfolioPerformance.mockImplementation(() => new Promise(() => undefined));
    renderFigures();
    const section = screen.getByRole('region', { name: "Depuis l'ouverture" });
    expect(screen.getByRole('heading', { name: "Depuis l'ouverture" })).toBeTruthy();
    expect(within(section).queryByRole('alert')).toBeNull();
    expect(
      within(section).getAllByTestId('performance-value')[0]?.querySelector('[aria-hidden="true"]'),
    ).toBeTruthy();
    expect(
      within(section)
        .getByRole('button', { name: /Gain total/ })
        .hasAttribute('disabled'),
    ).toBe(true);
  });

  it('shows an error with Réessayer when INCEPTION fails, not value skeletons', async () => {
    api.readPortfolioPerformance.mockImplementationOnce(() => refused(500, {}));
    renderFigures();
    const section = screen.getByRole('region', { name: "Depuis l'ouverture" });
    expect(await screen.findByRole('heading', { name: "Depuis l'ouverture" })).toBeTruthy();
    const alert = await within(section).findByRole('alert');
    expect(alert.textContent).toContain('La performance est indisponible');
    expect(within(section).queryAllByTestId('performance-value')).toHaveLength(0);
    fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));
    await waitFor(() =>
      expect(
        within(section)
          .getByRole('button', { name: /Gain total/ })
          .hasAttribute('disabled'),
      ).toBe(false),
    );
  });

  it('keeps a successful null figure as non-calculable, not an error', async () => {
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
    expect(within(section).getByText('Non calculable', { selector: '.sr-only' })).toBeTruthy();
  });

  it('opens a source in the operation or valuation detail and keeps year', async () => {
    renderFigures();
    await loaded();
    fireEvent.click(screen.getByRole('button', { name: /Gain total/ }));
    expect(await screen.findByRole('dialog', { name: 'Expliquer : Gain total' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Valorisation du 31/12/2025' }));
    expect(screen.queryByRole('dialog', { name: 'Expliquer : Gain total' })).toBeNull();
    const params = new URLSearchParams(window.location.search);
    expect(params.get('asOf')).toBe('2026-03-10');
    expect(params.get('tab')).toBe('valuations');
    expect(params.get('valuation')).toBe('valuation-1');
    expect(params.get('year')).toBe('2025');
  });
});
