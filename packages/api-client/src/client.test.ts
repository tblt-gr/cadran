import { describe, expect, expectTypeOf, it, vi } from 'vitest';
import { getFoundationStatus, listAssets, listAuditEvents } from './generated';
import type { DecimalAmount } from './generated';

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
});
