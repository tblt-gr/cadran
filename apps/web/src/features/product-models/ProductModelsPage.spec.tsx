import type { Product, ProductModel } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { ProductModelsPage } from './ProductModelsPage';

const api = vi.hoisted(() => ({
  addProductModelRule: vi.fn(),
  archiveProductModel: vi.fn(),
  createProductModel: vi.fn(),
  createProductModelFromProduct: vi.fn(),
  duplicateProductModel: vi.fn(),
  listProductModels: vi.fn(),
  listProducts: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const model: ProductModel = {
  id: '00000000-0000-7000-8000-0000000000a1',
  name: 'Livret Banque X',
  family: 'SAVINGS',
  nature: 'ASSET',
  wrapperKind: 'NONE',
  yieldKind: 'CONTRACTUAL_FIXED',
  yieldGuaranteed: true,
  ceilingBasis: 'NONE',
  defaultGroupCode: 'SAVINGS',
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
  asOf: '2026-09-04',
  rules: [],
  unavailableRuleKinds: [],
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
      <ProductModelsPage />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('ProductModelsPage', () => {
  it('describes a model by hand and records no period with it', async () => {
    api.listProductModels.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listProducts.mockImplementation(() =>
      success({ items: [livretA], page: 1, perPage: 100, total: 1 }),
    );
    api.createProductModel.mockImplementation(({ body }) => success({ ...model, ...body }, 201));
    const { container } = renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer le premier modèle' }));
    expect(screen.getByRole('dialog', { name: 'Nouveau modèle de produit' })).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Nom du modèle'), {
      target: { value: 'Livret Banque X' },
    });
    fireEvent.change(screen.getByLabelText('Rendement'), {
      target: { value: 'CONTRACTUAL_FIXED' },
    });
    expect(await screen.findByRole('option', { name: 'Épargne liquide' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Groupe patrimonial par défaut'), {
      target: { value: 'LIQUIDITY_SAVINGS' },
    });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Intérêts' }));
    fireEvent.click(screen.getByRole('button', { name: 'Créer le modèle' }));

    await waitFor(() => expect(api.createProductModel).toHaveBeenCalledOnce());
    const body = api.createProductModel.mock.calls[0]?.[0].body;
    expect(body).toMatchObject({
      name: 'Livret Banque X',
      family: 'SAVINGS',
      wrapperKind: 'NONE',
      yieldKind: 'CONTRACTUAL_FIXED',
      defaultGroupCode: 'LIQUIDITY_SAVINGS',
      valuationMode: 'TRANSACTIONS',
      rules: [],
    });
    // The valuation mode's capability is kept on whatever the user toggled.
    expect(body.capabilities).toEqual(
      expect.arrayContaining(['SUPPORTS_TRANSACTIONS', 'SUPPORTS_INTEREST']),
    );
    const toast = await screen.findByText('Le modèle a été enregistré.');
    expect(toast.closest('[role="status"]')).toBeTruthy();
    expect(container.contains(toast)).toBe(false);
  });

  it('records a tiered rate period as canonical decimal strings with its mode', async () => {
    api.listProductModels.mockImplementation(() =>
      success({ items: [model], page: 1, perPage: 50, total: 1 }),
    );
    api.addProductModelRule.mockImplementation(({ body }) =>
      success({ ...model, version: 2, rules: [{ id: 'r1', ...body }] }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Ajouter une période au modèle Livret Banque X' }),
    );
    fireEvent.change(screen.getByLabelText('Nature de la règle'), {
      target: { value: 'ANNUAL_RATE' },
    });
    fireEvent.click(screen.getByRole('radio', { name: 'Barème par tranches' }));

    fireEvent.change(screen.getByLabelText('Borne basse de la tranche 1'), {
      target: { value: '0' },
    });
    fireEvent.change(screen.getByLabelText('Borne haute de la tranche 1'), {
      target: { value: '10000' },
    });
    fireEvent.change(screen.getByLabelText('Taux de la tranche 1'), { target: { value: '4' } });
    fireEvent.change(screen.getByLabelText('Borne basse de la tranche 2'), {
      target: { value: '10000' },
    });
    fireEvent.change(screen.getByLabelText('Taux de la tranche 2'), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText('Date de début'), { target: { value: '2026-01-01' } });

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la période' }));

    await waitFor(() => expect(api.addProductModelRule).toHaveBeenCalledOnce());
    const call = api.addProductModelRule.mock.calls[0]?.[0];
    expect(call.path).toEqual({ id: model.id });
    expect(call.body).toEqual({
      kind: 'ANNUAL_RATE',
      amount: null,
      amountAssetCode: null,
      text: null,
      rateApplication: 'MARGINAL',
      brackets: [
        { lowerBound: '0', upperBound: '10000', percentage: '4' },
        { lowerBound: '10000', upperBound: null, percentage: '2' },
      ],
      validFrom: '2026-01-01',
      validTo: null,
      version: 1,
    });
  });

  it('starts a model from a catalogue product, sending only its code', async () => {
    api.listProductModels.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.listProducts.mockImplementation(() =>
      success({ items: [livretA], page: 1, perPage: 100, total: 1 }),
    );
    api.createProductModelFromProduct.mockImplementation(({ body }) =>
      success({ ...model, origin: 'SYSTEM_PRODUCT', basedOnProductCode: body.productCode }, 201),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Partir d’un produit' }));
    expect(
      await screen.findByRole('dialog', { name: 'Partir d’un produit du catalogue' }),
    ).toBeTruthy();

    fireEvent.change(await screen.findByLabelText('Nom du modèle'), {
      target: { value: 'Livret A Banque X' },
    });
    fireEvent.change(screen.getByLabelText('Produit du catalogue'), {
      target: { value: 'FR_LIVRET_A' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Créer le modèle' }));

    await waitFor(() => expect(api.createProductModelFromProduct).toHaveBeenCalledOnce());
    expect(api.createProductModelFromProduct.mock.calls[0]?.[0].body).toEqual({
      name: 'Livret A Banque X',
      productCode: 'FR_LIVRET_A',
      valuationMode: 'TRANSACTIONS',
    });
  });

  it('duplicates a model by name and archives one through a confirmation', async () => {
    api.listProductModels.mockImplementation(() =>
      success({ items: [model], page: 1, perPage: 50, total: 1 }),
    );
    api.duplicateProductModel.mockImplementation(({ body }) =>
      success({ ...model, id: 'copy', name: body.name, origin: 'WORKSPACE_MODEL' }, 201),
    );
    api.archiveProductModel.mockImplementation(() =>
      success({ ...model, editable: false, archivedAt: '2026-09-04T00:00:00Z', version: 2 }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Dupliquer le modèle Livret Banque X' }),
    );
    const nameField = screen.getByLabelText('Nom de la copie') as HTMLInputElement;
    expect(nameField.value).toBe('Livret Banque X (copie)');
    fireEvent.click(screen.getByRole('button', { name: 'Dupliquer' }));
    await waitFor(() => expect(api.duplicateProductModel).toHaveBeenCalledOnce());
    expect(api.duplicateProductModel.mock.calls[0]?.[0].body).toEqual({
      name: 'Livret Banque X (copie)',
    });

    fireEvent.click(
      await screen.findByRole('button', { name: 'Archiver le modèle Livret Banque X' }),
    );
    expect(screen.getByRole('dialog', { name: 'Archiver le modèle' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Archiver' }));
    await waitFor(() => expect(api.archiveProductModel).toHaveBeenCalledOnce());
    expect(api.archiveProductModel.mock.calls[0]?.[0].body).toEqual({ version: 1 });
    expect(await screen.findByText('Le modèle a été archivé.')).toBeTruthy();
  });

  it('separates a taken name from a stale version and shows the unauthorized state', async () => {
    api.listProductModels.mockImplementation(() =>
      success({ items: [model], page: 1, perPage: 50, total: 1 }),
    );
    api.duplicateProductModel.mockImplementation(() =>
      problem(409, '/problems/product-model-name-taken'),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Dupliquer le modèle Livret Banque X' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Dupliquer' }));
    expect(
      await screen.findByText(
        'Un modèle actif de cet espace porte déjà ce nom. Choisissez-en un autre.',
      ),
    ).toBeTruthy();

    cleanup();
    vi.clearAllMocks();
    api.listProductModels.mockImplementation(() =>
      success({ items: [model], page: 1, perPage: 50, total: 1 }),
    );
    api.archiveProductModel.mockImplementation(() => problem(409, '/problems/stale-version'));
    renderPage();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Archiver le modèle Livret Banque X' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Archiver' }));
    expect(
      await screen.findByText(
        'Ce modèle a été modifié ailleurs entre-temps. La liste vient d’être rechargée : rouvrez le modèle puis réappliquez votre modification.',
      ),
    ).toBeTruthy();

    cleanup();
    api.listProductModels.mockImplementation(() => failure(401));
    renderPage();
    expect(await screen.findByRole('heading', { name: 'Session expirée' })).toBeTruthy();
  });

  it('keeps an archived model readable while refusing to change it', async () => {
    const archived: ProductModel = {
      ...model,
      id: '00000000-0000-7000-8000-0000000000a2',
      name: 'Livret retiré',
      editable: false,
      archivedAt: '2026-09-04T00:00:00Z',
      version: 3,
      rules: [
        {
          id: 'rule-1',
          kind: 'BALANCE_CEILING',
          valueType: 'AMOUNT',
          amount: { value: '30000', assetCode: 'EUR' },
          text: null,
          rateApplication: null,
          brackets: [],
          validFrom: '2026-01-01',
          validTo: null,
        },
      ],
    };
    api.listProductModels.mockImplementation(() =>
      success({ items: [archived], page: 1, perPage: 50, total: 1 }),
    );
    renderPage();

    const inspect = await screen.findByRole('button', {
      name: 'Voir les périodes du modèle Livret retiré',
    });
    expect(
      (
        screen.getByRole('button', {
          name: 'Ajouter une période au modèle Livret retiré',
        }) as HTMLButtonElement
      ).disabled,
    ).toBe(true);
    expect((inspect as HTMLButtonElement).disabled).toBe(false);
    fireEvent.click(inspect);

    expect(screen.getByRole('dialog', { name: 'Périodes de « Livret retiré »' })).toBeTruthy();
    expect(screen.getByText('Plafond de solde')).toBeTruthy();
    expect(screen.getByText(/30\s?000/)).toBeTruthy();
  });

  it('covers the loading and error states of the list', async () => {
    api.listProductModels.mockImplementation(() => new Promise(() => undefined));
    renderPage();
    expect(screen.getByRole('heading', { name: 'Chargement des modèles…' })).toBeTruthy();

    cleanup();
    vi.clearAllMocks();
    api.listProductModels.mockImplementation(() => failure(500));
    renderPage();
    expect(await screen.findByRole('heading', { name: 'Modèles indisponibles' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(api.listProductModels).toHaveBeenCalledTimes(2);
  });

  it('keeps a locale-formatted rate on the field instead of sending it', async () => {
    api.listProductModels.mockImplementation(() =>
      success({ items: [model], page: 1, perPage: 50, total: 1 }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Ajouter une période au modèle Livret Banque X' }),
    );
    fireEvent.change(screen.getByLabelText('Nature de la règle'), {
      target: { value: 'ANNUAL_RATE' },
    });
    fireEvent.change(screen.getByLabelText('Taux (%)'), { target: { value: '4,5' } });
    fireEvent.change(screen.getByLabelText('Date de début'), { target: { value: '2026-01-01' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la période' }));

    expect(api.addProductModelRule).not.toHaveBeenCalled();
    expect(
      screen.getByText(
        'Saisissez des bornes et des taux décimaux, par exemple 0, 10000 et 4. N’utilisez ni virgule, ni zéro en tête.',
      ),
    ).toBeTruthy();
  });
});
