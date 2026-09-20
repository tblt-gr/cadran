import type { BudgetComparisons } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { BudgetComparisonsPanel } from './BudgetComparisonsPanel';

const api = vi.hoisted(() => ({ readBudgetComparisons: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

const result = (overrides: Partial<BudgetComparisons> = {}): BudgetComparisons => ({
  planId: '00000000-0000-7000-8000-000000000001',
  period: '2026-03',
  assetCode: 'EUR',
  status: 'AVAILABLE',
  reason: null,
  comparisons: [
    {
      targetId: '00000000-0000-7000-8000-000000000002',
      scopeType: 'AXIS',
      scopeId: 'ESSENTIAL',
      actual: '42.50',
      actualReason: null,
      target: '100.00',
      targetReason: null,
      variance: '57.50',
      status: 'WITHIN_TARGET',
      includedTransactionIds: ['00000000-0000-7000-8000-000000000003'],
      pendingCount: 1,
      overlapping: false,
      policy: 'Booked non-voided expense rows only.',
    },
  ],
  ...overrides,
});

function mount() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <BudgetComparisonsPanel planId="00000000-0000-7000-8000-000000000001" />
    </QueryClientProvider>,
  );
}

describe('BudgetComparisonsPanel', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('renders exact server figures, source transaction IDs, policy and pending scope count', async () => {
    api.readBudgetComparisons.mockResolvedValue({ data: result(), response: new Response('{}') });
    mount();

    expect(await screen.findByText(/42,50/)).toBeTruthy();
    expect(screen.getByText(/57,50/)).toBeTruthy();
    expect(screen.getByText('Dans le budget')).toBeTruthy();
    expect(screen.getByText('1 transaction en attente')).toBeTruthy();
    expect(screen.getByText('Booked non-voided expense rows only.')).toBeTruthy();
    expect(screen.getByText('00000000-0000-7000-8000-000000000003')).toBeTruthy();
  });

  it('renders an explicit no-target state rather than a zero', async () => {
    api.readBudgetComparisons.mockResolvedValue({
      data: result({ status: 'NO_TARGETS', reason: 'MISSING_TARGET', comparisons: [] }),
      response: new Response('{}'),
    });
    mount();

    expect(await screen.findByText(/Aucun objectif à comparer/)).toBeTruthy();
    expect(screen.getByRole('status').textContent).toContain('Objectif manquant');
    expect(screen.queryByText('0,00 €')).toBeNull();
  });

  it('renders the server non-calculable reason without a substitute figure', async () => {
    api.readBudgetComparisons.mockResolvedValue({
      data: result({
        comparisons: [
          {
            ...result().comparisons[0],
            actual: null,
            actualReason: 'MIXED_ASSETS',
            target: null,
            targetReason: 'ZERO_CASH_INCOME',
            variance: null,
            status: 'NON_CALCULABLE',
          },
        ],
      }),
      response: new Response('{}'),
    });
    mount();

    expect(await screen.findByText('Non calculable')).toBeTruthy();
    expect(screen.getByText('Actifs mélangés')).toBeTruthy();
    expect(screen.getByText('Revenus de trésorerie nuls')).toBeTruthy();
    expect(screen.queryByText('42,50 €')).toBeNull();
  });
});
