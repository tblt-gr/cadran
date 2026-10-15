import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AuthGate } from './AuthGate';
import { LogoutButton } from './logout-button/LogoutButton';

type SessionBody = {
  provisioned: boolean;
  authenticated: boolean;
  setupRequired: boolean;
  user: unknown;
  workspace: unknown;
};

function renderGate() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthGate>
        <p>Espace authentifié</p>
      </AuthGate>
    </QueryClientProvider>,
  );
}

function respondWithSession(body: Partial<SessionBody>) {
  vi.stubGlobal(
    'fetch',
    vi.fn(
      async () =>
        new Response(
          JSON.stringify({
            provisioned: true,
            authenticated: false,
            setupRequired: false,
            user: null,
            workspace: null,
            ...body,
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        ),
    ),
  );
}

describe('AuthGate', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.history.replaceState(null, '', '/');
  });

  it('shows a loading state while the session is being checked', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise(() => undefined)),
    );

    renderGate();

    expect(screen.getByRole('navigation', { name: 'Navigation principale' })).toBeTruthy();
    // The dashboard chunk is code-split: its own loading state follows the route fallback.
    expect(await screen.findByTestId('dashboard-skeleton', {}, { timeout: 5000 })).toBeTruthy();
    expect(screen.queryByText('Espace authentifié')).toBeNull();
  });

  it('shows the disabled login form without app navigation while checking a refreshed login URL', () => {
    window.history.replaceState(null, '', '/connexion');
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise(() => undefined)),
    );

    renderGate();

    expect(screen.getByRole('heading', { name: 'Connexion' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Se connecter' }).hasAttribute('disabled')).toBe(
      true,
    );
    expect(screen.queryByRole('navigation', { name: 'Navigation principale' })).toBeNull();
  });

  it('offers a retry when the session check fails', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => Promise.reject(new Error('offline'))),
    );

    renderGate();

    expect(await screen.findByRole('heading', { name: 'Session indisponible' })).toBeTruthy();
    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
  });

  it('tells the operator to provision an owner when the server has none', async () => {
    respondWithSession({ provisioned: false });

    renderGate();

    expect(await screen.findByRole('heading', { name: 'Serveur non initialisé' })).toBeTruthy();
  });

  it('routes a fresh install to the password-setup screen', async () => {
    respondWithSession({ setupRequired: true });

    renderGate();

    expect(await screen.findByRole('heading', { name: 'Créer votre mot de passe' })).toBeTruthy();
  });

  it('shows the login screen when there is no session', async () => {
    window.history.replaceState(null, '', '/transactions?month=2026-03');
    respondWithSession({ authenticated: false });

    renderGate();

    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeTruthy();
    expect(window.location.pathname).toBe('/transactions');
    expect(window.location.search).toBe('?month=2026-03');
  });

  it('renders the application once authenticated', async () => {
    window.history.replaceState(null, '', '/connexion');
    respondWithSession({
      authenticated: true,
      user: { id: 'u1', email: 'owner@example.test', displayName: 'Owner' },
      workspace: { id: 'w1', role: 'OWNER', timeZone: 'Europe/Paris', baseCurrency: 'EUR' },
    });

    renderGate();

    expect(await screen.findByText('Espace authentifié')).toBeTruthy();
    expect(window.location.pathname).toBe('/');
  });
  it('returns to the login screen as soon as the owner logs out', async () => {
    let authenticated = true;
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: Request) => {
        if (input.method === 'DELETE') {
          authenticated = false;

          return new Response(null, { status: 204 });
        }

        return new Response(
          JSON.stringify({
            provisioned: true,
            authenticated,
            setupRequired: false,
            user: authenticated
              ? { id: 'u1', email: 'owner@example.test', displayName: 'Owner' }
              : null,
            workspace: authenticated
              ? { id: 'w1', role: 'OWNER', timeZone: 'Europe/Paris', baseCurrency: 'EUR' }
              : null,
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        );
      }),
    );
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <AuthGate>
          <LogoutButton />
        </AuthGate>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole('button', { name: 'Se déconnecter' }));

    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeTruthy();
    expect(window.location.pathname).toBe('/connexion');
    expect(screen.queryByRole('button', { name: 'Se déconnecter' })).toBeNull();
  });
});
