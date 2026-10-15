import type { Account, Product } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountEditor } from './AccountEditor';

const api = vi.hoisted(() => ({
  listAccountGroups: vi.fn(),
  listAssets: vi.fn(),
  readProduct: vi.fn(),
  readProductModel: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const account = {
  id: '00000000-0000-7000-8000-000000000001',
  label: 'Livret A',
  assetCode: 'EUR',
  kind: 'SAVINGS',
  productCode: 'FR_LIVRET_A',
  productModelId: null,
  institution: null,
  maskedIdentifier: null,
  valuationMode: 'TRANSACTIONS',
  liquidityLevel: 'IMMEDIATE',
  includeInNetWorth: true,
  includeInEmergencyFund: true,
  openedOn: '2026-01-10',
  closedOn: null,
  status: 'ACTIVE',
  netWorthSign: 1,
  used: false,
  editable: true,
  kindEditable: true,
  kindEditReason: null,
  version: 1,
  archivedAt: null,
  primaryGroupId: null,
  tagGroupIds: [],
  share: { ratio: null, percent: null, percentDisplay: null, reason: 'MISSING_VALUATION' },
  valuation: {
    accountId: '00000000-0000-7000-8000-000000000001',
    requestedOn: '2026-01-10',
    asOf: null,
    amount: null,
    display: null,
    belowDisplayStep: false,
    source: null,
    ageDays: null,
    quality: 'MISSING',
    reconciliationStatus: null,
    snapshotId: null,
    version: null,
  },
} satisfies Account;

const product = {
  code: 'FR_LIVRET_A',
  displayName: 'Livret A',
  accountKind: 'SAVINGS',
  valuationMode: 'TRANSACTIONS',
  capabilities: ['SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS'],
  rules: [],
} as unknown as Product;

function ok<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function renderEditor() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        <AccountEditor
          account={account}
          onCancel={vi.fn()}
          onSubmit={vi.fn()}
          pending={false}
          submitError={null}
        />
      </QueryClientProvider>,
    ),
    queryClient,
  };
}

describe('AccountEditor', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('replaces its one named cold-load skeleton and keeps the form during a refetch', async () => {
    api.listAssets.mockReturnValue(ok({ items: [{ code: 'EUR', kind: 'FIAT' }] }));
    api.listAccountGroups.mockReturnValue(ok({ items: [] }));
    let resolveProduct: ((value: unknown) => void) | undefined;
    api.readProduct.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveProduct = resolve;
        }),
    );
    const { queryClient } = renderEditor();

    expect(screen.getByTestId('account-editor-skeleton')).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(within(screen.getByTestId('account-editor-skeleton')).getByRole('status')).toBeTruthy();
    resolveProduct?.(await ok(product));
    await waitFor(() => expect(screen.queryByTestId('account-editor-skeleton')).toBeNull());
    expect(screen.getByDisplayValue(account.label)).toBeTruthy();
    expect(screen.queryByTestId('account-editor-skeleton')).toBeNull();

    api.readProduct.mockImplementation(() => new Promise(() => undefined));
    void queryClient.invalidateQueries({ queryKey: ['product', account.productCode] });
    await waitFor(() => expect(api.readProduct).toHaveBeenCalledTimes(2));
    expect(screen.getByDisplayValue(account.label)).toBeTruthy();
    expect(screen.queryByTestId('account-editor-skeleton')).toBeNull();
  });

  it('replaces the skeleton with the existing error state and form after a failed read', async () => {
    api.listAssets.mockReturnValue(ok({ items: [{ code: 'EUR', kind: 'FIAT' }] }));
    api.listAccountGroups.mockReturnValue(ok({ items: [] }));
    api.readProduct.mockResolvedValue({
      data: undefined,
      response: new Response('{}', { status: 500 }),
    });
    renderEditor();

    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByDisplayValue(account.label)).toBeTruthy();
    expect(screen.queryByTestId('account-editor-skeleton')).toBeNull();
  });
});
