import type { Instrument } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { InstrumentEditorModal } from './InstrumentEditorModal';

const api = vi.hoisted(() => ({
  createInstrument: vi.fn(),
  updateInstrument: vi.fn(),
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
  id: 'ins-1',
  name: 'World ESG',
  isin: 'IE00BK5BQT80',
  ticker: null,
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
const ok = (data: Instrument) => ({ data, response: new Response('{}') });
const conflict = (code: string, extra: object) => ({
  error: { code, ...extra },
  response: new Response('{}', { status: 409 }),
});
function setup(props: Partial<React.ComponentProps<typeof InstrumentEditorModal>> = {}) {
  const saved = vi.fn();
  const close = vi.fn();
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const invalidate = vi.spyOn(client, 'invalidateQueries');
  render(
    <QueryClientProvider client={client}>
      <InstrumentEditorModal
        initial={{ name: 'World ESG', currencyCode: 'EUR' }}
        lock={{ currencyCode: true }}
        onClose={close}
        onSaved={saved}
        {...props}
      />
    </QueryClientProvider>,
  );
  return { saved, close, invalidate };
}
function submit() {
  fireEvent.change(screen.getByLabelText('ISIN'), { target: { value: instrument.isin } });
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
}
afterEach(() => {
  cleanup();
  vi.resetAllMocks();
});
describe('stacked InstrumentEditorModal', () => {
  it('focuses ISIN for a prefilled name, saves once and returns the complete instrument', async () => {
    api.createInstrument.mockResolvedValue(ok(instrument));
    const { saved, close, invalidate } = setup();
    await waitFor(() => expect(document.activeElement).toBe(screen.getByLabelText('ISIN')));
    expect(screen.getByRole('dialog', { name: 'Créer un instrument' })).toBeTruthy();
    expect(screen.getByText('Nouvel instrument')).toBeTruthy();
    submit();
    await waitFor(() => expect(saved).toHaveBeenCalledWith(instrument));
    expect(close).toHaveBeenCalledTimes(1);
    expect(api.createInstrument).toHaveBeenCalledWith(
      expect.objectContaining({
        body: expect.objectContaining({ currencyCode: 'EUR' }),
        headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }),
      }),
    );
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['instruments'] });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['instrument-picker'] });
  });
  it('focuses the empty name when ISIN is prefilled', async () => {
    setup({ initial: { isin: instrument.isin!, currencyCode: 'EUR' } });
    await waitFor(() => expect(document.activeElement).toBe(screen.getByLabelText('Nom')));
    expect((screen.getByLabelText('ISIN') as HTMLInputElement).value).toBe(instrument.isin);
  });
  it.each(['ACTIVE', 'INACTIVE'] as const)('uses an existing ISIN in status %s', async (status) => {
    api.createInstrument.mockResolvedValue(
      conflict('instrument_isin_exists', { existingId: instrument.id }),
    );
    api.readInstrument.mockResolvedValue(ok({ ...instrument, status }));
    api.reactivateInstrument.mockResolvedValue(ok({ ...instrument, version: 2 }));
    const { saved } = setup();
    submit();
    const button = await screen.findByRole('button', {
      name: status === 'ACTIVE' ? 'Utiliser cet instrument' : 'Réactiver et utiliser',
    });
    await waitFor(() =>
      expect(screen.getByText(/Cet ISIN existe déjà/).textContent).toContain('World ESG'),
    );
    expect(screen.queryByRole('link', { name: 'Voir l’instrument existant' })).toBeNull();
    fireEvent.click(button);
    await waitFor(() =>
      expect(saved).toHaveBeenCalledWith(
        expect.objectContaining({ id: instrument.id, status: 'ACTIVE' }),
      ),
    );
    expect(api.createInstrument).toHaveBeenCalledTimes(1);
    if (status === 'INACTIVE')
      expect(api.reactivateInstrument).toHaveBeenCalledWith(
        expect.objectContaining({ path: { id: instrument.id }, body: { version: 1 } }),
      );
    else expect(api.reactivateInstrument).not.toHaveBeenCalled();
  });
  it.each(['ACTIVE', 'INACTIVE'] as const)(
    'uses a duplicate candidate in status %s',
    async (status) => {
      api.createInstrument.mockResolvedValue(
        conflict('instrument_duplicate_candidates', { candidates: [{ ...instrument, status }] }),
      );
      api.readInstrument.mockResolvedValue(ok({ ...instrument, status }));
      api.reactivateInstrument.mockResolvedValue(ok(instrument));
      const { saved } = setup();
      submit();
      const use = await screen.findByRole('button', { name: 'Utiliser World ESG' });
      expect(use.textContent).toBe(status === 'ACTIVE' ? 'Utiliser' : 'Réactiver et utiliser');
      fireEvent.click(use);
      await waitFor(() => expect(saved).toHaveBeenCalledWith(instrument));
      expect(api.createInstrument).toHaveBeenCalledTimes(1);
    },
  );
  it('keeps explicit duplicate confirmation and its retry payload', async () => {
    api.createInstrument
      .mockResolvedValueOnce(
        conflict('instrument_duplicate_candidates', { candidates: [instrument] }),
      )
      .mockResolvedValueOnce(ok(instrument));
    const { saved } = setup();
    submit();
    fireEvent.click(await screen.findByRole('button', { name: 'Créer quand même' }));
    await waitFor(() => expect(saved).toHaveBeenCalledWith(instrument));
    expect(api.createInstrument).toHaveBeenLastCalledWith(
      expect.objectContaining({ body: expect.objectContaining({ confirmDuplicate: true }) }),
    );
  });
  it('blocks an incompatible existing instrument without reactivation', async () => {
    api.createInstrument.mockResolvedValue(
      conflict('instrument_isin_exists', { existingId: instrument.id }),
    );
    api.readInstrument.mockResolvedValue(
      ok({ ...instrument, currencyCode: 'USD', status: 'INACTIVE' }),
    );
    const { saved } = setup();
    submit();
    await screen.findByText('Cet instrument ne correspond pas à la devise ou au type du compte.');
    expect(
      (screen.getByRole('button', { name: 'Réactiver et utiliser' }) as HTMLButtonElement).disabled,
    ).toBe(true);
    expect(saved).not.toHaveBeenCalled();
    expect(api.reactivateInstrument).not.toHaveBeenCalled();
  });
  it('keeps the editor open if a scoped candidate read returns 404', async () => {
    api.createInstrument.mockResolvedValue(
      conflict('instrument_duplicate_candidates', { candidates: [instrument] }),
    );
    api.readInstrument.mockResolvedValue({ response: new Response('{}', { status: 404 }) });
    const { saved, close } = setup();
    submit();
    fireEvent.click(await screen.findByRole('button', { name: 'Utiliser World ESG' }));
    await screen.findByText(/Impossible d’utiliser cet instrument/);
    expect(saved).not.toHaveBeenCalled();
    expect(close).not.toHaveBeenCalled();
    expect(api.reactivateInstrument).not.toHaveBeenCalled();
  });
  it('retries a failed existing-instrument read in place', async () => {
    api.createInstrument.mockResolvedValue(
      conflict('instrument_isin_exists', { existingId: instrument.id }),
    );
    api.readInstrument
      .mockResolvedValueOnce({ response: new Response('{}', { status: 404 }) })
      .mockResolvedValue(ok(instrument));
    const { saved } = setup();
    submit();
    fireEvent.click(await screen.findByRole('button', { name: 'Réessayer' }));
    await waitFor(() =>
      expect(
        (screen.getByRole('button', { name: 'Utiliser cet instrument' }) as HTMLButtonElement)
          .disabled,
      ).toBe(false),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Utiliser cet instrument' }));
    await waitFor(() => expect(saved).toHaveBeenCalledWith(instrument));
  });
  it('retries a failed reactivation without creating another instrument', async () => {
    api.createInstrument.mockResolvedValue(
      conflict('instrument_isin_exists', { existingId: instrument.id }),
    );
    api.readInstrument.mockResolvedValue(ok({ ...instrument, status: 'INACTIVE' }));
    api.reactivateInstrument
      .mockResolvedValueOnce({ response: new Response('{}', { status: 404 }) })
      .mockResolvedValueOnce(ok(instrument));
    const { saved } = setup();
    submit();
    fireEvent.click(await screen.findByRole('button', { name: 'Réactiver et utiliser' }));
    await screen.findByText(/Impossible d’utiliser cet instrument/);
    expect(saved).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Réactiver et utiliser' }));
    await waitFor(() => expect(saved).toHaveBeenCalledWith(instrument));
    expect(api.createInstrument).toHaveBeenCalledTimes(1);
  });
  it('rejects a candidate of another required type before reactivation', async () => {
    api.createInstrument.mockResolvedValue(
      conflict('instrument_duplicate_candidates', { candidates: [instrument] }),
    );
    api.readInstrument.mockResolvedValue(ok({ ...instrument, status: 'INACTIVE' }));
    const { saved } = setup({
      initial: { name: 'Fonds euros', instrumentType: 'EURO_FUND', currencyCode: 'EUR' },
      lock: { currencyCode: true, instrumentType: true },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Utiliser World ESG' }));
    await screen.findByText(/Impossible d’utiliser cet instrument/);
    expect(saved).not.toHaveBeenCalled();
    expect(api.reactivateInstrument).not.toHaveBeenCalled();
  });
  it.each([
    {},
    { ...instrument, name: null },
    { ...instrument, currencyCode: 'USD' },
    { ...instrument, instrumentType: 'EURO_FUND' },
  ])('rejects a malformed or mismatched successful creation payload', async (data) => {
    api.createInstrument.mockResolvedValue({ data, response: new Response('{}') });
    const { saved, close } = setup();
    submit();
    await screen.findByText('La réponse de l’instrument est incompatible. Réessayez.');
    expect(saved).not.toHaveBeenCalled();
    expect(close).not.toHaveBeenCalled();
  });
  it('does not submit twice while saving', async () => {
    api.createInstrument.mockImplementation(() => new Promise(() => undefined));
    setup();
    submit();
    await screen.findByRole('button', { name: 'Enregistrement…' });
    fireEvent.submit(screen.getByLabelText('Nom').closest('form')!);
    expect(api.createInstrument).toHaveBeenCalledTimes(1);
  });
});
