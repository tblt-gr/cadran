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
  listAccountGroups: vi.fn(),
  listAssets: vi.fn(),
  listProductModels: vi.fn(),
  listProducts: vi.fn(),
  listAccountRuleOverrides: vi.fn(),
  readAccountRules: vi.fn(),
  recordAccountBalance: vi.fn(),
  recordAccountRuleOverride: vi.fn(),
  withdrawAccountRuleOverride: vi.fn(),
  readProduct: vi.fn(),
  readProductModel: vi.fn(),
  updateAccount: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const missingValuation = {
  accountId: '00000000-0000-7000-8000-0000000000d1',
  requestedOn: '2026-01-10',
  asOf: null,
  amount: null,
  display: null,
  belowDisplayStep: false,
  source: null,
  ageDays: null,
  quality: 'MISSING' as const,
  reconciliationStatus: null,
  snapshotId: null,
  version: null,
};

const staleValuation = {
  ...missingValuation,
  requestedOn: '2026-09-05',
  asOf: '2026-09-03',
  amount: { value: '231.10', assetCode: 'EUR' },
  display: { value: '231.10', assetCode: 'EUR' },
  source: 'MANUAL' as const,
  ageDays: 2,
  quality: 'STALE' as const,
  reconciliationStatus: 'UNRECONCILED' as const,
  snapshotId: '00000000-0000-7000-8000-0000000000b1',
  version: 1,
};

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
  primaryGroupId: null,
  tagGroupIds: [],
  share: { ratio: null, percent: null, reason: 'MISSING_VALUATION' },
  valuation: missingValuation,
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

const publishedCeiling = {
  measurable: true,
  amount: { value: '22950', assetCode: 'EUR' },
  validFrom: '2025-04-25',
  validTo: null,
  verification: 'VERIFIED' as const,
  source: livretA.rules[0]!.source,
  claim: null,
};

const localClaim = {
  overrideId: '00000000-0000-7000-8000-0000000000c1',
  reason: 'La banque a confirmé un plafond plus élevé par écrit.',
  authorId: '00000000-0000-7000-8000-000000000001',
  recordedAt: '2026-09-04T09:00:00+00:00',
};

const passbookRules = {
  accountId: account.id,
  assetCode: 'EUR',
  productCode: 'FR_LIVRET_A',
  productModelId: null,
  origin: 'SYSTEM_CATALOG' as const,
  asOf: '2026-09-03',
  ceilings: [
    {
      kind: 'DEPOSIT_CEILING' as const,
      basis: 'BALANCE_EXCLUDING_INTEREST' as const,
      countsCreditedInterest: false,
      spansSeveralAccounts: false,
      effectiveLayer: 'CATALOG' as const,
      catalog: publishedCeiling,
      inherited: null,
      override: null,
    },
  ],
  rates: [],
  terms: [],
  unavailableRuleKinds: [],
};

/** The same passbook, with a local ceiling recorded in front of the published one. */
const claimedRules = {
  ...passbookRules,
  ceilings: [
    {
      ...passbookRules.ceilings[0]!,
      effectiveLayer: 'OVERRIDE' as const,
      override: {
        ...publishedCeiling,
        amount: { value: '30000', assetCode: 'EUR' },
        verification: null,
        source: null,
        claim: localClaim,
      },
    },
  ],
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
    // Reading the rules of an account also reads the claims recorded against
    // it; an account that never claimed anything is the default.
    api.listAccountRuleOverrides.mockImplementation(() => success({ overrides: [] }));
    api.listAccountGroups.mockImplementation(() =>
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
      primaryGroupId: null,
      tagGroupIds: [],
    });
    const toast = await screen.findByText('Le compte a été enregistré.');
    expect(toast.closest('[role="status"]')).toBeTruthy();
    expect(container.contains(toast)).toBe(false);
  });

  it('lets a create choose an optional exclusive group', async () => {
    const epargne = {
      id: '00000000-0000-7000-8000-0000000000b1',
      label: 'Épargne',
      parentId: null,
      parentLabel: null,
      sortOrder: 0,
      depth: 1,
      version: 1,
      hasChildren: false,
      canAcceptChildren: true,
      share: { ratio: null, percent: null, reason: 'MISSING_VALUATION' as const },
      archivedAt: null,
    };
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listAssets.mockImplementation(() =>
      success({ items: [euro], page: 1, perPage: 100, total: 1 }),
    );
    api.listProducts.mockImplementation(() =>
      success({ items: [livretA], page: 1, perPage: 100, total: 1 }),
    );
    api.listAccountGroups.mockImplementation(() =>
      success({ items: [epargne], page: 1, perPage: 100, total: 1 }),
    );
    api.createAccount.mockImplementation(({ body }) => success({ ...account, ...body }, 201));
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier compte' }));
    fireEvent.click(await screen.findByRole('radio', { name: /Aucun produit/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Livret A Banque X' } });
    fireEvent.change(screen.getByLabelText('Date d’ouverture'), {
      target: { value: '2026-01-10' },
    });
    expect(await screen.findByRole('option', { name: 'EUR · Euro' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Groupe (optionnel)'), {
      target: { value: epargne.id },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }));
    fireEvent.click(screen.getByRole('button', { name: 'Créer le compte' }));

    await waitFor(() => expect(api.createAccount).toHaveBeenCalledOnce());
    expect(api.createAccount.mock.calls[0]?.[0].body).toMatchObject({
      label: 'Livret A Banque X',
      primaryGroupId: epargne.id,
      tagGroupIds: [],
    });
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
      'primaryGroupId',
      'productCode',
      'productModelId',
      'tagGroupIds',
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
            effectiveLayer: 'CATALOG',
            catalog: {
              measurable: true,
              amount: { value: '22950', assetCode: 'EUR' },
              validFrom: '2025-04-25',
              validTo: null,
              verification: 'VERIFIED',
              source: livretA.rules[0]!.source,
              claim: null,
            },
            inherited: null,
            override: null,
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

  it('records a claim against a rule and hands it back to the rules it came from', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 50, total: 1 }),
    );
    api.readAccountRules.mockImplementation(() => success(passbookRules));
    api.recordAccountRuleOverride.mockImplementation(() =>
      success({ id: '00000000-0000-7000-8000-0000000000c1' }, 201),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Règles applicables au compte Livret A Banque X' }),
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Déroger' }));

    // Recording a claim replaces the rules dialog rather than stacking a second
    // one over it, so focus and the escape key still have one owner.
    const dialog = screen.getByRole('dialog', {
      name: 'Déroger à une règle de « Livret A Banque X »',
    });
    expect(dialog).toBeTruthy();

    // The kinds list is already the ones this account may state. Re-filtering
    // it against an empty capability set would disable the selected option.
    const kind = screen.getByLabelText('Nature de la règle') as HTMLSelectElement;
    expect(kind.selectedOptions[0]?.value).toBe('DEPOSIT_CEILING');
    expect(kind.selectedOptions[0]?.disabled).toBe(false);

    // The reason is required: an unexplained local figure sitting beside a
    // published one is the drift the whole feature exists to make visible.
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '30000' } });
    fireEvent.change(screen.getByLabelText('Date de début'), { target: { value: '2026-01-01' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la dérogation' }));
    expect(api.recordAccountRuleOverride).not.toHaveBeenCalled();
    expect(
      screen.getByText('Le motif est obligatoire et ne dépasse pas 200 caractères.'),
    ).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Motif'), {
      target: { value: 'La banque a confirmé un plafond plus élevé par écrit.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la dérogation' }));

    await waitFor(() =>
      expect(api.recordAccountRuleOverride).toHaveBeenCalledWith(
        expect.objectContaining({
          path: { id: account.id },
          body: expect.objectContaining({
            kind: 'DEPOSIT_CEILING',
            amount: '30000',
            // The unit is the account's own and is never offered as a choice.
            amountAssetCode: 'EUR',
            validFrom: '2026-01-01',
            validTo: null,
            reason: 'La banque a confirmé un plafond plus élevé par écrit.',
          }),
        }),
      ),
    );
    expect(await screen.findByText('La dérogation a été enregistrée sur ce compte.')).toBeTruthy();
  });

  it('refuses a second claim over the same dates and says which correction is expected', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 50, total: 1 }),
    );
    api.readAccountRules.mockImplementation(() => success(passbookRules));
    api.recordAccountRuleOverride.mockImplementation(() =>
      problem(409, '/problems/account-rule-override-conflict'),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Règles applicables au compte Livret A Banque X' }),
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Déroger' }));
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '30000' } });
    fireEvent.change(screen.getByLabelText('Date de début'), { target: { value: '2026-01-01' } });
    fireEvent.change(screen.getByLabelText('Motif'), { target: { value: 'Accord de la banque.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la dérogation' }));

    // A conflict asks the reader to reload and reapply, not to correct a field.
    expect(
      await screen.findByText(
        'Une dérogation en vigueur couvre déjà ces dates pour cette règle, ou vient d’être retirée par une autre requête. Rechargez les dérogations du compte puis réessayez.',
      ),
    ).toBeTruthy();
  });

  it('withdraws a claim after saying that it stops applying to past dates too', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 50, total: 1 }),
    );
    api.readAccountRules.mockImplementation(() => success(claimedRules));
    api.withdrawAccountRuleOverride.mockImplementation(() =>
      success({ id: '00000000-0000-7000-8000-0000000000c1' }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Règles applicables au compte Livret A Banque X' }),
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Retirer la dérogation' }));

    const dialog = screen.getByRole('dialog', { name: 'Retirer cette dérogation' });
    expect(dialog.textContent).toContain('y compris passées');
    // The claim being taken back is quoted, so the confirmation is about a
    // specific figure rather than about "the override".
    expect(dialog.textContent).toContain('La banque a confirmé un plafond plus élevé par écrit.');

    fireEvent.click(screen.getByRole('button', { name: 'Retirer la dérogation' }));

    await waitFor(() =>
      expect(api.withdrawAccountRuleOverride).toHaveBeenCalledWith(
        expect.objectContaining({
          path: { id: account.id, overrideId: '00000000-0000-7000-8000-0000000000c1' },
          body: {},
        }),
      ),
    );
    expect(
      await screen.findByText(
        'La dérogation a été retirée : le compte suit de nouveau la règle héritée.',
      ),
    ).toBeTruthy();
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

  it('names a missing valuation instead of inventing a zero balance', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 50, total: 1 }),
    );
    renderPage();

    expect(await screen.findByText('Non calculable')).toBeTruthy();
    expect(screen.getByText('Aucune valorisation')).toBeTruthy();
    expect(screen.queryByText('0,00')).toBeNull();
  });

  it('shows a carried-forward balance as stale and records a manual replacement', async () => {
    api.listAccounts.mockImplementation(() =>
      success({
        items: [{ ...account, valuation: staleValuation }],
        page: 1,
        perPage: 50,
        total: 1,
      }),
    );
    api.recordAccountBalance.mockImplementation(({ body }) =>
      success(
        {
          id: '00000000-0000-7000-8000-0000000000b2',
          accountId: account.id,
          asOf: body.asOf,
          amount: { value: body.amount, assetCode: body.amountAssetCode },
          source: 'MANUAL',
          reconciliationStatus: 'UNRECONCILED',
          comment: body.comment,
          active: true,
          version: 1,
          recordedAt: '2026-09-05T12:00:00+00:00',
          supersededAt: null,
        },
        201,
      ),
    );
    renderPage();

    expect(await screen.findByText(/231,10/)).toBeTruthy();
    expect(screen.getByText(/Ancienne/)).toBeTruthy();
    expect(screen.getByText(/2 jours/)).toBeTruthy();

    fireEvent.click(
      screen.getByRole('button', { name: 'Enregistrer un solde sur Livret A Banque X' }),
    );
    fireEvent.change(screen.getByLabelText('Date du solde'), { target: { value: '2026-09-05' } });
    fireEvent.change(screen.getByLabelText('Montant observé'), { target: { value: '240.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer le solde' }));

    await waitFor(() => expect(api.recordAccountBalance).toHaveBeenCalledOnce());
    expect(api.recordAccountBalance.mock.calls[0]?.[0].body).toMatchObject({
      asOf: '2026-09-05',
      amount: '240.00',
      amountAssetCode: 'EUR',
      comment: null,
      version: null,
    });
    expect(await screen.findByText('Le solde observé a été enregistré.')).toBeTruthy();
  });
});
