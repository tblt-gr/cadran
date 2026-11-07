import type { Account, Transfer } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransferEditor } from './TransferEditor';
import type { TransferFormDefaults } from './TransferForm';

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
const transfer = { id: 'transfer' } as Transfer;
const notice = /enregistré automatiquement sur le compte d’investissement/;

function setup(
  target: Account = portfolio,
  accounts = [source, target],
  defaults: TransferFormDefaults = { bookedOn: '2026-03-10', targetAccountId: target.id },
) {
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
        defaults={defaults}
        onSubmit={onSubmit}
        onSaved={onSaved}
        pending={false}
        submitError={null}
      />
    </QueryClientProvider>,
  );
  return { onSubmit, onSaved, client, ...view };
}
function submit() {
  fireEvent.change(screen.getByLabelText('Compte source'), { target: { value: source.id } });
  fireEvent.change(screen.getByLabelText('Montant (EUR)'), { target: { value: '1500.00' } });
  fireEvent.change(screen.getByLabelText('Libellé'), {
    target: { value: 'Investissement mensuel' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Créer le virement' }));
}
describe('TransferEditor into a portfolio', () => {
  it('offers no deposit checkbox: the server records the deposit itself', () => {
    setup();
    expect(screen.queryByRole('checkbox', { name: /versement/i })).toBeNull();
    expect(screen.getByText(notice)).toBeTruthy();
  });
  it.each([
    { kind: 'INSURANCE_CONTRACT' },
    { kind: 'CURRENT' },
    { valuationMode: 'SNAPSHOTS' },
    { status: 'CLOSED' },
    { status: 'ARCHIVED' },
  ])('does not announce an automatic deposit for destination %j', (overrides) => {
    setup({ ...portfolio, ...overrides } as Account);
    expect(screen.queryByText(notice)).toBeNull();
  });
  it('announces the automatic withdrawal when the portfolio is the source', () => {
    setup(source, [portfolio, source]);
    fireEvent.change(screen.getByLabelText('Compte source'), { target: { value: portfolio.id } });
    expect(screen.getByText(notice)).toBeTruthy();
  });
  it('sends one transfer and completes without any portfolio operation request', async () => {
    const { onSubmit, onSaved } = setup();
    submit();
    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
    expect(onSubmit).toHaveBeenCalledOnce();
    expect(onSubmit.mock.calls[0]![0]).toMatchObject({
      sourceAccountId: source.id,
      targetAccountId: portfolio.id,
      bookedOn: '2026-03-10',
    });
  });
  it('still completes when refreshing the reads fails', async () => {
    const { onSaved, client } = setup();
    vi.spyOn(client, 'invalidateQueries').mockRejectedValue(new Error('Read failed'));
    submit();
    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
    expect(screen.queryByRole('alert')).toBeNull();
  });
  it('blocks a second submit while the first one is in flight', async () => {
    let resolve!: (value: Transfer) => void;
    const { onSubmit, onSaved } = setup();
    onSubmit.mockReturnValue(
      new Promise<Transfer>((done) => {
        resolve = done;
      }),
    );
    submit();
    fireEvent.submit(screen.getByLabelText('Libellé').closest('form')!);
    expect(onSubmit).toHaveBeenCalledOnce();
    resolve(transfer);
    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
  });
  it('keeps the form open and silent about the portfolio when the transfer is refused', async () => {
    const { onSubmit, onSaved } = setup();
    onSubmit.mockRejectedValue(new Error('refused'));
    submit();
    await waitFor(() => expect(onSubmit).toHaveBeenCalledOnce());
    expect(onSaved).not.toHaveBeenCalled();
    expect((screen.getByLabelText('Montant (EUR)') as HTMLInputElement).disabled).toBe(false);
  });
  it('refreshes account, budget, net worth and portfolio reads after the transfer', async () => {
    const { client, onSaved } = setup();
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
      'holdings',
    ];
    for (const key of keys) client.setQueryData([key], { cached: true });
    submit();
    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
    for (const key of keys) expect(client.getQueryState([key])?.isInvalidated).toBe(true);
  });
});

describe('TransferEditor with an account source lock', () => {
  it('locks the current account, selects the primary destination and focuses it', async () => {
    const primary = { ...portfolio, isPrimary: true };
    const { onSubmit } = setup(primary, [source, primary], {
      bookedOn: '2026-03-10',
      lockedSourceAccountId: source.id,
    });
    const from = screen.getByLabelText('Compte source') as HTMLSelectElement;
    const to = screen.getByLabelText('Compte de destination') as HTMLSelectElement;
    expect(from.value).toBe(source.id);
    expect(from.disabled).toBe(true);
    expect(from.selectedOptions[0]?.textContent).toBe(source.label);
    expect(to.value).toBe(primary.id);
    expect(to.disabled).toBe(false);
    expect(Array.from(to.options, (option) => option.value)).not.toContain(source.id);
    await waitFor(() => expect(document.activeElement).toBe(to));
    fireEvent.change(screen.getByLabelText('Montant (EUR)'), { target: { value: '25.00' } });
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Épargne' } });
    fireEvent.click(screen.getByRole('button', { name: 'Créer le virement' }));
    await waitFor(() => expect(onSubmit).toHaveBeenCalledOnce());
    expect(onSubmit.mock.calls[0]![0]).toMatchObject({
      sourceAccountId: source.id,
      targetAccountId: primary.id,
      sourceAmount: { value: '25.00', assetCode: 'EUR' },
      targetAmount: null,
    });
  });

  it.each(['current', 'absent', 'inactive'])(
    'leaves the destination empty when the primary is %s',
    (kind) => {
      const current = { ...source, isPrimary: kind === 'current' };
      const other = {
        ...portfolio,
        isPrimary: kind === 'inactive',
        status: kind === 'inactive' ? 'CLOSED' : 'ACTIVE',
      } as Account;
      setup(other, [current, other], { bookedOn: '2026-03-10', lockedSourceAccountId: current.id });
      const destination = screen.getByLabelText('Compte de destination') as HTMLSelectElement;
      expect(destination.value).toBe('');
      if (kind === 'inactive')
        expect(Array.from(destination.options, (option) => option.value)).not.toContain(other.id);
      fireEvent.click(screen.getByRole('button', { name: 'Créer le virement' }));
      expect(destination.getAttribute('aria-invalid')).toBe('true');
      expect(
        document.getElementById(destination.getAttribute('aria-describedby')!)?.textContent,
      ).toBeTruthy();
    },
  );

  it('keeps budget defaults editable and retains the target', () => {
    setup(portfolio, [{ ...source, isPrimary: true }, portfolio]);
    expect((screen.getByLabelText('Compte source') as HTMLSelectElement).disabled).toBe(false);
    expect((screen.getByLabelText('Compte source') as HTMLSelectElement).value).toBe(source.id);
    const destination = screen.getByLabelText('Compte de destination') as HTMLSelectElement;
    expect(destination.disabled).toBe(false);
    expect(destination.value).toBe(portfolio.id);
    expect(Array.from(destination.options, (option) => option.value)).toContain(source.id);
  });
});
