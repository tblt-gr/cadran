import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { PortfolioTakeoverModal } from './PortfolioTakeoverModal';

const api = vi.hoisted(() => ({
  previewPortfolioTakeover: vi.fn(),
  recordPortfolioTakeover: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
vi.mock('@/features/portfolio/instrument-picker/InstrumentPicker', () => ({
  InstrumentPicker: (props: { onChange: (item: { id: string; name: string }) => void }) => (
    <button type="button" onClick={() => props.onChange({ id: 'i-1', name: 'World ETF' })}>
      Choisir un instrument
    </button>
  ),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

const account = { id: 'acc-1', label: 'CTO Démo', assetCode: 'EUR' };

function renderModal() {
  const client = new QueryClient();
  const invalidate = vi.spyOn(client, 'invalidateQueries');
  const onClose = vi.fn();
  const onSaved = vi.fn();
  api.previewPortfolioTakeover.mockResolvedValue({
    response: new Response('{}', { status: 200 }),
    data: { lines: [], securitiesValue: null, cash: '0', totalValue: null, errors: [] },
  });
  render(
    <QueryClientProvider client={client}>
      <PortfolioTakeoverModal
        account={account}
        defaultDate="2026-10-31"
        onClose={onClose}
        onSaved={onSaved}
      />
    </QueryClientProvider>,
  );
  return { invalidate, onClose, onSaved };
}

function fillAndSubmit() {
  fireEvent.click(screen.getByRole('button', { name: 'Choisir un instrument' }));
  fireEvent.change(screen.getByLabelText('Quantité'), { target: { value: '5' } });
  fireEvent.change(screen.getByLabelText('Cours'), { target: { value: '101.5' } });
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la reprise' }));
}

describe('PortfolioTakeoverModal', () => {
  it('names the account in the eyebrow and closes at once when nothing was typed', () => {
    const { onClose } = renderModal();
    expect(screen.getByRole('dialog', { name: 'Saisir mes positions' })).toBeTruthy();
    expect(screen.getByText('CTO Démo')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Fermer' }));
    expect(onClose).toHaveBeenCalled();
  });

  it('asks before discarding typed fields, focusing the safe choice', async () => {
    const { onClose } = renderModal();
    fireEvent.change(screen.getByLabelText('Quantité'), { target: { value: '5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Fermer' }));
    expect(onClose).not.toHaveBeenCalled();
    const dialog = screen.getByRole('dialog', { name: 'Abandonner la saisie ?' });
    expect(dialog).toBeTruthy();
    await waitFor(() =>
      expect(document.activeElement).toBe(
        screen.getByRole('button', { name: 'Continuer la saisie' }),
      ),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continuer la saisie' }));
    expect(screen.queryByRole('dialog', { name: 'Abandonner la saisie ?' })).toBeNull();
    expect(onClose).not.toHaveBeenCalled();
    fireEvent.click(screen.getAllByRole('button', { name: 'Fermer' })[0]!);
    fireEvent.click(screen.getByRole('button', { name: 'Abandonner' }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('records with an idempotency key, reports the date and refreshes the account', async () => {
    api.recordPortfolioTakeover.mockResolvedValue({
      response: new Response('{}', { status: 201 }),
      data: { id: 't-1', takenOverOn: '2026-10-31' },
    });
    const { invalidate, onSaved } = renderModal();
    fillAndSubmit();
    await waitFor(() => expect(onSaved).toHaveBeenCalledWith('2026-10-31'));
    const call = api.recordPortfolioTakeover.mock.calls[0]![0];
    expect(call.path).toEqual({ accountId: 'acc-1' });
    expect(call.headers['Idempotency-Key']).toMatch(/[0-9a-f-]{36}/);
    expect(call.body.lines[0]).toMatchObject({
      instrumentId: 'i-1',
      quantity: '5',
      price: '101.5',
    });
    const keys = invalidate.mock.calls.map(([filters]) => filters?.queryKey);
    for (const key of [
      ['portfolio-valuations', 'acc-1'],
      ['portfolio-takeover', 'acc-1'],
      ['accounts'],
      ['net-worth'],
      ['portfolio-summary', 'acc-1'],
      ['account', 'acc-1'],
    ])
      expect(keys).toContainEqual(key);
  });

  it('shows the line a refusal points at and keeps the modal open', async () => {
    api.recordPortfolioTakeover.mockResolvedValue({
      response: new Response('{}', { status: 422 }),
      error: {
        code: 'invalid_portfolio_takeover',
        errors: [{ line: 1, field: 'instrumentId', code: 'instrument_currency_mismatch' }],
      },
    });
    const { onSaved } = renderModal();
    fillAndSubmit();
    expect(
      await screen.findByText('La devise de l’instrument diffère de celle du compte.'),
    ).toBeTruthy();
    expect(onSaved).not.toHaveBeenCalled();
  });

  it('explains a refusal that belongs to the whole account', async () => {
    api.recordPortfolioTakeover.mockResolvedValue({
      response: new Response('{}', { status: 409 }),
      error: { code: 'portfolio_takeover_not_empty', blockingReason: 'ACTIVE_VALUATION' },
    });
    renderModal();
    fillAndSubmit();
    expect(
      await screen.findByText(
        'Ce compte a déjà une valorisation : la reprise n’est plus possible.',
      ),
    ).toBeTruthy();
  });
});
