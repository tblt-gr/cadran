import type { Instrument } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { InstrumentsPage } from './InstrumentsPage';

const api = vi.hoisted(() => ({
  createInstrument: vi.fn(),
  deactivateInstrument: vi.fn(),
  listAssets: vi.fn(),
  listInstruments: vi.fn(),
  reactivateInstrument: vi.fn(),
  updateInstrument: vi.fn(),
}));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const instrument: Instrument = {
  id: '00000000-0000-7000-8000-000000000001',
  name: 'ETF Monde',
  isin: 'FR0000120271',
  ticker: 'CW8',
  venueMic: 'XPAR',
  instrumentType: 'ETF',
  assetClass: 'EQUITY',
  region: 'WORLD',
  currencyCode: 'EUR',
  quantityPrecision: 6,
  pricePrecision: 4,
  source: 'MANUAL',
  reviewed: true,
  reviewedAt: '2026-01-01T00:00:00+00:00',
  status: 'ACTIVE',
  used: false,
  version: 1,
};
function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}
function renderPage() {
  const query = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={query}>
      <InstrumentsPage />
    </QueryClientProvider>,
  );
}
function problem(code: string, status = 409, extra: object = {}) {
  return Promise.resolve({
    error: { code, ...extra },
    response: new Response('', { status }),
  });
}
async function openEdit(name = 'ETF Monde') {
  fireEvent.click(await screen.findByRole('button', { name: `Actions pour ${name}` }));
  fireEvent.click(await screen.findByRole('button', { name: `Modifier ${name}` }));
}
async function openStatus(verb = 'Désactiver', name = 'ETF Monde') {
  fireEvent.click(await screen.findByRole('button', { name: `Actions pour ${name}` }));
  fireEvent.click(await screen.findByRole('button', { name: `${verb} ${name}` }));
}
function setup(items: Instrument[] = [instrument]) {
  api.listAssets.mockResolvedValue(
    success({ items: [{ code: 'EUR', kind: 'FIAT' }], page: 1, perPage: 100, total: 1 }),
  );
  api.listInstruments.mockImplementation(() =>
    success({ items, page: 1, perPage: 20, total: items.length }),
  );
}

describe('InstrumentsPage', () => {
  beforeEach(() => window.history.replaceState({}, '', '/portfolios/instruments'));
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });
  it('validates the required name and identifier before creating', async () => {
    setup([]);
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier instrument' }));
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(screen.getByText('Le nom est obligatoire.')).toBeTruthy();
    expect(screen.getByText('Un ISIN ou un ticker est obligatoire.')).toBeTruthy();
    expect(api.createInstrument).not.toHaveBeenCalled();
  });
  it('shows duplicate candidates and resubmits the same form only after confirmation', async () => {
    setup([]);
    api.createInstrument
      .mockImplementationOnce(() =>
        Promise.resolve({
          error: {
            code: 'instrument_duplicate_candidates',
            candidates: [
              {
                id: instrument.id,
                name: instrument.name,
                isin: instrument.isin,
                ticker: instrument.ticker,
                status: 'ACTIVE',
              },
            ],
          },
          response: new Response('', { status: 409 }),
        }),
      )
      .mockImplementationOnce(() => success(instrument, 201));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier instrument' }));
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'ETF Monde bis' } });
    fireEvent.change(screen.getByLabelText('Ticker'), { target: { value: 'CW8B' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByRole('heading', { name: 'Instruments similaires' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Créer quand même' }));
    await waitFor(() => expect(api.createInstrument).toHaveBeenCalledTimes(2));
    expect(api.createInstrument.mock.calls[1]?.[0].body).toMatchObject({
      name: 'ETF Monde bis',
      confirmDuplicate: true,
    });
  });
  it('dismisses duplicate candidates without losing the entered form', async () => {
    setup([]);
    api.createInstrument.mockResolvedValue({
      error: { code: 'instrument_duplicate_candidates', candidates: [] },
      response: new Response('', { status: 409 }),
    });
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier instrument' }));
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'ETF Monde bis' } });
    fireEvent.change(screen.getByLabelText('Ticker'), { target: { value: 'CW8B' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await screen.findByRole('heading', { name: 'Instruments similaires' });
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }));
    await waitFor(() =>
      expect(screen.queryByRole('heading', { name: 'Instruments similaires' })).toBeNull(),
    );
    expect((screen.getByLabelText('Nom') as HTMLInputElement).value).toBe('ETF Monde bis');
  });
  it('requests the selected status filter and keeps the table scrollable at 360px', async () => {
    setup();
    renderPage();
    await screen.findByText('ETF Monde');
    fireEvent.click(screen.getByRole('button', { name: 'Inactifs' }));
    await waitFor(() =>
      expect(api.listInstruments).toHaveBeenLastCalledWith(
        expect.objectContaining({ query: expect.objectContaining({ status: 'inactive' }) }),
      ),
    );
    const table = await screen.findByRole('table');
    expect(table.parentElement?.className).toContain('scroll');
  });
  it('follows the existing ISIN link to the filtered list without a page reload', async () => {
    setup([]);
    api.createInstrument.mockImplementation(() =>
      problem('instrument_isin_exists', 409, { existingId: instrument.id }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier instrument' }));
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'Doublon' } });
    fireEvent.change(screen.getByLabelText('ISIN'), { target: { value: 'fr0000120271' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    fireEvent.click(await screen.findByRole('link', { name: 'Voir l’instrument existant' }));
    await waitFor(() =>
      expect(api.listInstruments).toHaveBeenLastCalledWith(
        expect.objectContaining({
          query: expect.objectContaining({ q: 'FR0000120271', status: 'all' }),
        }),
      ),
    );
    expect(window.location.pathname + window.location.search).toBe(
      '/portfolios/instruments?q=FR0000120271&status=all',
    );
    expect(screen.queryByRole('dialog')).toBeNull();
    expect((screen.getByLabelText('Rechercher un instrument') as HTMLInputElement).value).toBe(
      'FR0000120271',
    );
  });
  it('reads the search and status from the URL on first render', async () => {
    window.history.replaceState({}, '', '/portfolios/instruments?q=CW8&status=inactive');
    setup();
    renderPage();
    await waitFor(() =>
      expect(api.listInstruments).toHaveBeenCalledWith(
        expect.objectContaining({
          query: expect.objectContaining({ q: 'CW8', status: 'inactive' }),
        }),
      ),
    );
  });
  it('clears a stale status error when the status dialog is closed and reopened', async () => {
    setup();
    api.deactivateInstrument.mockImplementation(() => problem('server_error', 500));
    renderPage();
    await openStatus();
    fireEvent.click(await screen.findByRole('button', { name: 'Désactiver' }));
    await screen.findByText('La modification est indisponible. Réessayez.');
    fireEvent.keyDown(document, { key: 'Escape' });
    await openStatus();
    expect(screen.queryByText('La modification est indisponible. Réessayez.')).toBeNull();
  });
  it('refetches, closes the dialog and explains a stale status change', async () => {
    setup();
    api.deactivateInstrument.mockImplementation(() => problem('instrument_already_inactive'));
    renderPage();
    await openStatus();
    const calls = api.listInstruments.mock.calls.length;
    fireEvent.click(await screen.findByRole('button', { name: 'Désactiver' }));
    expect((await screen.findByRole('status')).textContent).toContain('déjà été modifié');
    await waitFor(() => expect(api.listInstruments.mock.calls.length).toBeGreaterThan(calls));
    expect(screen.queryByRole('button', { name: 'Désactiver' })).toBeNull();
  });
  it.each([
    ['invalid_instrument_isin', 'Cet ISIN est invalide.'],
    ['invalid_instrument_ticker', 'Ce ticker est invalide.'],
    ['invalid_instrument_venue', 'Ce code MIC est invalide.'],
  ])('shows %s on its own field', async (code, message) => {
    setup([]);
    api.createInstrument.mockImplementation(() => problem(code, 422));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier instrument' }));
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'X' } });
    fireEvent.change(screen.getByLabelText('Ticker'), { target: { value: 'X' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    const text = await screen.findByText(message);
    expect(text.closest('label')).toBeTruthy();
  });
  it.each([
    ['stale_instrument_version', 'modifié ailleurs'],
    ['instrument_identity_locked', 'figés'],
    ['instrument_limit_reached', 'limite'],
  ])('maps %s to a specific message', async (code, fragment) => {
    setup([]);
    api.createInstrument.mockImplementation(() => problem(code));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier instrument' }));
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'X' } });
    fireEvent.change(screen.getByLabelText('Ticker'), { target: { value: 'X' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect((await screen.findByRole('alert')).textContent).toContain(fragment);
  });
  it('shows a network message when the save request fails', async () => {
    setup([]);
    api.createInstrument.mockRejectedValue(new TypeError('offline'));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier instrument' }));
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'X' } });
    fireEvent.change(screen.getByLabelText('Ticker'), { target: { value: 'X' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect((await screen.findByRole('alert')).textContent).toContain('connexion');
  });
  it('explains a price precision outside 0-12 instead of silently blocking', async () => {
    setup([]);
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier instrument' }));
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'X' } });
    fireEvent.change(screen.getByLabelText('Ticker'), { target: { value: 'X' } });
    fireEvent.change(screen.getByLabelText('Précision des prix'), { target: { value: '13' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(screen.getAllByText('La précision doit être comprise entre 0 et 12.')).toHaveLength(1);
    fireEvent.change(screen.getByLabelText('Précision des quantités'), { target: { value: '99' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(screen.getAllByText('La précision doit être comprise entre 0 et 12.')).toHaveLength(2);
    expect(api.createInstrument).not.toHaveBeenCalled();
  });
  it('clamps to the last page and keeps the table when the page is out of range', async () => {
    setup();
    api.listInstruments.mockImplementation((options: { query: { page: number } }) =>
      options.query.page === 2
        ? success({ items: [], page: 2, perPage: 20, total: 1 })
        : success({ items: [instrument], page: 1, perPage: 20, total: 21 }),
    );
    renderPage();
    await screen.findByText('ETF Monde');
    fireEvent.click(screen.getByRole('button', { name: 'Suivant' }));
    await waitFor(() =>
      expect(
        api.listInstruments.mock.calls.filter(([o]) => o.query.page === 2).length,
      ).toBeGreaterThan(0),
    );
    await waitFor(() =>
      expect(api.listInstruments).toHaveBeenLastCalledWith(
        expect.objectContaining({ query: expect.objectContaining({ page: 1 }) }),
      ),
    );
    expect(await screen.findByText('Page 1 sur 2')).toBeTruthy();
    expect(screen.queryByText('Aucun instrument')).toBeNull();
    expect(await screen.findByText('ETF Monde')).toBeTruthy();
  });
  it('labels the duplicate confirmation as saving when editing', async () => {
    setup();
    api.updateInstrument.mockImplementation(() =>
      problem('instrument_duplicate_candidates', 409, { candidates: [] }),
    );
    renderPage();
    await openEdit();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByRole('button', { name: 'Enregistrer quand même' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Créer quand même' })).toBeNull();
  });
});
