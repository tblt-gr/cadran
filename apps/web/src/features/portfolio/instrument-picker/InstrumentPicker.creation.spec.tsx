import type { Instrument } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { StrictMode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { InstrumentPicker } from './InstrumentPicker';

const api = vi.hoisted(() => ({
  listInstruments: vi.fn(),
  createInstrument: vi.fn(),
  readInstrument: vi.fn(),
  reactivateInstrument: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
vi.mock('@/features/auth/useWorkspaceBaseCurrency', () => ({
  useWorkspaceBaseCurrency: () => 'EUR',
}));
vi.mock('@/hooks/use-reference-assets', () => ({
  useReferenceAssets: () => ({
    data: { items: [{ code: 'EUR', kind: 'FIAT', displayPrecision: 2 }] },
    isPending: false,
    isError: false,
  }),
}));
vi.mock('@/features/auth/withCsrfRetry', () => ({
  withCsrfRetry: (request: () => unknown) => request(),
}));

const instrument: Instrument = {
  id: '00000000-0000-7000-8000-000000000001',
  name: 'ETF Monde',
  isin: 'FR0000120271',
  ticker: 'CW8',
  venueMic: null,
  instrumentType: 'ETF',
  assetClass: null,
  region: null,
  currencyCode: 'EUR',
  quantityPrecision: 6,
  pricePrecision: 4,
  source: 'MANUAL',
  reviewed: true,
  reviewedAt: null,
  status: 'ACTIVE',
  used: false,
  version: 1,
};
const created = { ...instrument, id: '00000000-0000-7000-8000-000000000002', name: 'World ESG' };
const ok = (data: Instrument) => ({ data, response: new Response('{}') });
function delayedResponse() {
  let resolve!: (value: ReturnType<typeof ok>) => void;
  const promise = new Promise<ReturnType<typeof ok>>((done) => {
    resolve = done;
  });
  return { promise, resolve };
}
function setup() {
  api.listInstruments.mockResolvedValue({
    data: { items: [instrument] },
    response: new Response('{}'),
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const invalidate = vi.spyOn(client, 'invalidateQueries');
  const onChange = vi.fn();
  const view = render(
    <StrictMode>
      <QueryClientProvider client={client}>
        <InstrumentPicker allowCreate currencyCode="EUR" label="Instrument" onChange={onChange} />
      </QueryClientProvider>
    </StrictMode>,
  );
  return {
    ...view,
    invalidate,
    onChange,
    input: screen.getByRole('combobox', { name: 'Instrument' }),
  };
}
async function open(input: HTMLElement) {
  fireEvent.change(input, { target: { value: 'World ESG' } });
  fireEvent.focus(input);
  fireEvent.mouseDown(await screen.findByRole('option', { name: 'Créer « World ESG »' }));
  const dialog = await screen.findByRole('dialog', { name: 'Créer un instrument' });
  await waitFor(() => expect(document.activeElement).toBe(within(dialog).getByLabelText('ISIN')));
  return dialog;
}
function submit(dialog: HTMLElement) {
  fireEvent.change(within(dialog).getByLabelText('ISIN'), { target: { value: instrument.isin } });
  fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
}
async function selectOther(input: HTMLElement) {
  fireEvent.focus(input);
  fireEvent.mouseDown(await screen.findByRole('option', { name: /ETF Monde/ }));
}
afterEach(() => {
  cleanup();
  vi.resetAllMocks();
});
describe('dismissed instrument creation sessions', () => {
  it('keeps a later selection when a dismissed creation completes and still refreshes caches', async () => {
    const response = delayedResponse();
    api.createInstrument.mockReturnValue(response.promise);
    const { input, onChange, invalidate } = setup();
    const dialog = await open(input);
    submit(dialog);
    await waitFor(() => expect(api.createInstrument).toHaveBeenCalledTimes(1));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Fermer' }));
    await selectOther(input);
    await act(async () => {
      response.resolve(ok(created));
    });
    await waitFor(() =>
      expect(invalidate).toHaveBeenCalledWith({ queryKey: ['instrument-picker'] }),
    );
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['instruments'] });
    expect(onChange).toHaveBeenLastCalledWith(instrument);
    expect((input as HTMLInputElement).value).toBe(instrument.name);
  });
  it('keeps a reopened dialog with the same text when the earlier creation completes', async () => {
    const response = delayedResponse();
    api.createInstrument.mockReturnValue(response.promise);
    const { input, onChange, invalidate } = setup();
    const first = await open(input);
    submit(first);
    await waitFor(() => expect(api.createInstrument).toHaveBeenCalledTimes(1));
    fireEvent.keyDown(document, { key: 'Escape' });
    const second = await open(input);
    fireEvent.change(within(second).getByLabelText('Ticker'), { target: { value: 'KEEP' } });
    await act(async () => {
      response.resolve(ok(created));
    });
    await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: ['instruments'] }));
    expect(screen.getByRole('dialog', { name: 'Créer un instrument' })).toBe(second);
    expect((within(second).getByLabelText('Ticker') as HTMLInputElement).value).toBe('KEEP');
    expect(onChange).not.toHaveBeenCalledWith(created);
  });
  it('ignores creation UI effects after the picker unmounts', async () => {
    const response = delayedResponse();
    api.createInstrument.mockReturnValue(response.promise);
    const { input, onChange, invalidate, unmount } = setup();
    submit(await open(input));
    await waitFor(() => expect(api.createInstrument).toHaveBeenCalledTimes(1));
    unmount();
    await act(async () => {
      response.resolve(ok(created));
    });
    await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: ['instruments'] }));
    expect(onChange).not.toHaveBeenCalledWith(created);
  });
  it('keeps a later selection when a dismissed reactivation completes', async () => {
    const response = delayedResponse();
    api.createInstrument.mockResolvedValue({
      error: { code: 'instrument_isin_exists', existingId: created.id },
      response: new Response('{}', { status: 409 }),
    });
    api.readInstrument.mockResolvedValue(ok({ ...created, status: 'INACTIVE' }));
    api.reactivateInstrument.mockReturnValue(response.promise);
    const { input, onChange, invalidate } = setup();
    const dialog = await open(input);
    submit(dialog);
    fireEvent.click(await screen.findByRole('button', { name: 'Réactiver et utiliser' }));
    await waitFor(() => expect(api.reactivateInstrument).toHaveBeenCalledTimes(1));
    fireEvent.keyDown(document, { key: 'Escape' });
    await selectOther(input);
    await act(async () => {
      response.resolve(ok(created));
    });
    await waitFor(() =>
      expect(invalidate).toHaveBeenCalledWith({ queryKey: ['instrument', created.id] }),
    );
    expect(onChange).toHaveBeenLastCalledWith(instrument);
    expect((input as HTMLInputElement).value).toBe(instrument.name);
  });
  it('still selects a normally completed creation under StrictMode', async () => {
    api.createInstrument.mockResolvedValue(ok(created));
    const { input, onChange } = setup();
    submit(await open(input));
    await waitFor(() => expect(onChange).toHaveBeenCalledWith(created));
    expect(screen.queryByRole('dialog')).toBeNull();
  });
});
