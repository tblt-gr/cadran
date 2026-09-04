import type { Account, Product, ProductModel } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountsPage } from './AccountsPage';

const api = vi.hoisted(() => ({
  archiveAccount: vi.fn(),
  createAccount: vi.fn(),
  listAccounts: vi.fn(),
  listAssets: vi.fn(),
  listProductModels: vi.fn(),
  listProducts: vi.fn(),
  readAccountRules: vi.fn(),
  readProduct: vi.fn(),
  readProductModel: vi.fn(),
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
  productCode: 'FR_LIVRET_A',
  productModelId: null,
  institution: 'Banque X',
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

const livretA: Product = {
  code: 'FR_LIVRET_A',
  displayName: 'Livret A',
  jurisdiction: 'FR',
  accountKind: 'SAVINGS',
  wrapperKind: 'REGULATED_SAVINGS',
  yieldKind: 'REGULATED_RATE',
  yieldGuaranteed: true,
  ceilingBasis: 'BALANCE_EXCLUDING_INTEREST',
  defaultGroupCode: 'LIQUIDITY_SAVINGS',
  capabilities: ['SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS', 'SUPPORTS_INTEREST'],
  catalogVersion: 1,
  archivedAt: null,
  asOf: '2026-09-03',
  rules: [
    {
      kind: 'DEPOSIT_CEILING',
      valueType: 'AMOUNT',
      amount: { value: '22950', assetCode: 'EUR' },
      percentage: null,
      text: null,
      validFrom: '2026-08-22',
      validTo: null,
      verification: 'VERIFIED',
      verifiedOn: '2026-08-22',
      verifiedBy: 'cadran-maintainer',
      source: {
        publisher: 'Direction de l’information légale et administrative',
        title: 'Livret A',
        url: 'https://www.service-public.fr/particuliers/vosdroits/F2365',
        publishedOn: '2025-04-25',
        retrievedOn: '2026-08-22',
      },
    },
  ],
  unavailableRuleKinds: ['ANNUAL_RATE'],
};

const pea: Product = {
  ...livretA,
  code: 'FR_PEA',
  displayName: 'Plan d’épargne en actions',
  accountKind: 'PORTFOLIO',
  wrapperKind: 'TAX_WRAPPER',
  yieldKind: 'MARKET',
  yieldGuaranteed: false,
  ceilingBasis: 'CONTRIBUTIONS',
  defaultGroupCode: 'INVESTMENTS_MARKET',
  capabilities: [
    'SUPPORTS_BALANCE',
    'SUPPORTS_TRANSACTIONS',
    'SUPPORTS_HOLDINGS',
    'SUPPORTS_TRADES',
    'SUPPORTS_CONTRIBUTIONS',
  ],
  rules: [
    {
      ...livretA.rules[0]!,
      kind: 'CONTRIBUTION_CEILING',
      amount: { value: '150000', assetCode: 'EUR' },
    },
  ],
  unavailableRuleKinds: [],
};

const livretATemplate: ProductModel = {
  id: '00000000-0000-7000-8000-0000000000f1',
  name: 'Livret Banque X',
  family: 'SAVINGS',
  nature: 'ASSET',
  wrapperKind: 'REGULATED_SAVINGS',
  yieldKind: 'CONTRACTUAL_FIXED',
  yieldGuaranteed: true,
  ceilingBasis: 'BALANCE_EXCLUDING_INTEREST',
  defaultGroupCode: 'LIQUIDITY_SAVINGS',
  valuationMode: 'TRANSACTIONS',
  capabilities: ['SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS', 'SUPPORTS_INTEREST'],
  origin: 'DECLARED',
  basedOnProductCode: null,
  basedOnModelId: null,
  rules: [],
  editable: true,
  version: 1,
  createdAt: '2026-09-01T12:00:00Z',
  updatedAt: '2026-09-01T12:00:00Z',
  archivedAt: null,
};

const euro = {
  code: 'EUR',
  kind: 'FIAT',
  displayName: 'Euro',
  storagePrecision: 8,
  displayPrecision: 2,
  roundingMode: 'HALF_UP',
  displayStep: { value: '0.01', assetCode: 'EUR' },
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
  beforeEach(() => {
    // Editing resolves the model an account follows before offering a kind, so
    // every edit path answers the catalogue read.
    api.readProduct.mockImplementation(() => success(livretA));
    // The wizard always reads the workspace's own templates alongside the
    // catalogue; a workspace with none yet is the default for every test that
    // does not say otherwise.
    api.listProductModels.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('covers the empty state and creates an account described by hand', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listAssets.mockImplementation(() =>
      success({ items: [euro], page: 1, perPage: 100, total: 1 }),
    );
    api.listProducts.mockImplementation(() =>
      success({ items: [livretA], page: 1, perPage: 100, total: 1 }),
    );
    api.createAccount.mockImplementation(({ body }) => success({ ...account, ...body }, 201));
    const { container } = renderPage();

    expect(await screen.findByRole('heading', { name: 'Aucun compte' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Créer le premier compte' }));
    expect(screen.getByRole('dialog', { name: 'Nouveau compte' })).toBeTruthy();

    expect(await screen.findByRole('radio', { name: /Aucun produit/ })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));

    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Compte courant' } });
    fireEvent.change(screen.getByLabelText('Fin d’identifiant'), { target: { value: '4821' } });
    fireEvent.change(screen.getByLabelText('Date d’ouverture'), {
      target: { value: '2026-01-10' },
    });
    // The denomination comes from the reference, so the form waits for it
    // instead of submitting a currency the user never saw.
    expect(await screen.findByRole('option', { name: 'EUR · Euro' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));

    expect(
      screen.getByText(
        'Ce compte n’hérite d’aucune règle : aucun produit du catalogue ne lui est rattaché.',
      ),
    ).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Créer le compte' }));

    await waitFor(() => expect(api.createAccount).toHaveBeenCalledOnce());
    expect(api.createAccount.mock.calls[0]?.[0].body).toMatchObject({
      label: 'Compte courant',
      assetCode: 'EUR',
      kind: 'CURRENT',
      productCode: null,
      institution: null,
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

  it('inherits the kind of a chosen product and submits only its reference', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listAssets.mockImplementation(() =>
      success({ items: [euro], page: 1, perPage: 100, total: 1 }),
    );
    api.listProducts.mockImplementation(() =>
      success({ items: [livretA], page: 1, perPage: 100, total: 1 }),
    );
    api.createAccount.mockImplementation(({ body }) => success({ ...account, ...body }, 201));
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier compte' }));
    expect(await screen.findByRole('group', { name: 'Catalogue système' })).toBeTruthy();
    expect(screen.getByRole('group', { name: 'Modèles de l’espace de travail' })).toBeTruthy();
    fireEvent.click(await screen.findByRole('radio', { name: /Livret A/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));

    // The catalogue owns the kind, so the field states it instead of offering a
    // choice the API would refuse.
    const kind = screen.getByLabelText('Nature du compte') as HTMLSelectElement;
    expect(kind.value).toBe('SAVINGS');
    expect(kind.disabled).toBe(true);
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Livret A Banque X' } });
    fireEvent.change(screen.getByLabelText('Établissement'), { target: { value: 'Banque X' } });
    fireEvent.change(screen.getByLabelText('Date d’ouverture'), {
      target: { value: '2026-01-10' },
    });
    expect(await screen.findByRole('option', { name: 'EUR · Euro' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));

    // The review shows the ceiling with its period and its official source, and
    // says the figure is read from the catalogue rather than copied.
    expect(screen.getByRole('heading', { name: 'Hérité de « Livret A »' })).toBeTruthy();
    expect(screen.getByText('Plafond de dépôt')).toBeTruthy();
    expect(screen.getByText(/22\s950\s€/)).toBeTruthy();
    expect(screen.getByText('Plafond sur les sommes déposées, hors intérêts')).toBeTruthy();
    // An unsourced rate is unavailable on this date, never a zero.
    expect(screen.getByText(/Non disponible au/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Créer le compte' }));

    await waitFor(() => expect(api.createAccount).toHaveBeenCalledOnce());
    const body = api.createAccount.mock.calls[0]?.[0].body;
    expect(body).toMatchObject({
      label: 'Livret A Banque X',
      institution: 'Banque X',
      kind: 'SAVINGS',
      productCode: 'FR_LIVRET_A',
    });
    // Only the reference travels: no ceiling, rate or period is copied into the
    // account, so a regulatory revision is never frozen into it.
    expect(Object.keys(body as object).sort()).toEqual([
      'assetCode',
      'closedOn',
      'includeInEmergencyFund',
      'includeInNetWorth',
      'institution',
      'kind',
      'label',
      'liquidityLevel',
      'maskedIdentifier',
      'openedOn',
      'productCode',
      'productModelId',
      'valuationMode',
    ]);
  });

  it('creates an account from a workspace template without copying its rules', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listAssets.mockImplementation(() =>
      success({ items: [euro], page: 1, perPage: 100, total: 1 }),
    );
    api.listProducts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    api.listProductModels.mockImplementation(() =>
      success({ items: [livretATemplate], page: 1, perPage: 100, total: 1 }),
    );
    api.createAccount.mockImplementation(({ body }) => success({ ...account, ...body }, 201));
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier compte' }));
    fireEvent.click(await screen.findByRole('radio', { name: /Livret Banque X/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));

    // The template owns the kind, exactly as a catalogue product would.
    const kind = screen.getByLabelText('Nature du compte') as HTMLSelectElement;
    expect(kind.value).toBe('SAVINGS');
    expect(kind.disabled).toBe(true);
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Livret Banque X' } });
    fireEvent.change(screen.getByLabelText('Date d’ouverture'), {
      target: { value: '2026-01-10' },
    });
    expect(await screen.findByRole('option', { name: 'EUR · Euro' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));

    expect(
      screen.getByRole('heading', { name: 'Hérité du modèle « Livret Banque X »' }),
    ).toBeTruthy();
    expect(
      screen.getByText(
        'Le tableau liste toutes les périodes enregistrées sur le modèle, y compris celles déjà closes ou pas encore ouvertes.',
      ),
    ).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Créer le compte' }));

    await waitFor(() => expect(api.createAccount).toHaveBeenCalledOnce());
    const body = api.createAccount.mock.calls[0]?.[0].body;
    // Only the reference travels: the account carries the template's id and
    // no catalogue code at all.
    expect(body).toMatchObject({
      label: 'Livret Banque X',
      kind: 'SAVINGS',
      productCode: null,
      productModelId: livretATemplate.id,
    });
    expect(api.listProductModels).toHaveBeenCalledWith(
      expect.objectContaining({
        query: expect.objectContaining({ includeArchived: false }),
      }),
    );
  });

  it('states the contribution basis of a share savings plan and promises no yield', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listAssets.mockImplementation(() =>
      success({ items: [euro], page: 1, perPage: 100, total: 1 }),
    );
    api.listProducts.mockImplementation(() =>
      success({ items: [pea], page: 1, perPage: 100, total: 1 }),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier compte' }));
    fireEvent.click(await screen.findByRole('radio', { name: /épargne en actions/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));

    // A plan that holds positions is valued by those positions; starting it on
    // recorded movements would detach its value from what it holds.
    expect((screen.getByLabelText('Mode de valorisation') as HTMLSelectElement).value).toBe(
      'PORTFOLIO',
    );
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'PEA Banque X' } });
    fireEvent.change(screen.getByLabelText('Date d’ouverture'), {
      target: { value: '2026-01-10' },
    });

    // Stepping back to the product must not throw the typed fields away.
    fireEvent.click(screen.getByRole('button', { name: 'Retour' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));
    expect((screen.getByLabelText('Libellé') as HTMLInputElement).value).toBe('PEA Banque X');
    expect((screen.getByLabelText('Date d’ouverture') as HTMLInputElement).value).toBe(
      '2026-01-10',
    );

    expect(await screen.findByRole('option', { name: 'EUR · Euro' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));

    // A PEA is capped on what was paid in, whatever the plan is worth.
    expect(screen.getByText('Plafond sur les versements cumulés')).toBeTruthy();
    expect(screen.getByText('Plafond de versements')).toBeTruthy();
    expect(screen.queryByText('Plafond de dépôt')).toBeNull();
    expect(
      screen.getByText(
        'Aucun rendement promis : la valeur de ce compte dépend des actifs détenus, pas d’un taux du catalogue.',
      ),
    ).toBeTruthy();
    expect(screen.queryByText('Rendement garanti')).toBeNull();
  });

  it('lets a catalogue product be chosen when the workspace templates cannot be read', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listAssets.mockImplementation(() =>
      success({ items: [euro], page: 1, perPage: 100, total: 1 }),
    );
    api.listProducts.mockImplementation(() =>
      success({ items: [livretA], page: 1, perPage: 100, total: 1 }),
    );
    api.listProductModels.mockImplementation(() => failure(500));
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier compte' }));
    expect(
      await screen.findByText(
        'Les modèles de l’espace n’ont pas pu être chargés. Réessayez, ou choisissez une autre origine.',
      ),
    ).toBeTruthy();
    fireEvent.click(await screen.findByRole('radio', { name: /Livret A/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));

    expect(screen.getByLabelText('Libellé')).toBeTruthy();
    expect((screen.getByLabelText('Nature du compte') as HTMLSelectElement).disabled).toBe(true);
  });

  it('keeps the kind locked when the attached template cannot be read', async () => {
    const templated = {
      ...account,
      productCode: null,
      productModelId: livretATemplate.id,
    };
    api.listAccounts.mockImplementation(() =>
      success({ items: [templated], page: 1, perPage: 50, total: 1 }),
    );
    api.readProductModel.mockImplementation(() => failure(500));
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Modifier le compte Livret A Banque X' }),
    );
    expect(
      await screen.findByText(
        'Le modèle rattaché à ce compte n’a pas pu être lu. La nature reste imposée par cette référence ; l’API refusera une combinaison que le modèle ne déclare pas.',
      ),
    ).toBeTruthy();
    const kind = screen.getByLabelText('Nature du compte') as HTMLSelectElement;
    expect(kind.disabled).toBe(true);
    expect(
      screen.getByText(
        'La nature est imposée par le modèle rattaché, même s’il n’a pas pu être lu.',
      ),
    ).toBeTruthy();
  });

  it('lets an unreadable catalogue fall back to an account described by hand', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listAssets.mockImplementation(() =>
      success({ items: [euro], page: 1, perPage: 100, total: 1 }),
    );
    api.listProducts.mockImplementation(() => failure(500));
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier compte' }));
    expect(
      await screen.findByText(
        'Le catalogue produits n’a pas pu être chargé. Réessayez, ou décrivez le compte à la main.',
      ),
    ).toBeTruthy();
    // "Aucun produit" is selected by default, so the catalogue failing to load
    // never blocks describing the account by hand.
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));

    expect(screen.getByLabelText('Libellé')).toBeTruthy();
  });

  it('refuses to create an account when the asset reference cannot be read', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listAssets.mockImplementation(() => failure(500));
    api.listProducts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier compte' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Continuer' }));
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Compte courant' } });
    fireEvent.change(screen.getByLabelText('Date d’ouverture'), {
      target: { value: '2026-01-10' },
    });
    expect(
      await screen.findByText('Impossible de charger le référentiel des devises.'),
    ).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));

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
    api.listProducts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier compte' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Continuer' }));
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Compte courant' } });
    fireEvent.change(screen.getByLabelText('Date d’ouverture'), {
      target: { value: '2026-01-10' },
    });
    fireEvent.change(screen.getByLabelText('Fin d’identifiant'), {
      target: { value: 'FR763000' },
    });
    fireEvent.change(screen.getByLabelText('Date de clôture'), { target: { value: '2026-01-09' } });
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));

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
    // The account follows a catalogue model, so its kind is the product's to
    // state rather than the form's to offer.
    const kind = (await screen.findByLabelText('Nature du compte')) as HTMLSelectElement;
    expect(kind.disabled).toBe(true);
    expect(screen.getByText('La nature est imposée par le produit « Livret A ».')).toBeTruthy();

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
    fireEvent.click(await screen.findByRole('button', { name: 'Enregistrer' }));
    expect(
      await screen.findByText('Un compte actif porte déjà ce libellé. Choisissez-en un autre.'),
    ).toBeTruthy();

    cleanup();
    vi.clearAllMocks();
    api.readProduct.mockImplementation(() => success(livretA));
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 50, total: 1 }),
    );
    api.updateAccount.mockImplementation(() => problem(409, '/problems/stale-version'));
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Modifier le compte Livret A Banque X' }),
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Enregistrer' }));
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

  it('reads the dated rules of an account, including one that is archived', async () => {
    const archived = {
      ...account,
      id: '00000000-0000-7000-8000-0000000000d3',
      label: 'Livret A clos',
      status: 'ARCHIVED' as const,
      editable: false,
    };
    api.listAccounts.mockImplementation(() =>
      success({ items: [archived], page: 1, perPage: 50, total: 1 }),
    );
    api.readAccountRules.mockImplementation(() =>
      success({
        accountId: archived.id,
        assetCode: 'EUR',
        productCode: 'FR_LIVRET_A',
        productModelId: null,
        origin: 'SYSTEM_CATALOG',
        asOf: '2026-09-03',
        ceilings: [
          {
            kind: 'DEPOSIT_CEILING',
            basis: 'BALANCE_EXCLUDING_INTEREST',
            countsCreditedInterest: false,
            spansSeveralAccounts: false,
            measurable: true,
            breachPolicy: 'WARN',
            amount: { value: '22950', assetCode: 'EUR' },
            validFrom: '2025-04-25',
            validTo: null,
            verification: 'VERIFIED',
            source: livretA.rules[0]!.source,
          },
        ],
        rates: [],
        terms: [],
        unavailableRuleKinds: ['ANNUAL_RATE'],
      }),
    );
    renderPage();

    const rules = await screen.findByRole('button', {
      name: 'Règles applicables au compte Livret A clos',
    });
    // Editing an archived account is refused, but reading what applied to it
    // changes nothing and stays available.
    const edit = screen.getByRole('button', { name: 'Modifier le compte Livret A clos' });
    expect((edit as HTMLButtonElement).disabled).toBe(true);
    expect((rules as HTMLButtonElement).disabled).toBe(false);
    fireEvent.click(rules);

    expect(screen.getByRole('dialog', { name: 'Règles de « Livret A clos »' })).toBeTruthy();
    expect(await screen.findByText(/22\s950\s€/)).toBeTruthy();
    expect(api.readAccountRules.mock.calls[0]?.[0].path).toEqual({ id: archived.id });
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
