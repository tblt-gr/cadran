import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { compositionAccount, eur, workedComposition } from './compositionFixtures';
import { NetWorthCompositionCard } from './NetWorthCompositionCard';

const api = vi.hoisted(() => ({ readAccountOverviewComposition: vi.fn() }));
const box = vi.hoisted(() => ({ size: { height: 0, width: 0 } }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));
vi.mock('@/hooks/use-box-size', () => ({
  useBoxSize: () => ({ ref: { current: null }, size: box.size }),
}));

function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function failure(status: number) {
  return Promise.resolve({
    data: undefined,
    error: { type: '/problems/x', title: 'x', status },
    response: new Response('{}', { status }),
  });
}

function renderCard() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <NetWorthCompositionCard asOf="2026-10-25" />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  box.size = { height: 0, width: 0 };
});

describe('NetWorthCompositionCard', () => {
  it('keeps its title and table headers while loading and blocks only values', () => {
    api.readAccountOverviewComposition.mockReturnValue(new Promise(() => undefined));
    renderCard();

    expect(screen.getByRole('heading', { level: 2, name: 'Répartition par compte' })).toBeTruthy();
    const table = screen.getByRole('table', { name: 'Répartition par compte' });
    expect(within(table).getByRole('columnheader', { name: 'Compte' })).toBeTruthy();
    expect(within(table).getByRole('columnheader', { name: 'Montant' })).toBeTruthy();
    expect(within(table).getByRole('columnheader', { name: 'Part' })).toBeTruthy();
    expect(within(table).getAllByRole('row', { hidden: true })).toHaveLength(1 + 3 + 1);
    expect(document.querySelectorAll('[data-skeleton]').length).toBeGreaterThan(0);
    expect(screen.queryByText('Livret A')).toBeNull();
  });

  it('lists one row per account in response order with the server shares and total', async () => {
    api.readAccountOverviewComposition.mockReturnValue(success(workedComposition()));
    renderCard();

    await screen.findByText('PEA Bourse');
    expect(api.readAccountOverviewComposition).toHaveBeenCalledWith(
      expect.objectContaining({ query: { asOf: '2026-10-25' } }),
    );
    const rows = within(screen.getByRole('table', { name: 'Répartition par compte' })).getAllByRole(
      'row',
    );
    expect(rows).toHaveLength(1 + 2 + 1);
    expect(within(rows[1]!).getByText('PEA Bourse')).toBeTruthy();
    expect(within(rows[1]!).getByText('Boursorama')).toBeTruthy();
    expect(within(rows[1]!).getByText(/6\s?000,00/)).toBeTruthy();
    expect(within(rows[1]!).getByText(/60,00\s?%/)).toBeTruthy();
    expect(within(rows[2]!).getByText('Livret A')).toBeTruthy();
    expect(within(rows[2]!).getByText(/40,00\s?%/)).toBeTruthy();
    expect(within(rows[3]!).getByText('Total')).toBeTruthy();
    expect(rows[3]!.textContent).toMatch(/10\s?000,00/);
  });

  it('draws the donut when the chart is allowed and the box is measured', async () => {
    box.size = { height: 288, width: 288 };
    api.readAccountOverviewComposition.mockReturnValue(success(workedComposition()));
    renderCard();

    await screen.findByText('PEA Bourse');
    expect(
      await screen.findByRole('img', { name: 'Répartition du patrimoine par compte' }),
    ).toBeTruthy();
  });

  it.each([
    ['MISSING_VALUATION', "Le graphique n'est pas affiché : une valorisation manque."],
    ['MIXED_ASSETS', "Le graphique n'est pas affiché : les comptes mélangent plusieurs unités."],
    ['ZERO_TOTAL', "Le graphique n'est pas affiché : le patrimoine est nul."],
    ['NEGATIVE_TOTAL', "Le graphique n'est pas affiché : le patrimoine est négatif."],
    [
      'NEGATIVE_COMPONENT',
      "Le graphique n'est pas affiché : un compte négatif fausserait la lecture des parts.",
    ],
  ] as const)(
    'replaces the donut by its reason for %s and keeps the table',
    async (reason, text) => {
      box.size = { height: 288, width: 288 };
      api.readAccountOverviewComposition.mockReturnValue(
        success(
          workedComposition({
            chartReason: reason,
            accounts: [
              compositionAccount({ id: 'a1', label: 'Compte courant', value: eur('-50.00') }),
            ],
          }),
        ),
      );
      renderCard();

      expect(await screen.findByText(text)).toBeTruthy();
      expect(
        screen.queryByRole('img', { name: 'Répartition du patrimoine par compte' }),
      ).toBeNull();
      expect(screen.getByText('Compte courant')).toBeTruthy();
    },
  );

  it('shows an error with a retry that reads again', async () => {
    api.readAccountOverviewComposition.mockReturnValueOnce(failure(500));
    renderCard();

    expect(await screen.findByText('Répartition indisponible.')).toBeTruthy();
    api.readAccountOverviewComposition.mockReturnValue(success(workedComposition()));
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));

    expect(await screen.findByText('PEA Bourse')).toBeTruthy();
    expect(api.readAccountOverviewComposition).toHaveBeenCalledTimes(2);
  });

  it('is hidden when no account is eligible', async () => {
    api.readAccountOverviewComposition.mockReturnValue(
      success(workedComposition({ accounts: [], total: null, totalReason: 'NO_ELIGIBLE_ACCOUNT' })),
    );
    renderCard();

    await vi.waitFor(() => expect(api.readAccountOverviewComposition).toHaveBeenCalled());
    await vi.waitFor(() => expect(screen.queryByRole('heading')).toBeNull());
  });
});
