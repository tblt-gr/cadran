import { describe, expect, it, vi } from 'vitest';
import { getFoundationStatus, listAuditEvents } from './generated';

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
});
