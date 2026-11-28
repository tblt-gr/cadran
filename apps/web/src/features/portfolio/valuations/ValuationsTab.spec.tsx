import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@/i18n';
import english from '@/locales/en/translation.json';
import {
  draftFixture,
  positionsValuationFixture,
  totalValuationFixture,
  valuationPageFixture,
} from './valuationFixtures';
import { summaryFixture } from '@/features/portfolio/portfolio-account/overviewFixtures';
import { ValuationsTab } from './ValuationsTab';
import { ValuationPositionsTable } from './valuation-detail-modal/ValuationPositionsTable';

const api = vi.hoisted(() => ({
  getSession: vi.fn(),
  listPortfolioValuations: vi.fn(),
  readPortfolioSummary: vi.fn(),
  readPortfolioValuation: vi.fn(),
  readPortfolioValuationDraft: vi.fn(),
  recordPortfolioValuation: vi.fn(),
  withdrawPortfolioValuation: vi.fn(),
}));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const account = {
  id: 'acc-1',
  label: 'Compte-titres',
  assetCode: 'EUR',
  kind: 'PORTFOLIO',
  valuationMode: 'PORTFOLIO',
  status: 'ACTIVE',
  editable: true,
} as Account;
const ok = <T,>(data: T, status = 200) =>
  Promise.resolve({
    data,
    response: new Response('{}', { status, headers: { 'X-List-Revision': 'a'.repeat(64) } }),
  });
const refused = (status: number, body: object) =>
  Promise.resolve({
    error: body,
    response: new Response('{}', { status, headers: { 'X-List-Revision': 'a'.repeat(64) } }),
  });

/** Stands in for the page header, which owns the « Nouvelle valorisation » action. */
function RecordingTab({ account }: { account: Account }) {
  const [recording, setRecording] = useState(false);
  return (
    <>
      <button onClick={() => setRecording(true)} type="button">
        Nouvelle valorisation
      </button>
      <ValuationsTab
        account={account}
        onRecordClose={() => setRecording(false)}
        recording={recording}
      />
    </>
  );
}

function renderTab(overrides: Partial<Account> = {}) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const session = {
    provisioned: true,
    authenticated: true,
    setupRequired: false,
    user: { id: 'u', email: 'o@example.test', displayName: 'Owner' },
    workspace: { id: 'w', role: 'OWNER', timeZone: 'Europe/Paris', baseCurrency: 'EUR' },
  };
  client.setQueryData(['session'], session);
  api.getSession.mockImplementation(() => ok(session));
  const view = render(
    <QueryClientProvider client={client}>
      <RecordingTab account={{ ...account, ...overrides }} />
    </QueryClientProvider>,
  );
  return { ...view, client };
}

/** The nth valuation row of the dated list, newest first. */
function valuationRow(index: number): HTMLElement {
  return screen.getAllByRole('listitem').filter((item) => item.dataset.valuationId)[index]!;
}

async function loaded() {
  await waitFor(() => expect(screen.queryByTestId('valuations-skeleton')).toBeNull());
}

function changeValue(
  label: string | RegExp,
  value: string,
  scope: HTMLElement | Document = document,
) {
  fireEvent.change(within(scope as HTMLElement).getByLabelText(label), { target: { value } });
}

async function openRecordModal() {
  fireEvent.click(screen.getByRole('button', { name: 'Nouvelle valorisation' }));
  const dialog = await screen.findByRole('dialog', { name: 'Nouvelle valorisation' });
  changeValue('Date de valorisation', '2026-03-10', dialog);
  fireEvent.click(within(dialog).getByRole('button', { name: 'Continuer' }));
  await waitFor(() => expect(screen.queryByTestId('valuation-draft-skeleton')).toBeNull());
  await within(dialog).findByText(/retenir les espèces calculées/);
  return dialog;
}

const withDue = (dueMonths: string[]) =>
  api.readPortfolioSummary.mockImplementation(() => ok({ ...summaryFixture(), dueMonths }));

beforeEach(() => {
  withDue([]);
  vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-03-10T10:00:00Z') });
  api.listPortfolioValuations.mockImplementation(() => ok(valuationPageFixture()));
  api.readPortfolioValuationDraft.mockImplementation(() => ok(draftFixture()));
  api.readPortfolioValuation.mockImplementation(() => ok(positionsValuationFixture()));
});
afterEach(() => {
  cleanup();
  window.history.replaceState({}, '', '/');
  vi.useRealTimers();
  vi.clearAllMocks();
});

i18n.addResourceBundle('en', 'translation', english);

describe('ValuationsTab month end', () => {
  it('proposes the oldest due month end, then titles the draft with its date', async () => {
    withDue(['2026-01', '2026-02']);
    api.readPortfolioValuationDraft.mockImplementation(() =>
      ok(draftFixture({ valuedOn: '2026-01-31', suggestedDetail: 'POSITIONS' })),
    );
    renderTab();
    await loaded();
    await waitFor(() => expect(api.readPortfolioSummary).toHaveBeenCalled());
    fireEvent.click(screen.getByRole('button', { name: 'Nouvelle valorisation' }));
    const dialog = await screen.findByRole('dialog', { name: 'Nouvelle valorisation' });
    await waitFor(() =>
      expect(
        (within(dialog).getByLabelText('Date de valorisation') as HTMLInputElement).value,
      ).toBe('2026-01-31'),
    );
    fireEvent.click(within(dialog).getByRole('button', { name: 'Continuer' }));
    await waitFor(() => expect(screen.queryByTestId('valuation-draft-skeleton')).toBeNull());
    await within(dialog).findByText(/retenir les espèces calculées/);
    expect(screen.getByRole('dialog', { name: 'Valorisation du 31/01/2026' })).toBe(dialog);
    expect(api.readPortfolioValuationDraft.mock.calls[0]![0].query.valuedOn).toBe('2026-01-31');
    expect(
      (within(dialog).getByRole('radio', { name: 'Détail par ligne' }) as HTMLInputElement).checked,
    ).toBe(true);
  });

  it('opens the modal from a valid deep link and not from a non-due month', async () => {
    window.history.replaceState({}, '', '/portfolios/acc-1?tab=valuations&monthEnd=2026-02');
    withDue(['2026-02']);
    renderTab();
    const dialog = await screen.findByRole('dialog');
    await waitFor(() => expect(screen.queryByTestId('valuation-draft-skeleton')).toBeNull());
    await within(dialog).findByText(/retenir les espèces calculées/);
    expect(api.readPortfolioValuationDraft.mock.calls[0]![0].query.valuedOn).toBe('2026-02-28');
    expect(
      (within(dialog).getByRole('radio', { name: 'Valeur totale' }) as HTMLInputElement).checked,
    ).toBe(true);
    expect(window.location.search).toBe('?tab=valuations');
    cleanup();

    window.history.replaceState({}, '', '/portfolios/acc-1?tab=valuations&monthEnd=2026-03');
    renderTab();
    await loaded();
    await waitFor(() => expect(api.readPortfolioSummary).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(window.location.search).toBe('?tab=valuations'));
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('opens the record modal on action=record-valuation with the date prefilled, once', async () => {
    window.history.replaceState(
      {},
      '',
      '/portfolios/acc-1?tab=valuations&action=record-valuation&asOf=2026-10-31',
    );
    renderTab();
    const dialog = await screen.findByRole('dialog', { name: 'Valorisation du 31/10/2026' });
    await waitFor(() => expect(screen.queryByTestId('valuation-draft-skeleton')).toBeNull());
    expect(api.readPortfolioValuationDraft.mock.calls[0]![0].query.valuedOn).toBe('2026-10-31');
    expect(dialog).toBeTruthy();
    expect(window.location.search).toBe('?tab=valuations');
  });

  it('opens the record modal without a prefilled date when asOf is malformed', async () => {
    window.history.replaceState(
      {},
      '',
      '/portfolios/acc-1?tab=valuations&action=record-valuation&asOf=31-10-2026',
    );
    renderTab();
    const dialog = await screen.findByRole('dialog', { name: 'Nouvelle valorisation' });
    expect(
      (within(dialog).getByLabelText('Date de valorisation') as HTMLInputElement).value,
    ).not.toBe('31-10-2026');
    expect(window.location.search).toBe('?tab=valuations');
  });

  it('ignores an unknown action and leaves the URL alone', async () => {
    window.history.replaceState(
      {},
      '',
      '/portfolios/acc-1?tab=valuations&action=wipe&asOf=2026-10-31',
    );
    renderTab();
    await loaded();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(window.location.search).toContain('action=wipe');
  });

  it('ignores a malformed deep link month', async () => {
    window.history.replaceState({}, '', '/portfolios/acc-1?tab=valuations&monthEnd=abc');
    withDue(['2026-02']);
    renderTab();
    await loaded();
    expect(screen.queryByRole('dialog')).toBeNull();
  });
});

describe('ValuationsTab', () => {
  it('shows the real chrome while loading, then one dated row per valuation', async () => {
    renderTab();
    expect(screen.getByTestId('valuations-skeleton')).toBeTruthy();
    expect(screen.getByRole('list', { name: 'Valorisations du portefeuille' })).toBeTruthy();

    await loaded();
    const rows = screen.getAllByRole('listitem').filter((item) => item.dataset.valuationId);
    expect(rows).toHaveLength(2);
    expect(rows[0]?.textContent).toContain('Calculée');
    expect(rows[0]?.textContent).toMatch(/3\s664,70/);
    expect(rows[0]?.textContent).toContain('Complète');
    expect(rows[0]?.textContent).toContain('Prix actuels');
    expect(rows[1]?.textContent).toContain('Saisie manuelle');
    expect(rows[1]?.textContent).not.toContain('Prix');
  });

  it('shows Non calculable for a partial valuation and one alert cue naming the flags', async () => {
    api.listPortfolioValuations.mockImplementation(() =>
      ok(
        valuationPageFixture([
          positionsValuationFixture({
            totalValue: null,
            securitiesValue: null,
            completeness: 'PARTIAL',
            priceQuality: 'MISSING',
            flags: [
              'STALE_PRICES',
              'POSITIONS_CHANGED',
              'CASH_DIFFERENCE',
              'OBSERVED_TOTAL_DIFFERENCE',
            ],
          }),
        ]),
      ),
    );
    renderTab();
    await loaded();
    const row = screen.getAllByRole('listitem').find((item) => item.dataset.valuationId)!;
    expect(within(row).getAllByText('Non calculable').length).toBe(1);
    expect(row.textContent).toContain('Partielle');
    expect(row.textContent).toContain('Prix manquants');
    const alert = within(row).getByRole('img', { name: /^Alertes/ });
    for (const text of [
      'Prix anciens',
      'Écart d’espèces',
      'Écart avec le relevé',
      'Positions modifiées depuis',
    ]) {
      expect(alert.getAttribute('aria-label')).toContain(text);
    }
  });

  it('shows the empty state with its explanation', async () => {
    api.listPortfolioValuations.mockImplementation(() => ok(valuationPageFixture([])));
    renderTab();
    await loaded();
    expect(
      screen.getByText(
        'Saisissez la valeur du compte à partir d’un relevé avec Nouvelle valorisation.',
      ),
    ).toBeTruthy();
  });

  it('shows an error with a retry', async () => {
    api.listPortfolioValuations.mockImplementationOnce(() => refused(500, {}));
    renderTab();
    expect((await screen.findByRole('alert')).textContent).toContain(
      'Les valorisations sont indisponibles',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    await loaded();
    expect(screen.getByRole('list', { name: 'Valorisations du portefeuille' })).toBeTruthy();
  });

  it('prefills the draft with the last prices and shows their origin', async () => {
    renderTab();
    await loaded();
    const dialog = await openRecordModal();
    expect(api.readPortfolioValuationDraft).toHaveBeenCalledWith(
      expect.objectContaining({ path: { accountId: 'acc-1' }, query: { valuedOn: '2026-03-10' } }),
    );
    expect(within(dialog).getByText(/Dernière valeur/).textContent).toMatch(
      /3\s500,00.*28\/02\/2026/,
    );
    fireEvent.click(within(dialog).getByRole('radio', { name: 'Détail par ligne' }));
    const etf = within(dialog).getByRole('group', { name: 'ETF Monde Démo' });
    expect((within(etf).getByLabelText('Prix') as HTMLInputElement).value).toBe('231.20');
    expect((within(etf).getByLabelText('Date du prix') as HTMLInputElement).value).toBe(
      '2026-03-03',
    );
    expect(within(etf).getByText('Prix de l’achat du 03/03/2026')).toBeTruthy();
    const industry = within(dialog).getByRole('group', { name: 'Action Démo Industrie' });
    expect(within(industry).getByText('Prix de la valorisation du 03/03/2026')).toBeTruthy();
  });

  it('records a TOTAL valuation and opens its detail', async () => {
    api.recordPortfolioValuation.mockImplementation(() => ok(totalValuationFixture(), 201));
    api.readPortfolioValuation.mockImplementation(() => ok(totalValuationFixture()));
    renderTab();
    await loaded();
    const dialog = await openRecordModal();
    changeValue('Valeur totale du compte', '3664,70', dialog);
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.recordPortfolioValuation).toHaveBeenCalledTimes(1));
    const call = api.recordPortfolioValuation.mock.calls[0]![0];
    expect(call.body).toEqual({
      valuedOn: '2026-03-10',
      detail: 'TOTAL',
      totalValue: { value: '3664.70', assetCode: 'EUR' },
    });
    expect(call.headers['Idempotency-Key']).toBeTruthy();
    expect(await screen.findByRole('dialog', { name: /Valorisation du/ })).toBeTruthy();
  });

  it('sends a null price for an unknown-price line and shows the server error on its row', async () => {
    api.recordPortfolioValuation.mockImplementation(() =>
      refused(422, { code: 'price_precision_exceeded', field: '/prices/1/price' }),
    );
    renderTab();
    await loaded();
    const dialog = await openRecordModal();
    fireEvent.click(within(dialog).getByRole('radio', { name: 'Détail par ligne' }));
    const industry = within(dialog).getByRole('group', { name: 'Action Démo Industrie' });
    fireEvent.click(within(industry).getByLabelText('Prix inconnu'));
    expect((within(industry).getByLabelText('Prix') as HTMLInputElement).disabled).toBe(true);
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.recordPortfolioValuation).toHaveBeenCalled());
    expect(api.recordPortfolioValuation.mock.calls[0]![0].body.prices).toEqual([
      { instrumentId: 'ins-ind', price: null, priceDate: null },
      { instrumentId: 'ins-etf', price: '231.20', priceDate: '2026-03-03' },
    ]);
    const etf = within(dialog).getByRole('group', { name: 'ETF Monde Démo' });
    expect((await within(etf).findByRole('alert')).textContent).toBe(
      'Le prix dépasse la précision de l’instrument.',
    );
  });

  it('replaces the valuation of a date with the version of the clicked row, not the draft version', async () => {
    api.readPortfolioValuationDraft.mockImplementation(() =>
      ok(draftFixture({ currentValuation: { id: 'val-positions', version: 3 } })),
    );
    api.recordPortfolioValuation.mockImplementation(() => ok(totalValuationFixture(), 201));
    renderTab();
    await loaded();
    const row = valuationRow(0);
    fireEvent.click(within(row).getByRole('button', { name: /Actions pour la valorisation/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Remplacer la valorisation/ }));
    const dialog = await screen.findByRole('dialog', {
      name: 'Remplacer la valorisation du 10/03/2026',
    });
    await waitFor(() => expect(screen.queryByTestId('valuation-draft-skeleton')).toBeNull());
    await within(dialog).findByText(/retenir les espèces calculées/);
    changeValue('Valeur totale du compte', '3700', dialog);
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.recordPortfolioValuation).toHaveBeenCalled());
    expect(api.recordPortfolioValuation.mock.calls[0]![0].body.version).toBe(1);
  });

  it('shows the detail with exact figures, flags as sentences and positions', async () => {
    api.readPortfolioValuation.mockImplementation(() =>
      ok(positionsValuationFixture({ flags: ['STALE_PRICES'] })),
    );
    renderTab();
    await loaded();
    const row = valuationRow(0);
    fireEvent.click(within(row).getByRole('button', { name: /Détail de la valorisation/ }));
    const dialog = await screen.findByRole('dialog', { name: 'Valorisation du 10/03/2026' });
    await within(dialog).findByText('Tolérance');
    expect(within(dialog).getByText('Écart').nextElementSibling?.textContent).toMatch(/-0,001/);
    expect(within(dialog).getByText('Tolérance').nextElementSibling?.textContent).toMatch(/0,015/);
    expect(within(dialog).getByText(/plus de 5 jours/)).toBeTruthy();
    const positions = within(dialog).getByRole('table', { name: 'Positions valorisées' });
    const lines = within(positions).getAllByRole('row');
    expect(lines[1]?.textContent).toContain('Action Démo Industrie');
    expect(lines[2]?.textContent).toMatch(/2\s314,521/);
  });

  it.each([
    ['fr', 'REFRESH', 'Origine', 'Mise à jour après une opération'],
    ['fr', 'TAKEOVER', 'Origine', 'Reprise des positions'],
    ['en', 'REFRESH', 'Origin', 'Updated after an operation'],
    ['en', 'TAKEOVER', 'Origin', 'Position takeover'],
  ] as const)('names the %s origin of a %s valuation', async (language, source, label, origin) => {
    api.readPortfolioValuation.mockImplementation(() => ok(positionsValuationFixture({ source })));
    renderTab();
    await loaded();
    await act(() => i18n.changeLanguage(language));
    try {
      fireEvent.click(valuationRow(0));
      const dialog = await screen.findByRole('dialog');
      expect((await within(dialog).findByText(label)).nextElementSibling?.textContent).toBe(origin);
    } finally {
      await act(() => i18n.changeLanguage('fr'));
    }
  });

  it('shows no origin for a valuation the owner recorded', async () => {
    api.readPortfolioValuation.mockImplementation(() => ok(positionsValuationFixture()));
    renderTab();
    await loaded();
    fireEvent.click(valuationRow(0));
    const dialog = await screen.findByRole('dialog');
    await within(dialog).findByText('Tolérance');
    expect(within(dialog).queryByText('Origine')).toBeNull();
  });

  it('shows Non détaillé for the price columns of a TOTAL valuation', async () => {
    api.readPortfolioValuation.mockImplementation(() => ok(totalValuationFixture()));
    renderTab();
    await loaded();
    const row = valuationRow(1);
    fireEvent.click(within(row).getByRole('button', { name: /Détail de la valorisation/ }));
    const dialog = await screen.findByRole('dialog');
    const positions = await within(dialog).findByRole('table', { name: 'Positions valorisées' });
    expect(within(positions).getAllByText('Non détaillé').length).toBeGreaterThanOrEqual(2);
  });

  it('withdraws a valuation after confirmation, sending its version', async () => {
    api.withdrawPortfolioValuation.mockImplementation(() => ok({}));
    renderTab();
    await loaded();
    const row = valuationRow(0);
    fireEvent.click(within(row).getByRole('button', { name: /Actions pour la valorisation/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Retirer la valorisation/ }));
    const dialog = await screen.findByRole('dialog', { name: 'Retirer la valorisation' });
    expect(dialog.textContent).toContain(
      'La valeur publiée dans le patrimoine sera retirée ; la valorisation reste dans l’historique.',
    );
    fireEvent.click(within(dialog).getByRole('button', { name: 'Retirer' }));
    await waitFor(() => expect(api.withdrawPortfolioValuation).toHaveBeenCalled());
    expect(api.withdrawPortfolioValuation.mock.calls[0]![0]).toMatchObject({
      path: { id: 'val-positions' },
      body: { version: 1 },
    });
  });
});

describe('ValuationsTab robustness', () => {
  async function openReplace() {
    const row = valuationRow(0);
    fireEvent.click(within(row).getByRole('button', { name: /Actions pour la valorisation/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Remplacer la valorisation/ }));
    const dialog = await screen.findByRole('dialog', {
      name: 'Remplacer la valorisation du 10/03/2026',
    });
    await waitFor(() => expect(screen.queryByTestId('valuation-draft-skeleton')).toBeNull());
    await within(dialog).findByText(/retenir les espèces calculées/);
    return dialog;
  }

  it('keeps the draft form layout after a failed cold read and replaces only missing values on retry', async () => {
    api.readPortfolioValuationDraft.mockRejectedValueOnce(new TypeError('offline'));
    renderTab();
    await loaded();
    fireEvent.click(screen.getByRole('button', { name: 'Nouvelle valorisation' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Continuer' }));
    expect(
      await within(dialog).findByText('Le brouillon de valorisation est indisponible.'),
    ).toBeTruthy();
    expect(within(dialog).getByText('Valeur totale du compte')).toBeTruthy();
    expect(within(dialog).getByText('Espèces du relevé')).toBeTruthy();
    expect(
      within(dialog).getByText(/Laissez vide pour retenir les espèces calculées/),
    ).toBeTruthy();
    expect(within(dialog).queryByRole('status')).toBeNull();
    expect(
      (within(dialog).getByRole('button', { name: 'Enregistrer' }) as HTMLButtonElement).disabled,
    ).toBe(true);
    fireEvent.click(within(dialog).getByRole('button', { name: 'Réessayer' }));
    await waitFor(() =>
      expect(within(dialog).queryByTestId('valuation-draft-skeleton')).toBeNull(),
    );
    expect(within(dialog).queryByRole('alert')).toBeNull();
    expect(within(dialog).getByLabelText('Valeur totale du compte')).toBeTruthy();
    expect(
      (within(dialog).getByRole('button', { name: 'Enregistrer' }) as HTMLButtonElement).disabled,
    ).toBe(false);
  });

  it('retains typed values when a refreshed draft fails', async () => {
    const { client } = renderTab();
    await loaded();
    const dialog = await openRecordModal();
    changeValue('Valeur totale du compte', '6789.01', dialog);
    api.readPortfolioValuationDraft.mockRejectedValueOnce(new TypeError('offline'));
    await client.refetchQueries({ queryKey: ['portfolio-valuation-draft'] });
    expect(
      await within(dialog).findByText('Le brouillon de valorisation est indisponible.'),
    ).toBeTruthy();
    expect(
      (within(dialog).getByLabelText('Valeur totale du compte') as HTMLInputElement).value,
    ).toBe('6789.01');
    expect(within(dialog).queryByTestId('valuation-draft-skeleton')).toBeNull();
  });

  it('rebuilds the form when a refetched draft holds another position', async () => {
    const { client } = renderTab();
    await loaded();
    const dialog = await openReplace();
    fireEvent.click(within(dialog).getByRole('radio', { name: 'Détail par ligne' }));
    const extra = {
      ...draftFixture().positions[0]!,
      instrument: {
        ...draftFixture().positions[0]!.instrument,
        id: 'ins-new',
        name: 'Nouvelle Action',
      },
    };
    api.readPortfolioValuationDraft.mockImplementation(() =>
      ok(draftFixture({ positions: [...draftFixture().positions, extra] })),
    );
    await client.refetchQueries({ queryKey: ['portfolio-valuation-draft'] });
    await waitFor(() =>
      expect(within(dialog).queryByRole('group', { name: 'Nouvelle Action' })).toBeNull(),
    );
    fireEvent.click(await within(dialog).findByRole('radio', { name: 'Détail par ligne' }));
    expect(within(dialog).getByRole('group', { name: 'Nouvelle Action' })).toBeTruthy();
  });

  it('closes on a stale version and tells the user the list was refreshed', async () => {
    api.recordPortfolioValuation.mockImplementation(() =>
      refused(409, { code: 'stale_valuation_version' }),
    );
    renderTab();
    await loaded();
    const dialog = await openReplace();
    changeValue('Valeur totale du compte', '3700', dialog);
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByText(/déjà été modifiée/)).toBeTruthy();
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('shows the real detail layout while the valuation loads', async () => {
    api.readPortfolioValuation.mockImplementation(() => new Promise(() => undefined));
    renderTab();
    await loaded();
    const row = valuationRow(0);
    fireEvent.click(within(row).getByRole('button', { name: /Détail de la valorisation/ }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Tolérance')).toBeTruthy();
    expect(within(dialog).getByRole('heading', { name: 'Positions' })).toBeTruthy();
    expect(within(dialog).getByRole('columnheader', { name: 'Instrument' })).toBeTruthy();
  });

  it('shows the superseded day in the workspace time zone', async () => {
    api.readPortfolioValuation.mockImplementation(() =>
      ok(positionsValuationFixture({ supersededAt: '2026-03-10T23:30:00+00:00' })),
    );
    renderTab();
    await loaded();
    const row = valuationRow(0);
    fireEvent.click(within(row).getByRole('button', { name: /Détail de la valorisation/ }));
    expect(await screen.findByText(/Remplacée ou retirée le 11\/03\/2026/)).toBeTruthy();
  });
});

describe('360 px layout', () => {
  const originalWidth = window.innerWidth;
  beforeEach(() => {
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 360 });
  });
  afterEach(() => {
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: originalWidth });
  });

  it('keeps the valuation rows in the list and the form fieldsets in single columns', async () => {
    renderTab();
    await loaded();
    const row = valuationRow(0);
    expect(
      within(row).getByRole('button', { name: /Détail de la valorisation du 10\/03\/2026/ }),
    ).toBeTruthy();
    expect(row.textContent).toMatch(/3\s664,70/);
    const dialog = await openRecordModal();
    fireEvent.click(within(dialog).getByRole('radio', { name: 'Détail par ligne' }));
    const form = dialog.querySelector('form')!;
    expect(form.className).toContain('form');
    expect(within(dialog).getAllByRole('group', { name: /ETF|Action/ })).toHaveLength(2);
  });

  it('keeps the positions table scrollable', () => {
    render(<ValuationPositionsTable valuation={positionsValuationFixture()} />);
    expect(screen.getByRole('table').parentElement?.className).toContain('scroll');
  });
});

it('opens the detail from the row and keeps the row menu on a phone', async () => {
  renderTab();
  await loaded();
  const row = valuationRow(0);
  expect(row.getAttribute('data-menu-phone')).toBe('true');
  fireEvent.click(row);
  const dialog = await screen.findByRole('dialog');
  expect(await within(dialog).findByText('Tolérance')).toBeTruthy();
});

it('appends valuations and retains a coherent history across retry', async () => {
  const first = totalValuationFixture({ id: 'first', valuedOn: '2026-03-10' });
  const second = totalValuationFixture({ id: 'second', valuedOn: '2026-03-09' });
  api.listPortfolioValuations
    .mockImplementationOnce(() => ok({ items: [first], page: 1, pageSize: 24, total: 25 }))
    .mockImplementationOnce(() => refused(503, { type: '/problems/unavailable' }))
    .mockImplementationOnce(() => ok({ items: [second], page: 2, pageSize: 24, total: 25 }));
  renderTab();
  await loaded();
  expect(screen.getAllByRole('listitem').filter((item) => item.dataset.valuationId)).toHaveLength(
    1,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Afficher plus' }));
  await screen.findByRole('alert');
  expect(screen.getAllByRole('listitem').filter((item) => item.dataset.valuationId)).toHaveLength(
    1,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
  await waitFor(() =>
    expect(screen.getAllByRole('listitem').filter((item) => item.dataset.valuationId)).toHaveLength(
      2,
    ),
  );
  expect(screen.queryByRole('button', { name: 'Afficher plus' })).toBeNull();
});
