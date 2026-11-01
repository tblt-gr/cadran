import type { Account, Transfer } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransferEditor } from './TransferEditor';

const api = vi.hoisted(() => ({ recordPortfolioOperation: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
afterEach(() => {
  cleanup();
  vi.resetAllMocks();
});
const source = {
  id: 'source',
  label: 'Banque',
  assetCode: 'EUR',
  openedOn: '2026-01-01',
  status: 'ACTIVE',
  kind: 'CURRENT',
  valuationMode: 'TRANSACTIONS',
} as Account;
const portfolio = {
  ...source,
  id: 'portfolio',
  label: 'Titres',
  kind: 'PORTFOLIO',
  valuationMode: 'PORTFOLIO',
} as Account;
const transfer = {
  target: {
    id: 'incoming',
    accountId: portfolio.id,
    bookedOn: '2026-03-10',
    amount: { value: '12345678901234567890123456.123456789012345678901234', assetCode: 'EUR' },
  },
  source: {
    id: 'outgoing',
    bookedOn: '2026-03-09',
    amount: { value: '-1502.50', assetCode: 'EUR' },
  },
  fee: { amount: { value: '-2.50', assetCode: 'EUR' } },
} as Transfer;
const label = 'Créer le versement sur le compte de réception';
function setup(target: Account = portfolio, accounts = [source, target]) {
  const onSubmit = vi.fn().mockResolvedValue(transfer);
  const onSaved = vi.fn();
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const view = render(
    <QueryClientProvider client={client}>
      <TransferEditor
        accounts={accounts}
        close={vi.fn()}
        onSubmit={onSubmit}
        onSaved={onSaved}
        pending={false}
        submitError={null}
      />
    </QueryClientProvider>,
  );
  return { onSubmit, onSaved, client, ...view };
}
function submit(declined = false) {
  fireEvent.change(screen.getByLabelText('Montant (EUR)'), { target: { value: '1500.00' } });
  fireEvent.change(screen.getByLabelText('Libellé'), {
    target: { value: 'Investissement mensuel' },
  });
  if (declined) fireEvent.click(screen.getByLabelText(label));
  fireEvent.click(screen.getByRole('button', { name: 'Créer le virement' }));
}
describe('TransferEditor linked deposit', () => {
  it.each([
    { kind: 'INSURANCE_CONTRACT' },
    { kind: 'CURRENT' },
    { valuationMode: 'SNAPSHOTS' },
    { status: 'CLOSED' },
    { status: 'ARCHIVED' },
    { assetCode: 'CHF' },
  ])('hides consent for an ineligible destination %j', (overrides) => {
    setup({ ...portfolio, ...overrides } as Account);
    expect(screen.queryByLabelText(label)).toBeNull();
  });
  it('defaults on and never records a declined deposit', async () => {
    const { onSaved } = setup();
    expect((screen.getByLabelText(label) as HTMLInputElement).checked).toBe(true);
    submit(true);
    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
    expect(api.recordPortfolioOperation).not.toHaveBeenCalled();
  });
  it('never infers consent from a failed refresh after an unchecked transfer', async () => {
    const { onSaved, client } = setup();
    vi.spyOn(client, 'invalidateQueries').mockRejectedValue(new Error('Read failed'));
    submit(true);
    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
    expect(api.recordPortfolioOperation).not.toHaveBeenCalled();
  });
  it('does not retry a successful deposit when refreshing reads fails', async () => {
    api.recordPortfolioOperation.mockResolvedValue({ response: { ok: true }, data: {} });
    const { onSaved, client } = setup();
    vi.spyOn(client, 'invalidateQueries').mockRejectedValue(new Error('Read failed'));
    submit();
    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
    expect(api.recordPortfolioOperation).toHaveBeenCalledOnce();
    expect(screen.queryByRole('alert')).toBeNull();
  });
  it('uses only exact incoming values and waits for the deposit before completion', async () => {
    let resolve!: (value: unknown) => void;
    api.recordPortfolioOperation.mockReturnValue(
      new Promise((done) => {
        resolve = done;
      }),
    );
    const { onSaved } = setup();
    submit();
    await waitFor(() => expect(api.recordPortfolioOperation).toHaveBeenCalledOnce());
    expect(onSaved).not.toHaveBeenCalled();
    expect(api.recordPortfolioOperation).toHaveBeenCalledWith(
      expect.objectContaining({
        path: { accountId: portfolio.id },
        body: {
          type: 'DEPOSIT',
          tradeDate: transfer.target.bookedOn,
          grossAmount: transfer.target.amount,
          linkedTransactionId: 'incoming',
        },
      }),
    );
    resolve({ response: { ok: true }, data: {} });
    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
  });
  it.each([
    [409, { type: '/problems/period-closed' }, 'Le mois concerné est clôturé'],
    [409, { code: 'linked_transfer_taken' }, 'Ce virement est déjà lié'],
    [422, { code: 'invalid_operation_amount' }, 'Le montant est invalide'],
  ])(
    'keeps the transfer after deposit refusal %s %j and retries only the deposit',
    async (status, error, text) => {
      api.recordPortfolioOperation
        .mockResolvedValueOnce({ response: { ok: false, status }, error })
        .mockResolvedValueOnce({ response: { ok: true }, data: {} });
      const { onSubmit, onSaved } = setup();
      submit();
      await waitFor(() => expect(screen.getByRole('alert').textContent).toContain(text));
      expect(onSaved).not.toHaveBeenCalled();
      expect((screen.getByLabelText('Montant (EUR)') as HTMLInputElement).disabled).toBe(true);
      fireEvent.click(screen.getByRole('button', { name: 'Réessayer le versement' }));
      await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
      expect(onSubmit).toHaveBeenCalledOnce();
    },
  );
  it('retries the retained incoming leg even if refreshed account options disappear', async () => {
    api.recordPortfolioOperation
      .mockResolvedValueOnce({
        response: { ok: false, status: 409 },
        error: { code: 'portfolio_account_inactive' },
      })
      .mockResolvedValueOnce({ response: { ok: true }, data: {} });
    const { client, onSubmit, onSaved, rerender } = setup();
    submit();
    await screen.findByRole('alert');
    rerender(
      <QueryClientProvider client={client}>
        <TransferEditor
          accounts={[]}
          close={vi.fn()}
          onSubmit={onSubmit}
          onSaved={onSaved}
          pending={false}
          submitError={null}
        />
      </QueryClientProvider>,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer le versement' }));
    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
    expect(onSubmit).toHaveBeenCalledOnce();
    expect(api.recordPortfolioOperation).toHaveBeenCalledTimes(2);
  });
  it('reuses deposit idempotency after a lost response and blocks duplicate submits', async () => {
    api.recordPortfolioOperation
      .mockRejectedValueOnce(new TypeError('Failed to fetch'))
      .mockResolvedValueOnce({ response: { ok: true }, data: {} });
    const { onSubmit, onSaved } = setup();
    submit();
    fireEvent.submit(screen.getByLabelText('Libellé').closest('form')!);
    await screen.findByRole('button', { name: 'Réessayer le versement' });
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer le versement' }));
    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
    expect(onSubmit).toHaveBeenCalledOnce();
    expect(api.recordPortfolioOperation.mock.calls[0]![0].headers['Idempotency-Key']).toBe(
      api.recordPortfolioOperation.mock.calls[1]![0].headers['Idempotency-Key'],
    );
  });
  it('starts checked again when the editor is reopened', () => {
    const first = setup();
    fireEvent.click(screen.getByLabelText(label));
    expect((screen.getByLabelText(label) as HTMLInputElement).checked).toBe(false);
    first.unmount();
    setup();
    expect((screen.getByLabelText(label) as HTMLInputElement).checked).toBe(true);
  });
  it('refreshes account, budget, net worth and portfolio reads after partial success', async () => {
    api.recordPortfolioOperation.mockResolvedValue({
      response: { ok: false, status: 409 },
      error: { code: 'linked_transfer_taken' },
    });
    const { client } = setup();
    const keys = [
      'transactions',
      'account',
      'accounts',
      'monthly-ledger',
      'monthly-recap',
      'net-worth',
      'portfolio-cash',
      'portfolio-operations',
      'portfolio-summary',
    ];
    for (const key of keys) client.setQueryData([key], { cached: true });
    submit();
    await screen.findByRole('alert');
    for (const key of keys) expect(client.getQueryState([key])?.isInvalidated).toBe(true);
  });
  it('restores the default consent when destination changes or source loses eligibility', () => {
    const other = { ...portfolio, id: 'other', label: 'PEA' };
    setup(portfolio, [source, portfolio, other, { ...source, id: 'chf', assetCode: 'CHF' }]);
    const checkbox = screen.getByLabelText(label) as HTMLInputElement;
    checkbox.focus();
    expect(document.activeElement).toBe(checkbox);
    fireEvent.click(checkbox);
    fireEvent.change(screen.getByLabelText('Compte de destination'), {
      target: { value: other.id },
    });
    expect((screen.getByLabelText(label) as HTMLInputElement).checked).toBe(true);
    fireEvent.click(screen.getByLabelText(label));
    fireEvent.change(screen.getByLabelText('Compte source'), { target: { value: 'chf' } });
    expect(screen.queryByLabelText(label)).toBeNull();
    fireEvent.change(screen.getByLabelText('Compte source'), { target: { value: source.id } });
    expect((screen.getByLabelText(label) as HTMLInputElement).checked).toBe(true);
  });
});
