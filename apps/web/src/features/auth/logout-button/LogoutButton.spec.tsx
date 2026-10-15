import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { LogoutButton } from './LogoutButton';

function renderButton() {
  const queryClient = new QueryClient();
  queryClient.setQueryData(['session'], {
    provisioned: true,
    authenticated: true,
    setupRequired: false,
    user: { id: 'u1', email: 'owner@example.test', displayName: 'Owner' },
    workspace: { id: 'w1', role: 'OWNER', timeZone: 'Europe/Paris', baseCurrency: 'EUR' },
  });
  queryClient.setQueryData(['accounts'], ['private']);

  render(
    <QueryClientProvider client={queryClient}>
      <LogoutButton />
    </QueryClientProvider>,
  );

  const signedOut = () =>
    queryClient.getQueryData<{ authenticated: boolean }>(['session'])?.authenticated === false &&
    queryClient.getQueryData(['accounts']) === undefined;

  return { signedOut };
}

describe('LogoutButton', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.history.replaceState(null, '', '/');
  });

  it('ends the session, marks it signed out and drops every other query', async () => {
    window.history.replaceState(null, '', '/parameters/profile?tab=security');
    const fetchMock = vi.fn((_input: Request) =>
      Promise.resolve(new Response(null, { status: 204 })),
    );
    vi.stubGlobal('fetch', fetchMock);
    const { signedOut } = renderButton();

    fireEvent.click(screen.getByRole('button', { name: 'Se déconnecter' }));

    await waitFor(() => expect(signedOut()).toBe(true));
    expect(window.location.pathname).toBe('/connexion');
    expect(window.location.search).toBe('');
    expect(fetchMock).toHaveBeenCalledOnce();
    const request = fetchMock.mock.calls[0][0];
    expect(request.method).toBe('DELETE');
    expect(request.url).toContain('/api/v1/session');
  });

  it('still signs out locally when the request fails', async () => {
    window.history.replaceState(null, '', '/parameters/profile');
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => Promise.reject(new Error('offline'))),
    );
    const { signedOut } = renderButton();

    fireEvent.click(screen.getByRole('button', { name: 'Se déconnecter' }));

    await waitFor(() => expect(signedOut()).toBe(true));
    expect(window.location.pathname).toBe('/connexion');
  });
});
