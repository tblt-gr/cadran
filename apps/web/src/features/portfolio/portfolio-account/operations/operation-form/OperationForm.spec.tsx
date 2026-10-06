import type { PortfolioOperation } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
import { OperationForm } from './OperationForm';
import { operationFixture } from '@/features/portfolio/portfolio-account/operations/operations-table/operationFixtures';

const api = vi.hoisted(() => ({
  listInstruments: vi.fn(),
  listPortfolioTransferCandidates: vi.fn(),
  previewPortfolioOperation: vi.fn(),
  listPortfolioPositions: vi.fn(),
  readInstrument: vi.fn(),
}));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const account = { id: 'acc-1', assetCode: 'EUR' };
function ok<T>(data: T) {
  return Promise.resolve({ data, response: new Response('{}', { status: 200 }) });
}
function renderForm(
  props: Partial<React.ComponentProps<typeof OperationForm>> = {},
  configurePreview?: () => void,
) {
  const onSubmit = vi.fn();
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  api.listInstruments.mockImplementation(() =>
    ok({
      items: [{ id: 'ins-1', name: 'ETF Monde', isin: 'FR0000120271', ticker: 'CW8' }],
      page: 1,
      perPage: 20,
      total: 1,
    }),
  );
  api.listPortfolioTransferCandidates.mockImplementation(() =>
    ok({
      items: [
        {
          transactionId: 'tx-1',
          bookedOn: '2026-02-15',
          amount: { value: '500', assetCode: 'EUR' },
          label: 'Virement mensuel',
        },
      ],
    }),
  );
  api.previewPortfolioOperation.mockImplementation(() =>
    ok({
      cashAmount: { value: '-2307.68', assetCode: 'EUR' },
      roundingResidual: { value: '0.002', assetCode: 'EUR' },
      expectedGrossAmount: null,
    }),
  );
  configurePreview?.();
  render(
    <QueryClientProvider client={client}>
      <OperationForm
        account={account}
        onSubmit={onSubmit}
        pending={false}
        today="2026-03-10"
        {...props}
      />
    </QueryClientProvider>,
  );
  return { onSubmit };
}
const change = (label: string | RegExp, value: string) =>
  fireEvent.change(screen.getByLabelText(label), { target: { value } });

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('OperationForm', () => {
  it('previews a corrected surrender against the original id without double-counting it', async () => {
    // Server fixture: premium 100, then surrender 60; replacing the same 60 leaves cash 40.
    const originalId = '00000000-0000-7000-8000-000000000060';
    renderForm(
      {
        account: { ...account, kind: 'INSURANCE_CONTRACT' },
        operation: operationFixture({
          id: originalId,
          type: 'PARTIAL_SURRENDER',
          grossAmount: { value: '60', assetCode: 'EUR' },
        }),
      },
      () =>
        api.previewPortfolioOperation.mockImplementation((options) =>
          options.query?.correctingOperationId === originalId
            ? ok({
                cashAmount: { value: '-60', assetCode: 'EUR' },
                netPaidAmount: { value: '60', assetCode: 'EUR' },
                legs: [],
                roundingResidual: null,
                expectedGrossAmount: null,
              })
            : Promise.resolve({
                error: { code: 'surrender_exceeds_available' },
                response: new Response('{}', { status: 422 }),
              }),
        ),
    );
    await waitFor(() =>
      expect(api.previewPortfolioOperation.mock.calls[0]![0].query).toEqual({
        correctingOperationId: originalId,
      }),
    );
    expect(await screen.findByText('60 EUR')).toBeTruthy();
    expect(
      screen.queryByText('Le rachat dépasse les liquidités et les supports vendus.'),
    ).toBeNull();
    expect(api.previewPortfolioOperation.mock.calls[0]![0].body).toEqual({
      type: 'PARTIAL_SURRENDER',
      tradeDate: '2026-03-01',
      grossAmount: { value: '60', assetCode: 'EUR' },
      legs: [],
    });
    change('Montant brut du rachat', '61');
    await waitFor(
      () =>
        expect(api.previewPortfolioOperation.mock.calls.at(-1)![0].body.grossAmount.value).toBe(
          '61',
        ),
      { timeout: 2000 },
    );
    expect(api.previewPortfolioOperation.mock.calls.at(-1)![0].query.correctingOperationId).toBe(
      originalId,
    );
  });

  it('omits the correction query and keeps the create body unchanged', async () => {
    renderForm({ account: { ...account, kind: 'INSURANCE_CONTRACT' } });
    change('Type', 'PARTIAL_SURRENDER');
    change('Montant brut du rachat', '60');
    await waitFor(() => expect(api.previewPortfolioOperation).toHaveBeenCalled(), {
      timeout: 2000,
    });
    const options = api.previewPortfolioOperation.mock.calls.at(-1)![0];
    expect(options).not.toHaveProperty('query');
    expect(options.body).toEqual({
      type: 'PARTIAL_SURRENDER',
      tradeDate: '2026-03-10',
      grossAmount: { value: '60', assetCode: 'EUR' },
      legs: [],
    });
  });
  it('offers only allowed operations on a life-insurance contract', () => {
    renderForm({ account: { ...account, kind: 'INSURANCE_CONTRACT' } });
    expect((screen.getByLabelText('Type') as HTMLSelectElement).value).toBe('PREMIUM');
    const values = [...(screen.getByLabelText('Type') as HTMLSelectElement).options].map(
      (option) => option.value,
    );
    expect(values).toContain('BUY');
    expect(values).toContain('PREMIUM');
    expect(values).toContain('FULL_SURRENDER');
    for (const type of ['DEPOSIT', 'WITHDRAWAL', 'SECURITY_TRANSFER_IN', 'SECURITY_TRANSFER_OUT'])
      expect(values).not.toContain(type);
  });

  it('copies positions for a full surrender and shows the server expected gross', async () => {
    api.listPortfolioPositions.mockResolvedValue(
      ok({ items: [{ instrument: { id: 'euro', name: 'Fonds euros' }, quantity: '6000.00' }] }),
    );
    api.readInstrument.mockResolvedValue(
      ok({ id: 'euro', name: 'Fonds euros', instrumentType: 'EURO_FUND' }),
    );
    const { onSubmit } = renderForm({ account: { ...account, kind: 'INSURANCE_CONTRACT' } });
    api.previewPortfolioOperation.mockImplementation(() =>
      ok({
        cashAmount: { value: '0', assetCode: 'EUR' },
        netPaidAmount: { value: '6000.00', assetCode: 'EUR' },
        expectedGrossAmount: { value: '6000.00', assetCode: 'EUR' },
        legs: [],
        roundingResidual: null,
      }),
    );
    change('Type', 'FULL_SURRENDER');
    await waitFor(() =>
      expect(
        (
          screen.getByRole('button', {
            name: 'Reprendre toutes les positions',
          }) as HTMLButtonElement
        ).disabled,
      ).toBe(false),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Reprendre toutes les positions' }));
    expect((screen.getByLabelText('Montant 1') as HTMLInputElement).value).toBe('6000.00');
    expect(screen.queryByLabelText('Parts 1')).toBeNull();
    change('Montant brut du rachat', '6000.00');
    await waitFor(() => expect(screen.getAllByText(/6.000,00/).length).toBe(2), { timeout: 2000 });
    expect(screen.getByText('Montant brut attendu')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(onSubmit.mock.calls[0]![0].legs).toEqual([
      { direction: 'OUT', instrumentId: 'euro', amount: { value: '6000.00', assetCode: 'EUR' } },
    ]);
  });

  it('offers no insurance-only types on a standard portfolio', () => {
    renderForm();
    const options = [...(screen.getByLabelText('Type') as HTMLSelectElement).options].map(
      (option) => option.value,
    );
    expect(options).not.toContain('PREMIUM');
    expect(options).not.toContain('FULL_SURRENDER');
  });

  it('shows the server net paid for the reference surrender', async () => {
    renderForm({ account: { ...account, kind: 'INSURANCE_CONTRACT' } });
    api.previewPortfolioOperation.mockImplementation(() =>
      ok({
        cashAmount: { value: '0', assetCode: 'EUR' },
        netPaidAmount: { value: '1033.90', assetCode: 'EUR' },
        expectedGrossAmount: null,
        legs: [],
        roundingResidual: null,
      }),
    );
    change('Type', 'PARTIAL_SURRENDER');
    change('Montant brut du rachat', '1050.00');
    change('Prélèvement fiscal retenu', '7.50');
    change('Prélèvements sociaux retenus', '8.60');
    expect(await screen.findByText(/1.033,90/, undefined, { timeout: 2000 })).toBeTruthy();
    expect(screen.getByText('Net versé')).toBeTruthy();
    expect(screen.queryByLabelText('Taxes')).toBeNull();
  });

  it('focuses the type first and renders only the fields of the chosen type', () => {
    renderForm();
    expect(screen.getByLabelText('Type').hasAttribute('data-autofocus')).toBe(true);
    expect(screen.getByLabelText('Montant brut')).toBeTruthy();
    expect(screen.queryByLabelText('Quantité')).toBeNull();
    change('Type', 'BUY');
    for (const label of [
      'Instrument',
      'Quantité',
      'Prix unitaire',
      'Montant brut',
      'Frais',
      'Taxes',
    ]) {
      expect(screen.getByLabelText(label)).toBeTruthy();
    }
    expect(screen.queryByLabelText('Virement lié')).toBeNull();
  });

  it('shows the server cash effect and rounding residual for a buy', async () => {
    renderForm();
    change('Type', 'BUY');
    fireEvent.focus(screen.getByLabelText('Instrument'));
    fireEvent.mouseDown(await screen.findByRole('option', { name: /ETF Monde/ }));
    change('Quantité', '10');
    change('Prix unitaire', '230.5688');
    change('Montant brut', '2305.69');
    change('Frais', '1.99');
    const effect = await screen.findByText(/2.307,68/, undefined, { timeout: 2000 });
    expect(effect.textContent).toContain('−');
    expect(screen.getByText(/Écart d’arrondi/)).toBeTruthy();
    expect(api.previewPortfolioOperation).toHaveBeenCalled();
    const body = api.previewPortfolioOperation.mock.calls.at(-1)![0].body;
    expect(body).not.toHaveProperty('cashAmount');
    expect(body.unitPrice).toBe('230.5688');
  });

  it('prefills date and amount from a linked transfer candidate', async () => {
    renderForm();
    const select = await screen.findByLabelText('Virement lié');
    await waitFor(() =>
      expect(screen.getByRole('option', { name: /Virement mensuel/ })).toBeTruthy(),
    );
    fireEvent.change(select, { target: { value: 'tx-1' } });
    expect((screen.getByLabelText('Date d’opération') as HTMLInputElement).value).toBe(
      '2026-02-15',
    );
    expect((screen.getByLabelText('Montant brut') as HTMLInputElement).value).toBe('500');
  });

  it('submits the typed request and a null reason', () => {
    const { onSubmit } = renderForm();
    change('Montant brut', '1000');
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(onSubmit).toHaveBeenCalledWith(
      {
        type: 'DEPOSIT',
        tradeDate: '2026-03-10',
        grossAmount: { value: '1000', assetCode: 'EUR' },
      },
      null,
    );
  });

  it('shows required-field errors and does not submit', () => {
    const { onSubmit } = renderForm();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(onSubmit).not.toHaveBeenCalled();
    expect(screen.getByText('Ce champ est obligatoire.')).toBeTruthy();
  });

  it('prefills every stored field when modifying and offers an optional reason', () => {
    const operation = {
      id: 'op-1',
      accountId: 'acc-1',
      type: 'DEPOSIT',
      tradeDate: '2026-03-01',
      settlementDate: null,
      sequence: 1,
      instrument: null,
      counterInstrument: null,
      quantity: null,
      unitPrice: null,
      grossAmount: { value: '250.50', assetCode: 'EUR' },
      fees: { value: '0', assetCode: 'EUR' },
      taxes: { value: '0', assetCode: 'EUR' },
      cashAmount: { value: '250.50', assetCode: 'EUR' },
      costBasis: null,
      counterQuantity: null,
      ratio: null,
      costAllocationRatio: null,
      roundingResidual: null,
      reference: 'REF-1',
      note: null,
      linkedTransactionId: null,
      state: 'ACTIVE',
      voidedAt: null,
      voidReason: null,
      voidKind: null,
      correctsOperationId: null,
      correctedByOperationId: null,
      version: 3,
    } as PortfolioOperation;
    const { onSubmit } = renderForm({ operation });
    expect((screen.getByLabelText('Montant brut') as HTMLInputElement).value).toBe('250.50');
    expect((screen.getByLabelText('Référence') as HTMLInputElement).value).toBe('REF-1');
    change('Motif (facultatif)', 'Montant mal saisi');
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(onSubmit.mock.calls[0]![1]).toBe('Montant mal saisi');
  });

  it('shows the server field errors next to the field', () => {
    renderForm({ fieldErrors: { grossAmount: 'Montant attendu : 2305.688.' } });
    expect(screen.getByText('Montant attendu : 2305.688.')).toBeTruthy();
  });

  it('resolves a negative-position refusal against the original edited instrument', () => {
    const originalInstrument = {
      id: 'original-instrument',
      name: 'Action d’origine',
      isin: null,
      ticker: 'OLD',
      status: 'ACTIVE' as const,
    };
    const operation = {
      id: 'op-1',
      accountId: 'acc-1',
      type: 'BUY',
      tradeDate: '2026-03-01',
      settlementDate: null,
      sequence: 1,
      instrument: originalInstrument,
      counterInstrument: null,
      quantity: '1',
      unitPrice: '10',
      grossAmount: { value: '10', assetCode: 'EUR' },
      fees: { value: '0', assetCode: 'EUR' },
      taxes: { value: '0', assetCode: 'EUR' },
      cashAmount: { value: '-10', assetCode: 'EUR' },
      costBasis: null,
      counterQuantity: null,
      ratio: null,
      costAllocationRatio: null,
      roundingResidual: null,
      reference: null,
      note: null,
      linkedTransactionId: null,
      state: 'ACTIVE',
      voidedAt: null,
      voidReason: null,
      voidKind: null,
      correctsOperationId: null,
      correctedByOperationId: null,
      version: 1,
    } as PortfolioOperation;
    const saveError = operationRequestError({
      error: {
        code: 'position_would_go_negative',
        instrumentId: originalInstrument.id,
        tradeDate: '2026-03-01',
      },
      response: new Response('{}', { status: 422 }),
    });

    renderForm({ operation, saveError });

    expect(screen.getByRole('alert').textContent).toContain('Action d’origine');
  });
});
