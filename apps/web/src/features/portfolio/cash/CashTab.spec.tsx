import { CashMovementsTable } from './cash-movements-table/CashMovementsTable';
import { CashSummary } from './cash-summary/CashSummary';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { cashFixture } from './cashFixtures';
import { CashTab } from './CashTab';

const api = vi.hoisted(() => ({ getSession: vi.fn(), readPortfolioCash: vi.fn() }));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const accountId = '00000000-0000-7000-8000-0000000000ac';
const ok = <T,>(data: T) =>
  Promise.resolve({ data, response: new Response('{}', { status: 200 }) });
const refused = (status: number, body: object) =>
  Promise.resolve({ error: body, response: new Response('{}', { status }) });

function renderTab() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const session = {
    provisioned: true,
    authenticated: true,
    setupRequired: false,
    user: { id: 'u', email: 'o@example.test', displayName: 'Owner' },
    workspace: { id: 'w', role: 'OWNER', timeZone: 'Europe/Paris', baseCurrency: 'EUR' },
  };
  client.setQueryData(['session'], session);
  api.getSession.mockImplementation(() => ok(session));
  return render(
    <QueryClientProvider client={client}>
      <CashTab accountId={accountId} />
    </QueryClientProvider>,
  );
}

/** The checks sit behind the pill beside the balance. */
async function openChecks(name: string | RegExp = /^À vérifier/) {
  fireEvent.click(await screen.findByRole('button', { name }));
}

async function loaded() {
  await waitFor(() => expect(screen.queryByTestId('cash-skeleton')).toBeNull());
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-03-10T10:00:00Z') });
  api.readPortfolioCash.mockImplementation(() => ok(cashFixture()));
});
afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.clearAllMocks();
});

describe('CashTab', () => {
  it.each([null, cashFixture()])(
    'makes the cash movement scroller reachable while pending and loaded %#',
    (cash) => {
      render(<CashMovementsTable cash={cash} />);
      const region = screen.getByRole('region', { name: 'Mouvements d’espèces' });
      expect(region.tabIndex).toBe(0);
      expect(within(region).getByRole('table', { name: 'Mouvements d’espèces' })).toBeTruthy();
    },
  );

  it('shows the skeleton chrome then the balance, period totals and running movements', async () => {
    renderTab();
    expect(screen.getByRole('button', { name: 'Cette année' })).toBeTruthy();
    expect(screen.getByTestId('cash-skeleton')).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Solde espèces' })).toBeTruthy();

    await loaded();
    expect(screen.queryByTestId('cash-skeleton')).toBeNull();
    expect(api.readPortfolioCash).toHaveBeenCalledWith(
      expect.objectContaining({
        path: { accountId },
        query: { from: '2026-01-01', to: '2026-03-10' },
      }),
    );
    const summary = screen.getByRole('region', { name: 'Solde espèces' });
    expect(summary.textContent).toMatch(/1\s193,28.*1\s193,28/);
    const term = (name: string) => within(summary).getByText(name).nextElementSibling?.textContent;
    expect(term('Frais')).toMatch(/8,98/);
    expect(term('Taxes')).toMatch(/4,95/);
    expect(term('Retraits')).toMatch(/500,00/);

    const table = screen.getByRole('table', { name: 'Mouvements d’espèces' });
    const rows = within(table).getAllByRole('row');
    expect(rows).toHaveLength(8);
    expect(rows[2]?.textContent).toMatch(/Achat.*2\s307,68.*692,32/);
    expect(rows[2]?.textContent).toContain('−');
    expect(rows[3]?.textContent).toContain('+');
  });

  it('shows no checks pill while loading nor on a clean ledger', async () => {
    renderTab();
    expect(screen.queryByRole('button', { name: /À vérifier/ })).toBeNull();
    await loaded();
    expect(screen.queryByRole('button', { name: /À vérifier/ })).toBeNull();
    expect(screen.queryByText(/Aucune anomalie/)).toBeNull();
  });

  it('lists every warning with its count and links to the sources', async () => {
    api.readPortfolioCash.mockImplementation(() =>
      ok(
        cashFixture({
          warnings: [
            {
              code: 'UNLINKED_TRANSFER_IN',
              count: 2,
              sourceIds: ['tx-1', 'tx-2'],
              firstDate: null,
              amount: null,
            },
            {
              code: 'NEGATIVE_CASH',
              count: 1,
              sourceIds: ['op-2', 'op-9'],
              firstDate: '2026-02-04',
              amount: null,
            },
            {
              code: 'ROUNDING_RESIDUALS',
              count: 1,
              sourceIds: ['op-3'],
              firstDate: null,
              amount: { value: '0.004', assetCode: 'EUR' },
            },
          ],
        }),
      ),
    );
    renderTab();

    await openChecks();
    expect(screen.getByRole('dialog', { name: 'Contrôle des espèces' })).toBeTruthy();
    expect(screen.getByText('2 virements entrants ne sont liés à aucun versement')).toBeTruthy();
    expect(screen.getByText(/Solde négatif à partir du 04\/02\/2026/)).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Achat du 06/01/2026' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Opération 2' })).toBeTruthy();
    const links = screen.getAllByRole('link').map((link) => link.getAttribute('href'));
    expect(links).toContain(`/portfolios/${accountId}?tab=operations&operation=op-9`);
    expect(links).toContain(`/portfolios/${accountId}?tab=operations&operation=op-2`);
    expect(links).toContain(`/portfolios/${accountId}?tab=account&month=2026-03`);
  });

  it('shows the empty period', async () => {
    api.readPortfolioCash.mockImplementation(() => ok(cashFixture({ movements: [] })));
    renderTab();
    expect(await screen.findByText('Aucun mouvement d’espèces sur la période')).toBeTruthy();
  });

  it('applies the 12 last months shortcut and clamps a future end date', async () => {
    renderTab();
    await loaded();
    fireEvent.click(screen.getByRole('button', { name: '12 derniers mois' }));
    await waitFor(() =>
      expect(api.readPortfolioCash).toHaveBeenLastCalledWith(
        expect.objectContaining({ query: { from: '2025-03-11', to: '2026-03-10' } }),
      ),
    );
    fireEvent.change(screen.getByLabelText('Au'), { target: { value: '2026-12-31' } });
    expect((screen.getByLabelText('Au') as HTMLInputElement).value).toBe('2026-03-10');
  });

  it('explains a refused range and a failed read with a retry', async () => {
    api.readPortfolioCash.mockImplementationOnce(() =>
      refused(422, { code: 'cash_range_too_large', type: '/problems/x', title: 't', status: 422 }),
    );
    renderTab();
    expect(await screen.findByText(/La période ne peut pas dépasser 366 jours/)).toBeTruthy();

    api.readPortfolioCash.mockImplementationOnce(() => refused(500, {}));
    fireEvent.click(screen.getByRole('button', { name: '12 derniers mois' }));
    expect(await screen.findByText('Les espèces sont indisponibles')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    await loaded();
    expect(screen.getByRole('table', { name: 'Mouvements d’espèces' })).toBeTruthy();
  });

  it('lists only the flows that moved between the opening and closing balances', async () => {
    renderTab();
    await loaded();
    const terms = screen.getAllByRole('term').map((term) => term.textContent);
    expect(terms[0]).toBe('Solde d’ouverture');
    expect(terms.at(-1)).toBe('Solde de clôture');
    expect(terms).toContain('Dividendes (brut)');
    expect(terms).not.toContain('Intérêts (brut)');
    expect(terms).not.toContain('Soulte reçue');
    expect(screen.queryByText(/multi-devises/)).toBeNull();
  });

  it('drops information rows entirely when no warning-level item exists', async () => {
    api.readPortfolioCash.mockImplementation(() =>
      ok(
        cashFixture({
          warnings: [
            {
              code: 'UNLINKED_EXTERNAL_FLOW',
              count: 2,
              sourceIds: ['op-1', 'op-7'],
              firstDate: null,
              amount: null,
            },
          ],
        }),
      ),
    );
    renderTab();
    await loaded();

    expect(screen.queryByRole('button', { name: /À vérifier/ })).toBeNull();
    expect(screen.queryByText(/Informations \(/)).toBeNull();
    expect(screen.queryByText(/sans virement lié/)).toBeNull();
  });

  it('keeps information rows in a collapsed disclosure next to a warning', async () => {
    api.readPortfolioCash.mockImplementation(() =>
      ok(
        cashFixture({
          warnings: [
            {
              code: 'NEGATIVE_CASH',
              count: 1,
              sourceIds: ['op-9'],
              firstDate: '2026-02-04',
              amount: null,
            },
            {
              code: 'UNLINKED_EXTERNAL_FLOW',
              count: 2,
              sourceIds: ['op-1', 'op-7'],
              firstDate: null,
              amount: null,
            },
            {
              code: 'ROUNDING_RESIDUALS',
              count: 1,
              sourceIds: ['op-3'],
              firstDate: null,
              amount: { value: '0.02', assetCode: 'EUR' },
            },
          ],
        }),
      ),
    );
    renderTab();

    await openChecks('À vérifier (1)');
    const summary = screen.getByText('Informations (2)');
    const details = summary.closest('details') as HTMLDetailsElement;
    expect(details.open).toBe(false);
    fireEvent.click(summary);
    expect(details.open).toBe(true);
    expect(within(details).getByText(/résidu d’arrondi : total 0,02\s€/)).toBeTruthy();
    expect(within(details).getByText(/sans virement lié/)).toBeTruthy();
  });

  it('lists warning-level rows outside the disclosure', async () => {
    api.readPortfolioCash.mockImplementation(() =>
      ok(
        cashFixture({
          warnings: [
            {
              code: 'UNLINKED_TRANSFER_IN',
              count: 1,
              sourceIds: ['tx-1'],
              firstDate: null,
              amount: null,
            },
          ],
        }),
      ),
    );
    renderTab();

    await openChecks('À vérifier (1)');
    const row = screen.getByText('1 virement entrant n’est lié à aucun versement');
    expect(row.closest('details')).toBeNull();
    expect(screen.queryByText(/Informations \(/)).toBeNull();
  });

  it.each(['0.004', '-0.004', '0', '-0.00'])(
    'hides a rounding residual of %s that is zero at display precision, and its disclosure',
    async (amount) => {
      api.readPortfolioCash.mockImplementation(() =>
        ok(
          cashFixture({
            warnings: [
              {
                code: 'ROUNDING_RESIDUALS',
                count: 1,
                sourceIds: ['op-2'],
                firstDate: null,
                amount: { value: amount, assetCode: 'EUR' },
              },
            ],
          }),
        ),
      );
      renderTab();

      await loaded();
      expect(screen.queryByText(/résidu d’arrondi/)).toBeNull();
      expect(screen.queryByText(/Informations \(/)).toBeNull();
    },
  );
});

it('explains support purchases and sales in the cash identity', async () => {
  render(<CashSummary cash={null} />);
  expect(screen.getByText(/supports achetés \+ supports vendus/)).toBeTruthy();
  expect(screen.getAllByRole('term').map((term) => term.textContent)).toEqual([
    'Solde d’ouverture',
    'Solde de clôture',
  ]);
});
