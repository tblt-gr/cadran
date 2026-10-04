import type { PortfolioPerformance } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { performanceFixture } from '@/features/portfolio/performance/performanceFixtures';
import { PerformanceCard } from './PerformanceCard';

const api = vi.hoisted(() => ({ readPortfolioPerformance: vi.fn() }));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function ok(data: PortfolioPerformance) {
  return Promise.resolve({ data, response: new Response('{}', { status: 200 }) });
}

function renderCard(frozen = false, onSource = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <PerformanceCard
        accountId="account-1"
        asOf="2026-03-10"
        frozen={frozen}
        onSource={onSource}
      />
    </QueryClientProvider>,
  );
  return { onSource };
}

describe('PerformanceCard', () => {
  it('shows the real titles and disabled controls while loading', () => {
    api.readPortfolioPerformance.mockImplementation(() => new Promise(() => undefined));
    renderCard();
    expect(screen.getByRole('heading', { name: "Performance de l'année" })).toBeTruthy();
    for (const label of [
      'Gain total',
      'Rendement (Modified Dietz)',
      'TWR exact',
      'Rendement personnel (MWR)',
    ]) {
      expect(screen.getByRole('heading', { name: label })).toBeTruthy();
      expect(
        screen.getByRole('button', { name: `Expliquer : ${label}` }).matches(':disabled'),
      ).toBe(true);
    }
    expect(screen.getAllByRole('status').length).toBe(1);
  });

  it('asks for the year of the Au date', async () => {
    api.readPortfolioPerformance.mockImplementation(() => ok(performanceFixture()));
    renderCard();
    await waitFor(() => expect(screen.getByText('Du 01/01/2026 au 10/03/2026')).toBeTruthy());
    expect(api.readPortfolioPerformance).toHaveBeenCalledWith(
      expect.objectContaining({
        path: { accountId: 'account-1' },
        query: { scope: 'YEAR', period: '2026' },
      }),
    );
  });

  it('shows the four figures with their badges', async () => {
    api.readPortfolioPerformance.mockImplementation(() => ok(performanceFixture()));
    renderCard();
    await waitFor(() => expect(screen.getByText('+250,00 €')).toBeTruthy());
    expect(screen.getByText('+2,35 %')).toBeTruthy();
    expect(screen.getByText('+2,38 %')).toBeTruthy();
    expect(screen.getByText('Approximation')).toBeTruthy();
    expect(screen.getAllByText('Exact')).toHaveLength(3);
  });

  it('reads Depuis le 1er janvier while the period is in progress', async () => {
    const fixture = performanceFixture();
    fixture.gain.reasons = [{ code: 'PERIOD_IN_PROGRESS', dates: ['2026-03-10'] }];
    api.readPortfolioPerformance.mockImplementation(() => ok(fixture));
    renderCard();
    await waitFor(() => expect(screen.getByText('Depuis le 1er janvier')).toBeTruthy());
  });

  it('retries after an API error', async () => {
    api.readPortfolioPerformance.mockImplementationOnce(() =>
      Promise.resolve({ error: {}, response: new Response('{}', { status: 500 }) }),
    );
    api.readPortfolioPerformance.mockImplementation(() => ok(performanceFixture()));
    renderCard();
    const retry = await screen.findByRole('button', { name: 'Réessayer' });
    expect(screen.getByRole('alert')).toBeTruthy();
    fireEvent.click(retry);
    await waitFor(() => expect(screen.getByText('+250,00 €')).toBeTruthy());
  });

  it('shows every figure as a dash for an account without valuation', async () => {
    const fixture = performanceFixture();
    fixture.start = null;
    fixture.end = null;
    for (const figure of [
      fixture.gain,
      fixture.modifiedDietz,
      fixture.timeWeighted,
      fixture.moneyWeighted,
    ]) {
      figure.value = null;
      figure.quality = 'NOT_CALCULABLE';
      figure.reasons = [{ code: 'NO_END_VALUATION', dates: [] }];
    }
    api.readPortfolioPerformance.mockImplementation(() => ok(fixture));
    renderCard();
    await waitFor(() => expect(screen.getAllByText('Aucune valorisation')).toHaveLength(4));
    expect(screen.getAllByText('-', { selector: '[aria-hidden="true"]' })).toHaveLength(4);
  });

  it('opens the explanation of a figure by keyboard order and forwards a source', async () => {
    api.readPortfolioPerformance.mockImplementation(() => ok(performanceFixture()));
    const { onSource } = renderCard();
    await waitFor(() => expect(screen.getByText('+250,00 €')).toBeTruthy());
    const buttons = screen
      .getAllByRole('button')
      .map((button) => button.getAttribute('aria-label'));
    expect(buttons).toEqual([
      'Expliquer : Gain total',
      'Expliquer : Rendement (Modified Dietz)',
      'Expliquer : TWR exact',
      'Expliquer : Rendement personnel (MWR)',
    ]);
    fireEvent.click(screen.getByRole('button', { name: 'Expliquer : TWR exact' }));
    expect(screen.getByRole('dialog', { name: 'Expliquer : TWR exact' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Valorisation du 31/12/2025' }));
    expect(onSource).toHaveBeenCalledWith({
      kind: 'VALUATION',
      id: 'valuation-1',
      date: '2025-12-31',
    });
  });

  it('lets the two-by-two grid shrink to 360 px', () => {
    const css = readFileSync(join(__dirname, 'PerformanceCard.module.css'), 'utf8');
    expect(css).toMatch(/repeat\(2, minmax\(0, 1fr\)\)/);
    expect(css).toMatch(/@media \(min-width: 768px\)[\s\S]*repeat\(4, minmax\(0, 1fr\)\)/);
  });
});
