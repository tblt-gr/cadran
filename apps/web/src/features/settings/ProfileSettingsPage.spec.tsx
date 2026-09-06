import type { OwnerProfile } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { ProfileSettingsPage } from './ProfileSettingsPage';

const api = vi.hoisted(() => ({
  changeOwnerPassword: vi.fn(),
  getOwnerProfile: vi.fn(),
  updateOwnerProfile: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const profile: OwnerProfile = {
  id: '00000000-0000-7000-8000-000000000001',
  email: 'owner@example.test',
  displayName: 'Owner',
};

const CURRENT_PASSWORD = 'correct horse battery staple';
const NEW_PASSWORD = 'a different long passphrase';

function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function problem(status: number, type: string) {
  const body = { type, title: 'Refusé', status, detail: 'Refusé.' };

  return Promise.resolve({
    error: body,
    response: new Response(JSON.stringify(body), { status }),
  });
}

function type_(label: string, value: string) {
  fireEvent.change(screen.getByLabelText(label), { target: { value } });
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <ProfileSettingsPage />
    </QueryClientProvider>,
  );
}

async function renderLoadedPage() {
  api.getOwnerProfile.mockReturnValue(success(profile));
  renderPage();
  await screen.findByLabelText('Nom affiché');
}

describe('ProfileSettingsPage', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('announces the loading state while the profile is being read', () => {
    api.getOwnerProfile.mockReturnValue(new Promise(() => {}));
    renderPage();

    expect(screen.getByRole('status').textContent).toContain('Chargement du profil');
  });

  it('offers a retry when the profile cannot be read', async () => {
    api.getOwnerProfile.mockReturnValue(problem(500, 'about:blank'));
    renderPage();

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Profil indisponible');
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
  });

  it('asks an expired session to sign in again rather than to retry', async () => {
    api.getOwnerProfile.mockReturnValue(problem(401, 'about:blank'));
    renderPage();

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Session expirée');
    expect(screen.queryByRole('button', { name: 'Réessayer' })).toBeNull();
  });

  it('renders the profile section without any account or transaction data', async () => {
    await renderLoadedPage();

    expect(screen.getByText('owner@example.test')).toBeTruthy();
    expect(screen.getByLabelText('Nom affiché')).toHaveProperty('value', 'Owner');
  });

  it('saves a new display name and confirms it', async () => {
    await renderLoadedPage();
    api.updateOwnerProfile.mockReturnValue(success({ ...profile, displayName: 'Marie Dupont' }));

    type_('Nom affiché', 'Marie Dupont');
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer le nom' }));

    await waitFor(() =>
      expect(api.updateOwnerProfile).toHaveBeenCalledWith(
        expect.objectContaining({ body: { displayName: 'Marie Dupont' } }),
      ),
    );
    expect(
      (await screen.findAllByRole('status')).some((node) =>
        node.textContent?.includes('Le nom affiché a été enregistré.'),
      ),
    ).toBe(true);
  });

  it('refuses an empty display name before calling the server', async () => {
    await renderLoadedPage();

    type_('Nom affiché', '   ');
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer le nom' }));

    expect(screen.getByText(/entre 1 et 100 caractères/)).toBeTruthy();
    expect(api.updateOwnerProfile).not.toHaveBeenCalled();
  });

  it('changes the password and clears the three fields', async () => {
    await renderLoadedPage();
    api.changeOwnerPassword.mockReturnValue(
      Promise.resolve({ response: new Response(null, { status: 204 }) }),
    );

    type_('Mot de passe actuel', CURRENT_PASSWORD);
    type_('Nouveau mot de passe', NEW_PASSWORD);
    type_('Confirmer le nouveau mot de passe', NEW_PASSWORD);
    fireEvent.click(screen.getByRole('button', { name: 'Changer le mot de passe' }));

    await waitFor(() =>
      expect(api.changeOwnerPassword).toHaveBeenCalledWith(
        expect.objectContaining({
          body: { currentPassword: CURRENT_PASSWORD, newPassword: NEW_PASSWORD },
        }),
      ),
    );
    // No credential is left in the DOM once the change succeeded.
    await waitFor(() =>
      expect(screen.getByLabelText('Mot de passe actuel')).toHaveProperty('value', ''),
    );
    expect(screen.getByLabelText('Nouveau mot de passe')).toHaveProperty('value', '');
  });

  it('attaches a wrong current password to its own field', async () => {
    await renderLoadedPage();
    api.changeOwnerPassword.mockReturnValue(problem(422, '/problems/invalid-current-password'));

    type_('Mot de passe actuel', 'wrong password value');
    type_('Nouveau mot de passe', NEW_PASSWORD);
    type_('Confirmer le nouveau mot de passe', NEW_PASSWORD);
    fireEvent.click(screen.getByRole('button', { name: 'Changer le mot de passe' }));

    const field = await screen.findByLabelText('Mot de passe actuel');
    await waitFor(() => expect(field.getAttribute('aria-invalid')).toBe('true'));
    const describedBy = field.getAttribute('aria-describedby');
    expect(describedBy).not.toBeNull();
    expect(document.getElementById(describedBy ?? '')?.textContent).toContain(
      'Le mot de passe actuel est incorrect.',
    );
  });

  it('attaches a reused password to the new password field', async () => {
    await renderLoadedPage();
    api.changeOwnerPassword.mockReturnValue(problem(422, '/problems/reused-password'));

    type_('Mot de passe actuel', CURRENT_PASSWORD);
    type_('Nouveau mot de passe', NEW_PASSWORD);
    type_('Confirmer le nouveau mot de passe', NEW_PASSWORD);
    fireEvent.click(screen.getByRole('button', { name: 'Changer le mot de passe' }));

    const field = await screen.findByLabelText('Nouveau mot de passe');
    await waitFor(() => expect(field.getAttribute('aria-invalid')).toBe('true'));
    expect(
      document.getElementById(field.getAttribute('aria-describedby') ?? '')?.textContent,
    ).toContain('différent de l’actuel');
  });

  it('reports throttling as a form-level message', async () => {
    await renderLoadedPage();
    api.changeOwnerPassword.mockReturnValue(problem(429, 'about:blank'));

    type_('Mot de passe actuel', CURRENT_PASSWORD);
    type_('Nouveau mot de passe', NEW_PASSWORD);
    type_('Confirmer le nouveau mot de passe', NEW_PASSWORD);
    fireEvent.click(screen.getByRole('button', { name: 'Changer le mot de passe' }));

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Trop de tentatives');
  });

  it('refuses a mismatched confirmation before calling the server', async () => {
    await renderLoadedPage();

    type_('Mot de passe actuel', CURRENT_PASSWORD);
    type_('Nouveau mot de passe', NEW_PASSWORD);
    type_('Confirmer le nouveau mot de passe', `${NEW_PASSWORD} nope`);
    fireEvent.click(screen.getByRole('button', { name: 'Changer le mot de passe' }));

    expect(screen.getByText('Les deux mots de passe ne correspondent pas.')).toBeTruthy();
    expect(api.changeOwnerPassword).not.toHaveBeenCalled();
  });

  it('refuses a new password identical to the current one before calling the server', async () => {
    await renderLoadedPage();

    // What a password manager does when it fills all three fields.
    type_('Mot de passe actuel', CURRENT_PASSWORD);
    type_('Nouveau mot de passe', CURRENT_PASSWORD);
    type_('Confirmer le nouveau mot de passe', CURRENT_PASSWORD);
    fireEvent.click(screen.getByRole('button', { name: 'Changer le mot de passe' }));

    expect(
      screen.getByText('Le nouveau mot de passe doit être différent de l’actuel.'),
    ).toBeTruthy();
    expect(api.changeOwnerPassword).not.toHaveBeenCalled();
  });

  it('refuses a new password shorter than the policy before calling the server', async () => {
    await renderLoadedPage();

    type_('Mot de passe actuel', CURRENT_PASSWORD);
    type_('Nouveau mot de passe', 'too short');
    type_('Confirmer le nouveau mot de passe', 'too short');
    fireEvent.click(screen.getByRole('button', { name: 'Changer le mot de passe' }));

    expect(screen.getByText(/entre 12 et 128 caractères/)).toBeTruthy();
    expect(api.changeOwnerPassword).not.toHaveBeenCalled();
  });
});
