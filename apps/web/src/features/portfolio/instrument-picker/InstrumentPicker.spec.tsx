import type { Instrument } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { Modal } from '@/components/ui/modal/Modal';
import { InstrumentPicker } from './InstrumentPicker';

const api = vi.hoisted(() => ({ listInstruments: vi.fn() }));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
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
function renderPicker(onChange = vi.fn()) {
  const query = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={query}>
      <InstrumentPicker currencyCode="EUR" label="Instrument" onChange={onChange} />
    </QueryClientProvider>,
  );
  return onChange;
}
describe('InstrumentPicker', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });
  it('selects an active matching instrument with arrow keys and Enter', async () => {
    api.listInstruments.mockResolvedValue({
      data: { items: [instrument] },
      response: new Response('{}', { status: 200 }),
    });
    const onChange = renderPicker();
    const picker = screen.getByRole('combobox', { name: 'Instrument' });
    fireEvent.focus(picker);
    expect(await screen.findByRole('option')).toBeTruthy();
    fireEvent.keyDown(picker, { key: 'ArrowDown' });
    fireEvent.keyDown(picker, { key: 'Enter' });
    expect(onChange).toHaveBeenLastCalledWith(instrument);
    expect(picker.getAttribute('aria-expanded')).toBe('false');
  });
  it('has a useful empty result and a management link', async () => {
    api.listInstruments.mockResolvedValue({
      data: { items: [] },
      response: new Response('{}', { status: 200 }),
    });
    renderPicker();
    fireEvent.focus(screen.getByRole('combobox', { name: 'Instrument' }));
    expect(await screen.findByText('Aucun instrument trouvé.')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Gérer les instruments' }).getAttribute('href')).toBe(
      '/portfolios/instruments',
    );
  });
  it('refetches and resets the active option when its currency changes', async () => {
    const usd = {
      ...instrument,
      id: '00000000-0000-7000-8000-000000000002',
      name: 'USD fund',
      currencyCode: 'USD',
    };
    api.listInstruments.mockImplementation((options: { query: { currencyCode: string } }) =>
      Promise.resolve({
        data: { items: options.query.currencyCode === 'USD' ? [usd] : [instrument, usd] },
        response: new Response('{}', { status: 200 }),
      }),
    );
    const query = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const onChange = vi.fn();
    const { rerender } = render(
      <QueryClientProvider client={query}>
        <InstrumentPicker currencyCode="EUR" label="Instrument" onChange={onChange} />
      </QueryClientProvider>,
    );
    const picker = screen.getByRole('combobox');
    fireEvent.focus(picker);
    await screen.findByRole('option', { name: /ETF Monde/ });
    fireEvent.keyDown(picker, { key: 'ArrowDown' });
    rerender(
      <QueryClientProvider client={query}>
        <InstrumentPicker currencyCode="USD" label="Instrument" onChange={onChange} />
      </QueryClientProvider>,
    );
    await screen.findByRole('option', { name: /USD fund/ });
    expect(picker.getAttribute('aria-activedescendant')).toContain(
      '00000000-0000-7000-8000-000000000002',
    );
    await waitFor(() => expect(api.listInstruments).toHaveBeenCalledTimes(2));
  });
  it('exposes a picker request error and retries it', async () => {
    api.listInstruments.mockRejectedValueOnce(new TypeError('offline')).mockResolvedValueOnce({
      data: { items: [] },
      response: new Response('{}', { status: 200 }),
    });
    renderPicker();
    fireEvent.focus(screen.getByRole('combobox'));
    expect((await screen.findByRole('alert')).textContent).toContain(
      'La recherche est indisponible',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    await waitFor(() => expect(api.listInstruments).toHaveBeenCalledTimes(2));
  });
  it('sends the currency to the API and does not filter the response client-side', async () => {
    api.listInstruments.mockResolvedValue({
      data: { items: [{ ...instrument, currencyCode: 'USD' }] },
      response: new Response('{}', { status: 200 }),
    });
    renderPicker();
    fireEvent.focus(screen.getByRole('combobox'));
    expect(await screen.findByRole('option')).toBeTruthy();
    expect(api.listInstruments).toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ currencyCode: 'EUR' }) }),
    );
  });
  it('closes only the list on Escape inside a modal', async () => {
    api.listInstruments.mockResolvedValue({
      data: { items: [instrument] },
      response: new Response('{}', { status: 200 }),
    });
    const close = vi.fn();
    const query = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={query}>
        <Modal close={close} title="Opération">
          <InstrumentPicker currencyCode="EUR" label="Instrument" onChange={vi.fn()} />
        </Modal>
      </QueryClientProvider>,
    );
    const picker = screen.getByRole('combobox');
    fireEvent.focus(picker);
    await screen.findByRole('option');
    fireEvent.keyDown(picker, { key: 'Escape' });
    expect(picker.getAttribute('aria-expanded')).toBe('false');
    expect(close).not.toHaveBeenCalled();
    fireEvent.keyDown(picker, { key: 'Escape' });
    expect(close).toHaveBeenCalledTimes(1);
  });
  it('labels the combobox from its own label element', () => {
    renderPicker();
    const picker = screen.getByRole('combobox');
    const labelledBy = picker.getAttribute('aria-labelledby');
    expect(labelledBy).toBeTruthy();
    expect(document.getElementById(labelledBy as string)?.textContent).toBe('Instrument');
  });
  it('keeps status messages outside the listbox and announces them politely', async () => {
    api.listInstruments.mockResolvedValue({
      data: { items: [] },
      response: new Response('{}', { status: 200 }),
    });
    renderPicker();
    fireEvent.focus(screen.getByRole('combobox'));
    const message = await screen.findByText('Aucun instrument trouvé.');
    expect(message.closest('[role="listbox"]')).toBeNull();
    expect(message.closest('[aria-live="polite"]')).toBeTruthy();
  });
  it('closes the list on blur but still lets an option click select', async () => {
    api.listInstruments.mockResolvedValue({
      data: { items: [instrument] },
      response: new Response('{}', { status: 200 }),
    });
    const onChange = renderPicker();
    const picker = screen.getByRole('combobox');
    fireEvent.focus(picker);
    const option = await screen.findByRole('option');
    fireEvent.mouseDown(option);
    fireEvent.blur(picker);
    expect(onChange).toHaveBeenLastCalledWith(instrument);
    fireEvent.focus(picker);
    await screen.findByRole('option');
    fireEvent.blur(picker);
    expect(picker.getAttribute('aria-expanded')).toBe('false');
  });
});
