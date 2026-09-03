import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountsPage } from './AccountsPage';

const api = vi.hoisted(() => ({
  archiveAccount: vi.fn(),
  createAccount: vi.fn(),
  listAccounts: vi.fn(),
  listAssets: vi.fn(),
  updateAccount: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const account: Account = {
  id: '00000000-0000-7000-8000-0000000000d1',
  label: 'Livret A Banque X',
  assetCode: 'EUR',
  kind: 'SAVINGS',
  maskedIdentifier: '4821',
  valuationMode: 'TRANSACTIONS',
  liquidityLevel: 'IMMEDIATE',
  includeInNetWorth: true,
  includeInEmergencyFund: true,
  openedOn: '2026-01-10',
  closedOn: null,
  status: 'ACTIVE',
  netWorthSign: 1,
  used: false,
  editable: true,
  kindEditable: true,
  kindEditReason: null,
  version: 1,
  archivedAt: null,
};

function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function failure(status: number) {
  return Promise.resolve({ data: undefined, response: new Response('{}', { status }) });
}

/** An RFC 9457 refusal, whose `type` is what the page branches on. */
function problem(status: number, type: string) {
  const body = { type, title: 'Conflit', status, detail: 'Conflit' };

  return Promise.resolve({
    data: undefined,
    error: body,
    response: new Response(JSON.stringify(body), { status }),
  });
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <AccountsPage />
    </QueryClientProvider>,
  );
}

describe('AccountsPage', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('covers the empty state and creates an account with its denomination and policies', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listAssets.mockImplementation(() =>
      success({
        items: [
          {
            code: 'EUR',
            kind: 'FIAT',
            displayName: 'Euro',
            storagePrecision: 8,
            displayPrecision: 2,
            roundingMode: 'HALF_UP',
            displayStep: { value: '0.01', assetCode: 'EUR' },
          },
        ],
        page: 1,
        perPage: 100,
        total: 1,
      }),
    );
    api.createAccount.mockImplementation(({ body }) => success({ ...account, ...body }, 201));
    const { container } = renderPage();

    expect(await screen.findByRole('heading', { name: 'Aucun compte' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Créer le premier compte' }));
    expect(screen.getByRole('dialog', { name: 'Nouveau compte' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Livret A Banque X' } });
    fireEvent.change(screen.getByLabelText('Fin d’identifiant'), { target: { value: '4821' } });
    fireEvent.change(screen.getByLabelText('Date d’ouverture'), {
      target: { value: '2026-01-10' },
    });
    // The denomination comes from the reference, so the form waits for it
    // instead of submitting a currency the user never saw.
    expect(await screen.findByRole('option', { name: 'EUR · Euro' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(api.createAccount).toHaveBeenCalledOnce());
    expect(api.createAccount.mock.calls[0]?.[0].body).toMatchObject({
      label: 'Livret A Banque X',
      assetCode: 'EUR',
      kind: 'CURRENT',
      maskedIdentifier: '4821',
      valuationMode: 'TRANSACTIONS',
      includeInNetWorth: true,
      includeInEmergencyFund: false,
      openedOn: '2026-01-10',
      closedOn: null,
    });
    const toast = await screen.findByText('Le compte a été enregistré.');
    expect(toast.closest('[role="status"]')).toBeTruthy();
    expect(container.contains(toast)).toBe(false);
  });

  it('refuses to create an account when the asset reference cannot be read', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listAssets.mockImplementation(() => failure(500));
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier compte' }));
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Compte courant' } });
    fireEvent.change(screen.getByLabelText('Date d’ouverture'), {
      target: { value: '2026-01-10' },
    });
    expect(
      await screen.findByText('Impossible de charger le référentiel des devises.'),
    ).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    // The denomination is fixed at creation: a silent EUR fallback could never
    // be corrected afterwards.
    expect(api.createAccount).not.toHaveBeenCalled();
    expect(
      screen.getByText(
        'Choisissez une devise du référentiel. Elle est fixée à la création et ne pourra plus être changée.',
      ),
    ).toBeTruthy();
  });

  it('refuses a full banking identifier and a closing date before the opening one', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listAssets.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier compte' }));
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Compte courant' } });
    fireEvent.change(screen.getByLabelText('Date d’ouverture'), {
      target: { value: '2026-01-10' },
    });
    fireEvent.change(screen.getByLabelText('Fin d’identifiant'), {
      target: { value: 'FR763000' },
    });
    fireEvent.change(screen.getByLabelText('Date de clôture'), { target: { value: '2026-01-09' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    expect(api.createAccount).not.toHaveBeenCalled();
    expect(
      screen.getByText('La date de clôture doit suivre l’ouverture et ne peut pas être future.'),
    ).toBeTruthy();
  });

  it('keeps the emergency fund tied to net worth', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 50, total: 1 }),
    );
    api.updateAccount.mockImplementation(({ body }) => success({ ...account, ...body }));
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Modifier le compte Livret A Banque X' }),
    );
    const emergencyFund = screen.getByLabelText('Compter ce compte dans l’épargne de précaution');
    expect((emergencyFund as HTMLInputElement).checked).toBe(true);

    fireEvent.click(screen.getByLabelText('Compter ce compte dans le patrimoine net'));
    expect((emergencyFund as HTMLInputElement).checked).toBe(false);
    expect((emergencyFund as HTMLInputElement).disabled).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.updateAccount).toHaveBeenCalledOnce());
    expect(api.updateAccount.mock.calls[0]?.[0].body).toMatchObject({
      includeInNetWorth: false,
      includeInEmergencyFund: false,
      version: 1,
    });
  });

  it('archives an account through a confirmation instead of deleting it', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 50, total: 1 }),
    );
    api.archiveAccount.mockImplementation(() =>
      success({ ...account, status: 'ARCHIVED', editable: false, version: 2 }),
    );
    const { container } = renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Archiver le compte Livret A Banque X' }),
    );
    expect(screen.getByRole('dialog', { name: 'Archiver le compte' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Archiver' }));

    await waitFor(() => expect(api.archiveAccount).toHaveBeenCalledOnce());
    expect(api.archiveAccount.mock.calls[0]?.[0].body).toEqual({ version: 1 });
    const toast = await screen.findByText('Le compte a été archivé.');
    expect(toast.closest('[role="status"]')).toBeTruthy();
    expect(container.contains(toast)).toBe(false);
  });

  it('separates a taken label from a stale version and shows the unauthorized state', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 50, total: 1 }),
    );
    api.updateAccount.mockImplementation(() => problem(409, '/problems/account-label-taken'));
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Modifier le compte Livret A Banque X' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(
      await screen.findByText('Un compte actif porte déjà ce libellé. Choisissez-en un autre.'),
    ).toBeTruthy();

    cleanup();
    vi.clearAllMocks();
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 50, total: 1 }),
    );
    api.updateAccount.mockImplementation(() => problem(409, '/problems/stale-version'));
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Modifier le compte Livret A Banque X' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(
      await screen.findByText(
        'Ce compte a été modifié ailleurs entre-temps. La liste vient d’être rechargée : rouvrez le compte puis réappliquez votre modification.',
      ),
    ).toBeTruthy();

    cleanup();
    api.listAccounts.mockImplementation(() => failure(401));
    renderPage();
    expect(await screen.findByRole('heading', { name: 'Session expirée' })).toBeTruthy();
  });

  it('states the net-worth contribution in words and marks a liability', async () => {
    api.listAccounts.mockImplementation(() =>
      success({
        items: [
          account,
          {
            ...account,
            id: '00000000-0000-7000-8000-0000000000d2',
            label: 'Prêt immobilier',
            kind: 'LIABILITY',
            netWorthSign: -1,
            includeInEmergencyFund: false,
          },
        ],
        page: 1,
        perPage: 50,
        total: 2,
      }),
    );
    renderPage();

    expect(await screen.findByText('Actif (+)')).toBeTruthy();
    expect(screen.getByText('Passif (−)')).toBeTruthy();
  });
});
