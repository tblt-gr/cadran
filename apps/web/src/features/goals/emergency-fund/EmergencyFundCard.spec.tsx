import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { EmergencyFundCard } from './EmergencyFundCard';
import { coverageNoAccount, coverageShortfall, defaultPolicy } from './fixtures';

const api = vi.hoisted(() => ({
  readEmergencyFundCoverage: vi.fn(),
  readEmergencyFundPolicy: vi.fn(),
  updateEmergencyFundPolicy: vi.fn(),
  listCategories: vi.fn(),
  getSession: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const ok = <T,>(data: T) =>
  Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });

function renderCard() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        <EmergencyFundCard />
      </QueryClientProvider>,
    ),
    queryClient,
  };
}

describe('EmergencyFundCard', () => {
  beforeEach(() => {
    api.readEmergencyFundPolicy.mockImplementation(() => ok(defaultPolicy));
    api.getSession.mockImplementation(() => ok({ workspace: { baseCurrency: 'EUR' } }));
  });
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('replaces its one named cold-load skeleton and keeps coverage during a refetch', async () => {
    let resolveCoverage: ((value: unknown) => void) | undefined;
    api.readEmergencyFundCoverage.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveCoverage = resolve;
        }),
    );
    const { queryClient } = renderCard();

    expect(screen.getByTestId('emergency-fund-skeleton')).toBeTruthy();
    expect(
      screen.getByTestId('emergency-fund-skeleton').querySelectorAll('[role="status"]'),
    ).toHaveLength(1);
    resolveCoverage?.(await ok(coverageShortfall));
    expect(await screen.findByText('4,9 mois')).toBeTruthy();
    expect(screen.queryByTestId('emergency-fund-skeleton')).toBeNull();

    api.readEmergencyFundCoverage.mockImplementation(() => new Promise(() => undefined));
    void queryClient.invalidateQueries({ queryKey: ['emergency-fund'] });
    await waitFor(() => expect(api.readEmergencyFundCoverage).toHaveBeenCalledTimes(2));
    expect(screen.getByText('4,9 mois')).toBeTruthy();
    expect(screen.queryByTestId('emergency-fund-skeleton')).toBeNull();
  });

  it('shows the covered months, target, required amount and the shortfall in words', async () => {
    api.readEmergencyFundCoverage.mockImplementation(() => ok(coverageShortfall));
    renderCard();
    expect(await screen.findByText('4,9 mois')).toBeTruthy();
    expect(screen.getByText(/pour 6 mois visés/i)).toBeTruthy();
    expect(screen.getByText(/10\s260,00\s€/)).toBeTruthy();
    expect(screen.getByText(/12\s600\s€/)).toBeTruthy();
    expect(screen.getByText(/Il manque 2\s340,00\s€/)).toBeTruthy();
    const meter = screen.getByRole('meter');
    expect(meter.getAttribute('aria-valuemax')).toBe('6');
    expect(meter.getAttribute('aria-valuenow')).toBe('4.9');
  });

  it('flags a stale valuation while still showing the figure', async () => {
    api.readEmergencyFundCoverage.mockImplementation(() => ok(coverageShortfall));
    renderCard();
    expect(await screen.findByText('Ancienne valorisation')).toBeTruthy();
  });

  it('shows a surplus with the word and not the sign alone', async () => {
    api.readEmergencyFundCoverage.mockImplementation(() =>
      ok({ ...coverageShortfall, gap: '500.00', reason: null, quality: 'COMPLETE' }),
    );
    renderCard();
    expect(await screen.findByText(/500,00\s€ d’excédent/)).toBeTruthy();
  });

  it('never shows a shortfall for an exactly reached target', async () => {
    api.readEmergencyFundCoverage.mockImplementation(() =>
      ok({ ...coverageShortfall, gap: '0.00', reason: null, quality: 'COMPLETE' }),
    );
    renderCard();
    expect(await screen.findByText('Objectif atteint')).toBeTruthy();
  });

  it('explains a non-calculable ratio with its reason instead of zero', async () => {
    api.readEmergencyFundCoverage.mockImplementation(() =>
      ok({
        ...coverageShortfall,
        coveredMonths: null,
        coveredMonthsDisplay: null,
        reason: 'ZERO_ESSENTIAL_EXPENSES',
        requiredAmount: '0.00',
        gap: '10260.00',
        quality: 'NOT_CALCULABLE',
      }),
    );
    renderCard();
    expect(await screen.findByText(/aucune dépense essentielle/i)).toBeTruthy();
    expect(screen.queryByText('0 mois')).toBeNull();
    expect(screen.queryByRole('meter')).toBeNull();
  });

  it('names the account missing a valuation', async () => {
    api.readEmergencyFundCoverage.mockImplementation(() =>
      ok({
        ...coverageShortfall,
        liquidAssets: {
          value: null,
          assetCode: null,
          reason: 'MISSING_VALUATION',
          accounts: [
            {
              id: 'a',
              label: 'Livret A',
              assetCode: 'EUR',
              value: null,
              valuedOn: null,
              stale: false,
            },
            {
              id: 'b',
              label: 'LDDS',
              assetCode: 'EUR',
              value: '10.00',
              valuedOn: '2026-09-01',
              stale: false,
            },
          ],
        },
        gap: null,
        coveredMonths: null,
        coveredMonthsDisplay: null,
        reason: 'MISSING_VALUATION',
        quality: 'NOT_CALCULABLE',
      }),
    );
    renderCard();
    expect(await screen.findByText(/Livret A/)).toBeTruthy();
    expect(screen.getByText(/sans valorisation/i)).toBeTruthy();
  });

  it('shows no gap and no invented currency when the assets differ', async () => {
    api.readEmergencyFundCoverage.mockImplementation(() =>
      ok({
        ...coverageShortfall,
        liquidAssets: {
          ...coverageShortfall.liquidAssets,
          value: null,
          assetCode: null,
          reason: 'MIXED_ASSETS',
        },
        reference: { ...coverageShortfall.reference, assetCode: null },
        gap: null,
        coveredMonths: null,
        coveredMonthsDisplay: null,
        reason: 'MIXED_ASSETS',
        quality: 'NOT_CALCULABLE',
      }),
    );
    renderCard();
    expect(await screen.findByText(/actifs différents/)).toBeTruthy();
    expect(screen.queryByText(/€|\$/)).toBeNull();
  });

  it('formats the required amount and gap in the essentials asset, not the liquid asset', async () => {
    api.readEmergencyFundCoverage.mockImplementation(() =>
      ok({
        ...coverageShortfall,
        liquidAssets: { ...coverageShortfall.liquidAssets, assetCode: 'USD' },
        reference: { ...coverageShortfall.reference, assetCode: 'EUR' },
      }),
    );
    renderCard();
    await screen.findByText('4,9 mois');
    expect(screen.getByText(/12\s600\s€/)).toBeTruthy();
    expect(screen.getByText(/Il manque 2\s340,00\s€/)).toBeTruthy();
    expect(screen.getByText(/10\s260,00\s\$US/)).toBeTruthy();
  });

  it('explains a workspace too large to compute instead of offering a retry', async () => {
    api.readEmergencyFundCoverage.mockImplementation(() =>
      Promise.resolve({
        error: { code: 'emergency_fund_scope_too_large' },
        response: new Response('{}', { status: 422 }),
      }),
    );
    renderCard();
    expect(await screen.findByText(/trop de comptes ou de catégories/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: /réessayer/i })).toBeNull();
  });

  it('shows the empty state with a link to the accounts', async () => {
    api.readEmergencyFundCoverage.mockImplementation(() => ok(coverageNoAccount));
    renderCard();
    const link = await screen.findByRole('link', { name: /comptes/i });
    expect(link.getAttribute('href')).toBe('/accounts');
    expect(screen.queryByRole('meter')).toBeNull();
  });

  it('shows a loading state, then an error with a retry', async () => {
    api.readEmergencyFundCoverage.mockImplementationOnce(() =>
      Promise.resolve({ error: {}, response: new Response('{}', { status: 500 }) }),
    );
    renderCard();
    expect(screen.getByRole('status')).toBeTruthy();
    expect(await screen.findByRole('alert')).toBeTruthy();
    api.readEmergencyFundCoverage.mockImplementation(() => ok(coverageShortfall));
    fireEvent.click(screen.getByRole('button', { name: /réessayer/i }));
    expect(await screen.findByText('4,9 mois')).toBeTruthy();
  });

  it('opens the explanation and the policy modals from keyboard-reachable buttons', async () => {
    api.readEmergencyFundCoverage.mockImplementation(() => ok(coverageShortfall));
    renderCard();
    await screen.findByText('4,9 mois');
    fireEvent.click(screen.getByRole('button', { name: /voir le calcul/i }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByRole('link', { name: 'Livret A' }).getAttribute('href')).toBe(
      '/accounts/00000000-0000-7000-8000-0000000000a1',
    );
    fireEvent.click(within(dialog).getByRole('button', { name: /fermer/i }));
    fireEvent.click(screen.getByRole('button', { name: /modifier la politique/i }));
    expect(await screen.findByRole('dialog')).toBeTruthy();
  });
});
