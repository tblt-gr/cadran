import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { LogoutButton } from './LogoutButton';

function renderButton() {
  const queryClient = new QueryClient();
  const clear = vi.spyOn(queryClient, 'clear');

  render(
    <QueryClientProvider client={queryClient}>
      <LogoutButton />
    </QueryClientProvider>,
  );

  return { clear };
}

describe('LogoutButton', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('ends the session and drops the query cache', async () => {
    const fetchMock = vi.fn((_input: Request) =>
      Promise.resolve(new Response(null, { status: 204 })),
    );
    vi.stubGlobal('fetch', fetchMock);
    const { clear } = renderButton();

    fireEvent.click(screen.getByRole('button', { name: 'Se déconnecter' }));

    await waitFor(() => expect(clear).toHaveBeenCalledOnce());
    expect(fetchMock).toHaveBeenCalledOnce();
    const request = fetchMock.mock.calls[0][0];
    expect(request.method).toBe('DELETE');
    expect(request.url).toContain('/api/v1/session');
  });

  it('still clears the cache when the request fails', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => Promise.reject(new Error('offline'))),
    );
    const { clear } = renderButton();

    fireEvent.click(screen.getByRole('button', { name: 'Se déconnecter' }));

    await waitFor(() => expect(clear).toHaveBeenCalledOnce());
  });
});
