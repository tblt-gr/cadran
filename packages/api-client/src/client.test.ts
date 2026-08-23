import { describe, expect, it, vi } from 'vitest';
import { getFoundationStatus } from './generated';

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
});
