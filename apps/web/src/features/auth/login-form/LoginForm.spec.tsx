import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { LoginForm } from './LoginForm';

function fillAndSubmit() {
  fireEvent.change(screen.getByLabelText('Adresse e-mail'), {
    target: { value: 'owner@example.test' },
  });
  fireEvent.change(screen.getByLabelText('Mot de passe'), {
    target: { value: 'correct horse battery' },
  });
  fireEvent.submit(screen.getByRole('button', { name: 'Se connecter' }));
}

function respondWith(status: number) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => new Response(status === 204 ? null : '{}', { status })),
  );
}

describe('LoginForm', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('signals success once the server accepts the credentials', async () => {
    respondWith(204);
    const onSuccess = vi.fn();
    render(<LoginForm onSuccess={onSuccess} />);

    fillAndSubmit();

    await waitFor(() => expect(onSuccess).toHaveBeenCalledOnce());
  });

  it('shows a generic error for invalid credentials without revealing which field failed', async () => {
    respondWith(401);
    render(<LoginForm onSuccess={vi.fn()} />);

    fillAndSubmit();

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('incorrect');
  });

  it('reports a disabled account distinctly', async () => {
    respondWith(403);
    render(<LoginForm onSuccess={vi.fn()} />);

    fillAndSubmit();

    expect((await screen.findByRole('alert')).textContent).toContain('désactivé');
  });

  it('reports a throttled sign-in without blaming the credentials', async () => {
    respondWith(429);
    render(<LoginForm onSuccess={vi.fn()} />);

    fillAndSubmit();

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Trop de tentatives');
    expect(alert.textContent).not.toContain('incorrect');
  });

  it('recovers from a stale CSRF token by replanting it and retrying', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ type: '/problems/csrf-token' }), {
          status: 403,
          headers: { 'Content-Type': 'application/problem+json' },
        }),
      )
      .mockResolvedValueOnce(new Response('{}', { status: 200 }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);
    const onSuccess = vi.fn();
    render(<LoginForm onSuccess={onSuccess} />);

    fillAndSubmit();

    await waitFor(() => expect(onSuccess).toHaveBeenCalledOnce());
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });

  it('surfaces a network failure', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => Promise.reject(new Error('offline'))),
    );
    render(<LoginForm onSuccess={vi.fn()} />);

    fillAndSubmit();

    expect((await screen.findByRole('alert')).textContent).toContain('échoué');
  });

  it('disables the submit control while the request is in flight', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise(() => undefined)),
    );
    render(<LoginForm onSuccess={vi.fn()} />);

    fillAndSubmit();

    const submit = await screen.findByRole('button', { name: 'Connexion…' });
    expect(submit.hasAttribute('disabled')).toBe(true);
  });
});
