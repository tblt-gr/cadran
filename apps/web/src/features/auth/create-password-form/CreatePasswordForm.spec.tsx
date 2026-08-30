import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { CreatePasswordForm } from './CreatePasswordForm';

const STRONG = 'correct horse battery staple';

function type(label: string, value: string) {
  fireEvent.change(screen.getByLabelText(label), { target: { value } });
}

describe('CreatePasswordForm', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('rejects a password shorter than the policy before calling the server', () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    render(<CreatePasswordForm onDone={vi.fn()} />);

    type('Nouveau mot de passe', 'too short');
    type('Confirmer le mot de passe', 'too short');
    fireEvent.submit(screen.getByRole('button', { name: 'Enregistrer le mot de passe' }));

    expect(screen.getByText(/entre 12 et 128 caractères/)).toBeTruthy();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('rejects a confirmation that does not match', () => {
    vi.stubGlobal('fetch', vi.fn());
    render(<CreatePasswordForm onDone={vi.fn()} />);

    type('Nouveau mot de passe', STRONG);
    type('Confirmer le mot de passe', `${STRONG} nope`);
    fireEvent.submit(screen.getByRole('button', { name: 'Enregistrer le mot de passe' }));

    expect(screen.getByText('Les deux mots de passe ne correspondent pas.')).toBeTruthy();
  });

  it('completes when the server stores the password', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response(null, { status: 204 })),
    );
    const onDone = vi.fn();
    render(<CreatePasswordForm onDone={onDone} />);

    type('Nouveau mot de passe', STRONG);
    type('Confirmer le mot de passe', STRONG);
    fireEvent.submit(screen.getByRole('button', { name: 'Enregistrer le mot de passe' }));

    await waitFor(() => expect(onDone).toHaveBeenCalledOnce());
  });

  it('treats an already-defined password as done', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('{}', { status: 409 })),
    );
    const onDone = vi.fn();
    render(<CreatePasswordForm onDone={onDone} />);

    type('Nouveau mot de passe', STRONG);
    type('Confirmer le mot de passe', STRONG);
    fireEvent.submit(screen.getByRole('button', { name: 'Enregistrer le mot de passe' }));

    await waitFor(() => expect(onDone).toHaveBeenCalledOnce());
  });

  it('shows the policy error when the server rejects the password', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('{}', { status: 422 })),
    );
    render(<CreatePasswordForm onDone={vi.fn()} />);

    type('Nouveau mot de passe', STRONG);
    type('Confirmer le mot de passe', STRONG);
    fireEvent.submit(screen.getByRole('button', { name: 'Enregistrer le mot de passe' }));

    expect((await screen.findByRole('alert')).textContent).toContain('politique de sécurité');
  });
});
