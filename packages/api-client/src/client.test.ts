import { describe, expect, expectTypeOf, it, vi } from 'vitest';
import {
  createCategory,
  createTransaction,
  getFoundationStatus,
  listAssets,
  listAuditEvents,
  listProducts,
} from './generated';
import type {
  CreateCategoryRequest,
  CreateTransactionRequest,
  DecimalAmount,
  ProductCapability,
} from './generated';

describe('generated Cadran client', () => {
  it('calls the versioned endpoint with its generated response type', async () => {
    const request = vi.fn<typeof fetch>(async (_input, _init) =>
      Promise.resolve(
        new Response(JSON.stringify({ status: 'ready', apiVersion: 'v1' }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    );

    const response = await getFoundationStatus({
      baseUrl: 'https://cadran.test',
      fetch: request,
      throwOnError: true,
    });

    expect(response.data).toEqual({ status: 'ready', apiVersion: 'v1' });
    expect(request).toHaveBeenCalledOnce();
    expect(request.mock.calls[0]?.[0]).toMatchObject({
      method: 'GET',
      url: 'https://cadran.test/api/v1/status',
    });
  });

  it('binds the audit trail endpoint with its bounded query parameters', async () => {
    const request = vi.fn<typeof fetch>(async (_input, _init) =>
      Promise.resolve(
        new Response(JSON.stringify({ items: [], nextCursor: null }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    );

    const response = await listAuditEvents({
      baseUrl: 'https://cadran.test',
      fetch: request,
      throwOnError: true,
      query: { limit: 25 },
    });

    expect(response.data).toEqual({ items: [], nextCursor: null });
    expect(request.mock.calls[0]?.[0]).toMatchObject({
      method: 'GET',
      url: 'https://cadran.test/api/v1/audit-events?limit=25',
    });
  });

  it('types every reference decimal as a canonical string paired with its asset', async () => {
    const euro = {
      code: 'EUR',
      kind: 'FIAT',
      displayName: 'Euro',
      storagePrecision: 8,
      displayPrecision: 2,
      roundingMode: 'HALF_UP',
      displayStep: { value: '0.01', assetCode: 'EUR' },
    } as const;
    const request = vi.fn<typeof fetch>(async (_input, _init) =>
      Promise.resolve(
        new Response(JSON.stringify({ items: [euro], page: 1, perPage: 50, total: 1 }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    );

    const response = await listAssets({
      baseUrl: 'https://cadran.test',
      fetch: request,
      throwOnError: true,
      query: { perPage: 50 },
    });

    // The generated type must keep the amount a string: a number here would
    // mean the contract handed a financial value to binary floating point.
    expectTypeOf<DecimalAmount['value']>().toEqualTypeOf<string>();
    expect(response.data.items[0]?.displayStep).toEqual({ value: '0.01', assetCode: 'EUR' });
    expect(request.mock.calls[0]?.[0]).toMatchObject({
      method: 'GET',
      url: 'https://cadran.test/api/v1/assets?perPage=50',
    });
  });

  it('types product behavior as closed explicit capabilities', async () => {
    const request = vi.fn<typeof fetch>(async (_input, _init) =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            items: [
              {
                code: 'GENERIC_CURRENT',
                displayName: 'Generic current account',
                jurisdiction: null,
                accountKind: 'CURRENT',
                wrapperKind: 'NONE',
                yieldKind: 'NONE',
                yieldGuaranteed: false,
                defaultGroupCode: 'LIQUIDITY_CURRENT',
                capabilities: ['SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS'],
                catalogVersion: 1,
                archivedAt: null,
                asOf: '2026-09-02',
                rules: [],
                unavailableRuleKinds: [],
              },
            ],
            page: 1,
            perPage: 25,
            total: 1,
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        ),
      ),
    );

    const response = await listProducts({
      baseUrl: 'https://cadran.test',
      fetch: request,
      throwOnError: true,
    });

    expectTypeOf<ProductCapability>().toEqualTypeOf<
      | 'SUPPORTS_BALANCE'
      | 'SUPPORTS_TRANSACTIONS'
      | 'SUPPORTS_INTEREST'
      | 'SUPPORTS_HOLDINGS'
      | 'SUPPORTS_TRADES'
      | 'SUPPORTS_ARBITRAGE'
      | 'SUPPORTS_CONTRIBUTIONS'
      | 'SUPPORTS_FEES'
      | 'SUPPORTS_TAX_TRACKING'
      | 'SUPPORTS_LIABILITY'
    >();
    expect(response.data.items[0]?.capabilities).toEqual([
      'SUPPORTS_BALANCE',
      'SUPPORTS_TRANSACTIONS',
    ]);
  });

  it('binds category mutations to the generated closed request schema', async () => {
    const body: CreateCategoryRequest = {
      type: 'EXPENSE',
      label: 'Restaurants',
      parentId: null,
      icon: 'utensils',
      color: '#AABBCC',
      defaultAnalyticAxes: ['DISCRETIONARY'],
      budgetIncluded: true,
      sortOrder: 10,
    };
    const request = vi.fn<typeof fetch>(async (_input, _init) =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            id: '00000000-0000-7000-8000-0000000000c1',
            ...body,
            depth: 1,
            version: 1,
            used: false,
            typeEditable: true,
            typeEditReason: null,
            canAcceptChildren: true,
            archivedAt: null,
          }),
          { status: 201, headers: { 'Content-Type': 'application/json' } },
        ),
      ),
    );

    const response = await createCategory({
      baseUrl: 'https://cadran.test',
      fetch: request,
      headers: { 'X-CSRF-TOKEN': 'signed-token' },
      body,
      throwOnError: true,
    });

    expect(response.data.label).toBe('Restaurants');
    expect(request.mock.calls[0]?.[0]).toMatchObject({
      method: 'POST',
      url: 'https://cadran.test/api/v1/categories',
    });
  });

  it('binds transaction mutations and keeps amount values as canonical strings', async () => {
    const body: CreateTransactionRequest = {
      accountId: '00000000-0000-7000-8000-0000000000d1',
      amount: { value: '-42.90', assetCode: 'EUR' },
      nature: 'EXPENSE',
      state: 'BOOKED',
      bookedOn: '2026-03-14',
      valueOn: null,
      authorizedOn: null,
      rawLabel: 'CB CARREFOUR 1234',
      counterparty: 'Carrefour',
      note: null,
      paymentMethod: 'CARD',
      mcc: null,
      maskedCard: null,
      bankReference: null,
      categoryId: null,
    };
    const request = vi.fn<typeof fetch>(async (_input, _init) =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            id: '00000000-0000-7000-8000-0000000000f1',
            ...body,
            originalAmount: null,
            exchangeRate: null,
            source: 'MANUAL',
            splits: [],
            version: 1,
            createdAt: '2026-03-14T09:12:04.113221+01:00',
            updatedAt: '2026-03-14T09:12:04.113221+01:00',
            voidedAt: null,
          }),
          { status: 201, headers: { 'Content-Type': 'application/json' } },
        ),
      ),
    );

    const response = await createTransaction({
      baseUrl: 'https://cadran.test',
      fetch: request,
      headers: { 'X-CSRF-TOKEN': 'signed-token' },
      body,
      throwOnError: true,
    });

    expectTypeOf<CreateTransactionRequest['amount']['value']>().toEqualTypeOf<string>();
    expect(response.data.amount.value).toBe('-42.90');
    expect(request.mock.calls[0]?.[0]).toMatchObject({
      method: 'POST',
      url: 'https://cadran.test/api/v1/transactions',
    });
  });
});
