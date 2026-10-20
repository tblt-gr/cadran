import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import {
  act,
  cleanup,
  fireEvent,
  render,
  renderHook,
  screen,
  waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { useTransactionMutations } from '@/features/transactions/useTransactionMutations';
import { useAccountDetailMutations } from './account-detail/useAccountDetailMutations';
import { useRecurrenceMutations } from '@/features/recurrences/useRecurrenceMutations';
import { BudgetCreationModals } from '@/features/budget/monthly-budget/budget-creation-modals/BudgetCreationModals';
import { MovementEditModal } from '@/features/budget/monthly-budget/movement-edit-modal/MovementEditModal';
import { useAccountRules } from './account-rules/useAccountRules';
import { invalidateAccountBalances } from './invalidateAccountBalances';

const api = vi.hoisted(() => ({
  createTransaction: vi.fn(),
  updateTransaction: vi.fn(),
  voidTransaction: vi.fn(),
  createTransfer: vi.fn(),
  duplicateTransaction: vi.fn(),
  confirmRecurrence: vi.fn(),
  updateRecurrence: vi.fn(),
  getTransaction: vi.fn(),
  readAccountRules: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
vi.mock('@/features/auth/withCsrfRetry', () => ({
  withCsrfRetry: (request: () => unknown) => request(),
}));
vi.mock('@/features/budget/monthly-budget/budget-creation-modals/useActiveBudgetAccounts', () => ({
  useActiveBudgetAccounts: () => ({ data: [], isPending: false, isError: false }),
}));
vi.mock('@/features/transactions/transactionAccounts', () => ({
  listReferencedAccounts: async () => [],
  mergeAccountOptions: () => [],
}));
vi.mock('@/features/transactions/transaction-editor/TransactionEditor', () => ({
  TransactionEditor: ({ onSubmit }: { onSubmit: (body: never) => void }) => (
    <button onClick={() => onSubmit({} as never)}>Save transaction</button>
  ),
}));
vi.mock('@/features/transactions/transfer-editor/TransferEditor', () => ({
  TransferEditor: ({ onSubmit }: { onSubmit: (body: never) => void }) => (
    <button onClick={() => void onSubmit({} as never)}>Save transfer</button>
  ),
}));

const keys = [
  'accounts',
  'account',
  'account-rules',
  'net-worth',
  'net-worth-history',
  'monthly-recap',
  'annual-report',
  'multi-year-report',
];
function setup() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  keys.forEach((key) => queryClient.setQueryData([key, 'cached-filter'], {}));
  queryClient.setQueryData(['unrelated'], {});
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return { queryClient, wrapper };
}
function expectBalancesInvalidated(client: QueryClient) {
  for (const key of keys)
    expect(client.getQueryState([key, 'cached-filter'])?.isInvalidated, key).toBe(true);
  expect(client.getQueryState(['unrelated'])?.isInvalidated).toBe(false);
}
const reload = vi.fn(async () => undefined);
const transaction = { id: 'transaction', version: 1 } as never;

beforeEach(() => {
  for (const mock of Object.values(api))
    mock.mockResolvedValue({ response: { ok: true }, data: {} });
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('balance query invalidation after movement writes', () => {
  it('invalidates every account/report prefix, including filtered historical reads', async () => {
    const { queryClient } = setup();
    await invalidateAccountBalances(queryClient);
    expectBalancesInvalidated(queryClient);
  });

  it.each(['create', 'update', 'transfer', 'void', 'duplicate'] as const)(
    'transactions: %s',
    async (operation) => {
      const { queryClient, wrapper } = setup();
      const { result } = renderHook(
        () =>
          useTransactionMutations({
            editor: operation === 'update' ? transaction : 'create',
            onSaved: vi.fn(),
            onTransferSaved: vi.fn(),
            onVoided: vi.fn(),
            reloadAfterWrite: reload,
          }),
        { wrapper },
      );
      await act(async () => {
        if (operation === 'transfer') await result.current.transferSave.mutateAsync({} as never);
        else if (operation === 'void') await result.current.voidMutation.mutateAsync(transaction);
        else if (operation === 'duplicate') await result.current.duplicate.mutateAsync(transaction);
        else await result.current.save.mutateAsync({} as never);
      });
      expectBalancesInvalidated(queryClient);
      expect(reload).toHaveBeenCalled();
    },
  );

  it.each(['transaction', 'transfer', 'complete transfer'] as const)(
    'account detail: %s',
    async (operation) => {
      const { queryClient, wrapper } = setup();
      const { result } = renderHook(
        () =>
          useAccountDetailMutations({
            accountId: 'account',
            onTransactionSaved: vi.fn(),
            onTransferSaved: vi.fn(),
            reloadAfterWrite: reload,
          }),
        { wrapper },
      );
      await act(async () => {
        if (operation === 'transaction')
          await result.current.saveTransaction.mutateAsync({} as never);
        else if (operation === 'transfer')
          await result.current.saveTransfer.mutateAsync({} as never);
        else await result.current.completeTransfer();
      });
      expectBalancesInvalidated(queryClient);
    },
  );

  it.each(['confirm', 'edit'] as const)('recurrences: %s', async (operation) => {
    const { queryClient, wrapper } = setup();
    const { result } = renderHook(
      () =>
        useRecurrenceMutations({
          editor: operation === 'edit' ? transaction : 'create',
          onArchived: vi.fn(),
          onRestored: vi.fn(),
          onSaved: vi.fn(),
        }),
      { wrapper },
    );
    await act(async () => {
      await result.current.save.mutateAsync({} as never);
    });
    if (operation === 'confirm') expectBalancesInvalidated(queryClient);
    else
      for (const key of keys)
        expect(queryClient.getQueryState([key, 'cached-filter'])?.isInvalidated).toBe(false);
  });

  it.each(['transaction', 'transfer'] as const)('budget creation: %s', async (operation) => {
    const { queryClient, wrapper } = setup();
    render(
      <BudgetCreationModals
        close={vi.fn()}
        draft={
          operation === 'transfer'
            ? { kind: 'account', row: { id: 'account' } as never }
            : { kind: 'expense', row: null }
        }
        month="2026-10"
        today="2026-10-10"
        onSaved={reload}
      />,
      { wrapper },
    );
    fireEvent.click(
      screen.getByText(operation === 'transfer' ? 'Save transfer' : 'Save transaction'),
    );
    await waitFor(() => expectBalancesInvalidated(queryClient));
  });

  it('budget movement edit invalidates balances after saving', async () => {
    const { queryClient, wrapper } = setup();
    api.getTransaction.mockResolvedValue({
      response: { ok: true },
      data: { accountId: 'account' },
    });
    render(<MovementEditModal close={vi.fn()} onSaved={reload} transactionId="transaction" />, {
      wrapper,
    });
    fireEvent.click(await screen.findByText('Save transaction'));
    await waitFor(() => expectBalancesInvalidated(queryClient));
  });

  it('refetches active dated account rules after an expense changes their measured balance', async () => {
    const { wrapper } = setup();
    const rules = (measured: string, interest: string) => ({
      accountId: 'account',
      asOf: '2026-10-10',
      assetCode: 'EUR',
      ceilings: [{ check: { measured: { value: measured, assetCode: 'EUR' } } }],
      rates: [{ applied: { interest } }],
    });
    api.readAccountRules
      .mockResolvedValueOnce({ response: { ok: true }, data: rules('1000', '20') })
      .mockResolvedValue({ response: { ok: true }, data: rules('900', '18') });
    const { result } = renderHook(
      () => ({
        rules: useAccountRules('account', '2026-10-10'),
        mutations: useTransactionMutations({
          editor: 'create',
          onSaved: vi.fn(),
          onTransferSaved: vi.fn(),
          onVoided: vi.fn(),
          reloadAfterWrite: reload,
        }),
      }),
      { wrapper },
    );
    await waitFor(() => expect(result.current.rules.data?.rates[0]?.applied?.interest).toBe('20'));
    expect(result.current.rules.data?.ceilings[0]?.check.measured?.value).toBe('1000');
    await act(async () => {
      await result.current.mutations.save.mutateAsync({
        accountId: 'account',
        amount: { value: '-100', assetCode: 'EUR' },
        bookedOn: '2026-10-10',
        nature: 'EXPENSE',
        state: 'BOOKED',
      } as never);
    });
    await waitFor(() => expect(result.current.rules.data?.rates[0]?.applied?.interest).toBe('18'));
    expect(result.current.rules.data?.ceilings[0]?.check.measured?.value).toBe('900');
    expect(api.readAccountRules).toHaveBeenCalledTimes(2);
    expect(api.readAccountRules.mock.calls[1]?.[0].query).toEqual({ asOf: '2026-10-10' });
  });

  it('does not invalidate balances when the transaction write fails', async () => {
    const { queryClient, wrapper } = setup();
    api.createTransaction.mockResolvedValue({ response: { ok: false, status: 422 } });
    const { result } = renderHook(
      () =>
        useTransactionMutations({
          editor: 'create',
          onSaved: vi.fn(),
          onTransferSaved: vi.fn(),
          onVoided: vi.fn(),
          reloadAfterWrite: reload,
        }),
      { wrapper },
    );
    await act(async () => {
      await expect(result.current.save.mutateAsync({} as never)).rejects.toThrow();
    });
    for (const key of keys)
      expect(queryClient.getQueryState([key, 'cached-filter'])?.isInvalidated).toBe(false);
  });
});
