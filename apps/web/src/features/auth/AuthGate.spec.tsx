import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AuthGate } from './AuthGate';

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
  });

  it('shows a loading state while the session is being checked', () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise(() => undefined)),
    );

    renderGate();

    expect(screen.getByRole('status').textContent).toContain('Vérification de la session');
    expect(screen.queryByText('Espace authentifié')).toBeNull();
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
    respondWithSession({ authenticated: false });

    renderGate();

    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeTruthy();
  });

  it('renders the application once authenticated', async () => {
    respondWithSession({
      authenticated: true,
      user: { id: 'u1', email: 'owner@example.test', displayName: 'Owner' },
      workspace: { id: 'w1', role: 'OWNER', timeZone: 'Europe/Paris' },
    });

    renderGate();

    expect(await screen.findByText('Espace authentifié')).toBeTruthy();
  });
});
