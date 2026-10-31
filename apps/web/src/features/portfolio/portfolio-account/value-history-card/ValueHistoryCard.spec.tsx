import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { ValueHistoryCard } from './ValueHistoryCard';
import { ValueHistoryCardSkeleton } from './ValueHistoryCardSkeleton';
import { historyFixture, historyPointFixture } from './valueHistoryFixtures';

const api = vi.hoisted(() => ({ readPortfolioValuationHistory: vi.fn() }));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const accountId = '00000000-0000-7000-8000-0000000000ac';

function ok<T>(data: T) {
  return Promise.resolve({ data, response: new Response('{}', { status: 200 }) });
}
function renderCard(asOf = '2026-10-03') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ValueHistoryCard accountId={accountId} asOf={asOf} />
    </QueryClientProvider>,
  );
}
async function loaded() {
  await waitFor(() => expect(screen.queryByTestId('value-history-skeleton')).toBeNull());
}

beforeEach(() => {
  window.history.replaceState({}, '', `/portfolios/${accountId}`);
  api.readPortfolioValuationHistory.mockReset();
  api.readPortfolioValuationHistory.mockImplementation(() => ok(historyFixture()));
});
afterEach(cleanup);

describe('ValueHistoryCard', () => {
  it('asks for 24 months ending at Au by default and plots the server strings', async () => {
    renderCard();
    await loaded();
    expect(api.readPortfolioValuationHistory.mock.calls[0][0].query).toEqual({
      from: '2024-10-03',
      to: '2026-10-03',
    });
    expect(screen.getByRole('heading', { name: 'Évolution de la valeur' })).toBeTruthy();
    expect(screen.getByRole('img', { name: /valeur totale/ })).toBeTruthy();
    expect(screen.getByRole('group', { name: 'Valeur totale en EUR' })).toBeTruthy();
    expect(screen.getByRole('group', { name: 'Date de valorisation' })).toBeTruthy();
  });

  it('gives a focus target to the two totals only and keeps the gap out of the curve', async () => {
    renderCard();
    await loaded();
    const hits = screen.getAllByRole('button', { name: /^\d{2}\/\d{2}\/\d{4}, / });
    expect(hits).toHaveLength(2);
    act(() => hits[1].focus());
    const tooltip = screen.getByRole('tooltip');
    expect(tooltip.textContent).toContain('30/09/2026');
    expect(tooltip.textContent).toContain('3');
    expect(tooltip.textContent).toContain('Prix anciens');
    expect(tooltip.textContent).toContain('Positions modifiées depuis');
  });

  it('holds the same figures in a table, newest first, with a reasoned dash for the gap', async () => {
    renderCard();
    await loaded();
    const table = screen.getByRole('table', { name: 'Valorisations du portefeuille par date' });
    const headers = within(table)
      .getAllByRole('columnheader')
      .map((cell) => cell.textContent);
    expect(headers).toEqual(['Date', 'Valeur totale', 'Titres', 'Espèces', 'Saisie', 'Qualité']);
    const rows = within(table).getAllByRole('row').slice(1);
    expect(rows.map((row) => within(row).getAllByRole('rowheader')[0].textContent)).toEqual([
      '30/09/2026',
      '31/08/2026',
      '31/07/2026',
    ]);
    expect(rows[0].textContent).toContain('3\u202f664,701');
    expect(rows[0].textContent).toContain('Par ligne');
    expect(rows[0].textContent).toContain('Prix anciens');
    expect(rows[0].textContent).toContain('Positions modifiées depuis');
    expect(rows[1].textContent).toContain('Valorisation partielle');
    expect(within(rows[1]).getAllByText('-').length).toBeGreaterThan(0);
    expect(rows[1].textContent).not.toContain('Non calculable');
    expect(rows[2].textContent).toContain('3\u202f500,00');
    expect(rows[2].textContent).toContain('Total');
  });

  it('keeps the period in ?history= and refetches with the new bounds', async () => {
    renderCard();
    await loaded();
    expect(screen.getByRole('button', { name: '24 mois' }).getAttribute('aria-pressed')).toBe(
      'true',
    );
    fireEvent.click(screen.getByRole('button', { name: '12 mois' }));
    expect(window.location.search).toContain('history=12');
    await waitFor(() =>
      expect(api.readPortfolioValuationHistory.mock.calls.at(-1)?.[0].query).toEqual({
        from: '2025-10-03',
        to: '2026-10-03',
      }),
    );
    await loaded();
    fireEvent.click(screen.getByRole('button', { name: 'Tout' }));
    expect(window.location.search).toContain('history=all');
    await waitFor(() =>
      expect(api.readPortfolioValuationHistory.mock.calls.at(-1)?.[0].query).toEqual({
        to: '2026-10-03',
      }),
    );
  });

  it('offers Tout, 24, 12 and Depuis janvier in canonical order and resolves YTD from asOf', async () => {
    const { container } = renderCard();
    await loaded();
    const group = screen.getByRole('group', { name: 'Période' });
    expect(Array.from(group.querySelectorAll('button')).map((b) => b.textContent)).toEqual([
      'Tout',
      '24 mois',
      '12 mois',
      'Depuis janvier',
    ]);
    fireEvent.click(screen.getByRole('button', { name: 'Depuis janvier' }));
    expect(window.location.search).toContain('history=ytd');
    await waitFor(() =>
      expect(api.readPortfolioValuationHistory.mock.calls.at(-1)?.[0].query).toEqual({
        from: '2025-12-31',
        to: '2026-10-03',
      }),
    );
    expect(container.querySelectorAll('[aria-pressed="true"]')).toHaveLength(1);
  });

  it('keeps the previous chart and flags the card busy while a new range loads', async () => {
    const { container } = renderCard();
    await loaded();
    expect(container.querySelector('section')?.getAttribute('aria-busy')).toBe('false');
    api.readPortfolioValuationHistory.mockReturnValue(new Promise(() => {}));
    fireEvent.click(screen.getByRole('button', { name: '12 mois' }));
    await waitFor(() =>
      expect(container.querySelector('section')?.getAttribute('aria-busy')).toBe('true'),
    );
    expect(screen.queryByTestId('value-history-skeleton')).toBeNull();
    expect(screen.getByRole('heading', { name: 'Évolution de la valeur' })).toBeTruthy();
  });

  it('starts from a saved ?history=ytd and falls back to 24 months for unknown values', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?history=ytd`);
    renderCard();
    await loaded();
    expect(
      screen.getByRole('button', { name: 'Depuis janvier' }).getAttribute('aria-pressed'),
    ).toBe('true');
    cleanup();
    window.history.replaceState({}, '', `/portfolios/${accountId}?history=bogus`);
    renderCard();
    await loaded();
    expect(screen.getByRole('button', { name: '24 mois' }).getAttribute('aria-pressed')).toBe(
      'true',
    );
  });

  it('starts from the period carried by the URL', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?history=all`);
    renderCard();
    await loaded();
    expect(screen.getByRole('button', { name: 'Tout' }).getAttribute('aria-pressed')).toBe('true');
    expect(api.readPortfolioValuationHistory.mock.calls[0][0].query).toEqual({
      to: '2026-10-03',
    });
  });

  it('says that only the 240 latest valuations are shown when truncated', async () => {
    api.readPortfolioValuationHistory.mockImplementation(() =>
      ok(historyFixture({ truncated: true })),
    );
    renderCard();
    await loaded();
    expect(screen.getByText('Seules les 240 dernières valorisations sont affichées.')).toBeTruthy();
  });

  it('invites to record a valuation when there is none', async () => {
    api.readPortfolioValuationHistory.mockImplementation(() => ok(historyFixture({ points: [] })));
    renderCard();
    await loaded();
    expect(
      screen.getByText(
        'Aucune valorisation. Enregistrez la valeur de vos titres à partir d’un relevé.',
      ),
    ).toBeTruthy();
    const link = screen.getByRole('link', { name: 'Valorisations' });
    expect(link.getAttribute('href')).toBe(
      `/portfolios/${accountId}?tab=valuations&asOf=2026-10-03`,
    );
    expect(screen.queryByRole('table')).toBeNull();
  });

  it('draws a single point and lists it', async () => {
    api.readPortfolioValuationHistory.mockImplementation(() =>
      ok(historyFixture({ points: [historyPointFixture()] })),
    );
    renderCard();
    await loaded();
    expect(screen.getByRole('img', { name: /valeur totale/ })).toBeTruthy();
    expect(within(screen.getByRole('table')).getAllByRole('row')).toHaveLength(2);
  });

  it('shows an error with a retry and no chart', async () => {
    api.readPortfolioValuationHistory.mockImplementationOnce(() =>
      Promise.resolve({ error: {}, response: new Response('{}', { status: 500 }) }),
    );
    renderCard();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('L’évolution de la valeur est indisponible');
    expect(screen.queryByRole('img')).toBeNull();
    fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));
    await screen.findByRole('table');
  });

  it('draws no curve and says so when no valuation has a total', async () => {
    api.readPortfolioValuationHistory.mockImplementation(() =>
      ok(
        historyFixture({
          points: [
            historyPointFixture({
              totalValue: null,
              securitiesValue: null,
              completeness: 'PARTIAL',
            }),
          ],
        }),
      ),
    );
    renderCard();
    await loaded();
    expect(screen.queryByRole('img')).toBeNull();
    expect(screen.getByText('Aucune valorisation complète à tracer.')).toBeTruthy();
    expect(screen.getByRole('table')).toBeTruthy();
  });
});

describe('ValueHistoryCardSkeleton', () => {
  it('keeps the real title, a disabled period control and the table header', () => {
    render(<ValueHistoryCardSkeleton status="Chargement…" />);
    expect(screen.getByRole('heading', { name: 'Évolution de la valeur' })).toBeTruthy();
    for (const name of ['Tout', '24 mois', '12 mois', 'Depuis janvier']) {
      expect((screen.getByRole('button', { name }) as HTMLButtonElement).disabled).toBe(true);
    }
    expect(screen.getByRole('columnheader', { name: 'Valeur totale' })).toBeTruthy();
    expect(screen.getByTestId('value-history-skeleton').getAttribute('aria-busy')).toBe('true');
  });
});
