import type { Account, Product } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
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
  isPrimary: false,
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
    basis: null,
    derivation: null,
    reason: null,
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

function renderEditor(editedAccount: Account = account, onSubmit = vi.fn()) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        <AccountEditor
          account={editedAccount}
          onCancel={vi.fn()}
          onSubmit={onSubmit}
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

  it('edits the primary preference and clears it on closure', async () => {
    api.listAssets.mockReturnValue(ok({ items: [{ code: 'EUR', kind: 'FIAT' }] }));
    api.listAccountGroups.mockReturnValue(ok({ items: [] }));
    const submit = vi.fn();
    renderEditor(
      { ...account, kind: 'CURRENT', productCode: null, includeInNetWorth: false },
      submit,
    );
    const preference = await screen.findByRole('checkbox', {
      name: 'Utiliser comme compte principal',
    });
    fireEvent.click(preference);
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(submit.mock.calls[0]?.[0]).toMatchObject({ isPrimary: true });
    fireEvent.change(screen.getByLabelText('Date de clôture'), { target: { value: '2026-01-11' } });
    expect((preference as HTMLInputElement).disabled).toBe(true);
    expect((preference as HTMLInputElement).checked).toBe(false);
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(submit.mock.calls[1]?.[0]).toMatchObject({ isPrimary: false });
    expect(screen.getByText(/Si ce compte est principal/)).toBeTruthy();
  });

  it('hides the primary preference for accounts that are not current accounts', async () => {
    api.listAssets.mockReturnValue(ok({ items: [{ code: 'EUR', kind: 'FIAT' }] }));
    api.listAccountGroups.mockReturnValue(ok({ items: [] }));
    renderEditor({ ...account, kind: 'SAVINGS', productCode: null, includeInNetWorth: false });
    await screen.findByDisplayValue(account.label);
    expect(screen.queryByRole('checkbox', { name: 'Utiliser comme compte principal' })).toBeNull();
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
    const { queryClient } = renderEditor({ ...account, kind: 'CURRENT' });

    expect(screen.getByTestId('account-editor-skeleton')).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(within(screen.getByTestId('account-editor-skeleton')).getByRole('status')).toBeTruthy();
    const frozenPreference = screen
      .getByText('Compte principal')
      .parentElement!.querySelector('input')!;
    const preferenceMarkup = frozenPreference.parentElement?.outerHTML.replace(
      /aria-describedby="[^"]*"/g,
      '',
    );
    resolveProduct?.(await ok(product));
    await waitFor(() => expect(screen.queryByTestId('account-editor-skeleton')).toBeNull());
    expect(screen.getByDisplayValue(account.label)).toBeTruthy();
    expect(screen.queryByTestId('account-editor-skeleton')).toBeNull();
    expect(
      screen
        .getByRole('checkbox', { name: 'Utiliser comme compte principal' })
        .parentElement?.outerHTML.replace(/aria-describedby="[^"]*"/g, ''),
    ).toBe(preferenceMarkup);

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
