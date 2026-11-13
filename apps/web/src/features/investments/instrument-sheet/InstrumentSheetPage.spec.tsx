import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import {
  HeaderTitleProvider,
  useHeaderTitleValue,
} from '@/components/layout/header/HeaderTitleContext';
import { InstrumentSheetPage } from './InstrumentSheetPage';
import { instrumentSheetFixture } from './instrumentSheetFixture';
const read = vi.hoisted(() => vi.fn());
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  readInstrumentHolding: read,
}));
vi.mock('@/features/investments/instruments/instrument-editor-modal/InstrumentEditorModal', () => ({
  InstrumentEditorModal: () => <div role="dialog">Édition existante</div>,
}));
vi.mock(
  '@/features/investments/instruments/instrument-status-dialog/InstrumentStatusModal',
  () => ({ InstrumentStatusModal: () => <div role="dialog">Statut existant</div> }),
);
const data = instrumentSheetFixture();
const ok = (value = data) => ({ data: value, response: new Response('', { status: 200 }) });
function Title() {
  const title = useHeaderTitleValue(window.location.pathname);
  return <h1>{title ?? 'Instrument'}</h1>;
}
function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <HeaderTitleProvider>
        <Title />
        <InstrumentSheetPage instrumentId={data.instrument.id} />
      </HeaderTitleProvider>
    </QueryClientProvider>,
  );
  return client;
}
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});
beforeEach(() => {
  window.history.replaceState({}, '', `/investments/instruments/${data.instrument.id}`);
});
describe('InstrumentSheetPage', () => {
  it('keeps static chrome and labels while values load', () => {
    read.mockReturnValue(new Promise(() => {}));
    mount();
    expect(
      screen.getByRole('button', { name: 'Actions de l’instrument' }).hasAttribute('disabled'),
    ).toBe(true);
    expect(screen.getByRole('heading', { name: 'Quantité détenue' })).toBeTruthy();
    expect(screen.getByRole('table', { name: 'Par compte' })).toBeTruthy();
    expect(document.querySelectorAll('[data-skeleton]').length).toBeGreaterThan(0);
    expect(screen.getAllByRole('status')).toHaveLength(1);
  });
  it('renders server values, the definition, and filtered operations links', async () => {
    read.mockResolvedValue(ok());
    mount();
    await screen.findByRole('heading', { name: data.instrument.name });
    expect(screen.getAllByText(/\+39,20/).length).toBe(2);
    expect(screen.getAllByText(/\+15,00/).length).toBe(2);
    expect(screen.getAllByText(/\+28,80/).length).toBe(2);
    expect(screen.getByText(/-5,50/)).toBeTruthy();
    expect(screen.getByText(/\+77,50/)).toBeTruthy();
    expect(screen.getByText(/XPAR · ETF · Actions · Monde · EUR/)).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Voir les opérations' }).getAttribute('href')).toBe(
      `/portfolios/${data.accounts[0]!.accountId}?tab=operations&instrument=${data.instrument.id}`,
    );
    expect(
      within(screen.getByRole('banner')).queryByRole('link', {
        name: `Voir l'instrument ${data.instrument.name}`,
      }),
    ).toBeNull();
  });
  it('colours realized, unrealized and total gains by sign, never zero or income', async () => {
    read.mockResolvedValue(ok());
    mount();
    await screen.findByRole('heading', { name: data.instrument.name });
    const tone = (text: RegExp) =>
      screen
        .getAllByText(text)
        .map((node) => node.closest('[class*="positive"], [class*="negative"]')?.className ?? '');
    expect(tone(/\+77,50/).some((name) => /positive/.test(name))).toBe(true);
    expect(tone(/\+39,20/).every((name) => /positive/.test(name))).toBe(true);
    expect(tone(/\+28,80/).every((name) => /positive/.test(name))).toBe(true);
    expect(tone(/\+15,00/).every((name) => name === '')).toBe(true);
  });
  it.each(['instrument_not_found'])(
    'shows a missing or foreign instrument without a table',
    async (code) => {
      read.mockResolvedValue({ error: { code }, response: new Response('', { status: 404 }) });
      mount();
      await screen.findByRole('heading', { name: 'Page introuvable' });
      expect(screen.queryByRole('table')).toBeNull();
      expect(screen.queryByRole('link', { name: 'Voir tous les instruments' })).toBeNull();
      expect(screen.queryByRole('button', { name: 'Actions de l’instrument' })).toBeNull();
    },
  );
  it('keeps the header and retries an error', async () => {
    read.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(ok());
    mount();
    await screen.findByText('La fiche est indisponible.');
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    await screen.findByRole('heading', { name: data.instrument.name });
  });
  it('shows quiet empty figures without inventing zeros', async () => {
    read.mockResolvedValue(ok({ ...data, holding: null, accounts: [] }));
    mount();
    await screen.findByText(/Cet instrument n'est détenu sur aucun compte\./);
    // One sentence instead of six empty figures.
    expect(screen.queryByRole('region', { name: "Synthèse de l'instrument" })).toBeNull();
    expect(screen.queryByText('Aucune détention')).toBeNull();
    expect(screen.queryByRole('table')).toBeNull();
    expect(screen.queryByText(/0,00/)).toBeNull();
  });
  it('keeps retained holdings visible when there is no active operation history', async () => {
    read.mockResolvedValue(ok({ ...data, accounts: [] }));
    mount();
    await screen.findByRole('heading', { name: data.instrument.name });
    expect(screen.getByText(/630,00/)).toBeTruthy();
    expect(screen.queryByText(/Cet instrument n'est détenu sur aucun compte\./)).toBeNull();
    expect(screen.getByRole('table', { name: 'Par compte' })).toBeTruthy();
  });

  it('keeps archived instruments readable and reuses edit/status dialogs', async () => {
    read.mockResolvedValue(ok({ ...data, instrument: { ...data.instrument, status: 'INACTIVE' } }));
    mount();
    await screen.findByText('Archivé');
    fireEvent.click(screen.getByRole('button', { name: /^Actions de l’instrument/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
    expect(screen.getByRole('dialog').textContent).toBe('Édition existante');
  });
  it('reuses the status dialog from the sheet', async () => {
    read.mockResolvedValue(ok());
    mount();
    await screen.findByRole('heading', { name: data.instrument.name });
    fireEvent.click(screen.getByRole('button', { name: /^Actions de l’instrument/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Archiver' }));
    expect(screen.getByRole('dialog').textContent).toBe('Statut existant');
  });
  it('explains null realized gains and oversized histories in words', async () => {
    read.mockResolvedValue(
      ok({
        ...data,
        accounts: [{ ...data.accounts[0]!, reason: 'HISTORY_TOO_LARGE', realizedGain: null }],
        totals: { ...data.totals, realizedGain: null, totalGain: null, reason: 'UNKNOWN_LOT_COST' },
      }),
    );
    mount();
    await screen.findByText("Plus-value réalisée non calculable : coût d'origine inconnu");
    expect(screen.getAllByText('Historique trop volumineux pour ce compte').length).toBeGreaterThan(
      0,
    );
  });
  it('keeps existing values visible during invalidation', async () => {
    read.mockResolvedValueOnce(ok()).mockReturnValue(new Promise(() => {}));
    const client = mount();
    await screen.findByRole('heading', { name: data.instrument.name });
    void client.invalidateQueries({ queryKey: ['instrument-holding'] });
    await waitFor(() => expect(read).toHaveBeenCalledTimes(2));
    expect(screen.getByText(/\+77,50/)).toBeTruthy();
    expect(document.querySelector('[data-skeleton]')).toBeNull();
  });
});
