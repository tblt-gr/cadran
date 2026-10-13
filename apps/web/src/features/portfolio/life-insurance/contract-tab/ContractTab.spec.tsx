import { summaryFixture } from '@/features/portfolio/life-insurance/overview/summaryFixture';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { ContractTab } from './ContractTab';
import {
  contractFixture,
  overviewFixture,
} from '@/features/portfolio/life-insurance/lifeInsuranceFixtures';
const api = vi.hoisted(() => ({
  readLifeInsuranceContract: vi.fn(),
  readLifeInsuranceSummary: vi.fn(),
  saveLifeInsuranceContract: vi.fn(),
  addLifeInsuranceFeeTerms: vi.fn(),
  removeLifeInsuranceFeeTerms: vi.fn(),
  recordEuroFundRate: vi.fn(),
  removeEuroFundRate: vi.fn(),
  listInstruments: vi.fn(),
  getSession: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const ok = (data: unknown, status = 200) => ({
  data,
  response: new Response(status === 204 ? null : '{}', { status }),
});
function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  client.setQueryData(['session'], {
    workspace: { timeZone: 'Europe/Paris', baseCurrency: 'EUR' },
  });
  return render(
    <QueryClientProvider client={client}>
      <ContractTab accountId="account-1" assetCode="EUR" />
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  vi.resetAllMocks();
  api.readLifeInsuranceContract.mockResolvedValue(ok(overviewFixture()));
  api.readLifeInsuranceSummary.mockResolvedValue(ok(summaryFixture()));
  api.listInstruments.mockResolvedValue(ok({ items: [] }));
});
afterEach(cleanup);
describe('ContractTab', () => {
  it('keeps real titles and table headers in its loading layout', () => {
    api.readLifeInsuranceContract.mockReturnValue(new Promise(() => {}));
    mount();
    expect(screen.getByTestId('contract-skeleton')).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Contrat' })).toBeTruthy();
    expect(screen.getByRole('columnheader', { name: 'Gestion UC' })).toBeTruthy();
    expect(screen.getByRole('columnheader', { name: 'Taux servi' })).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Modifier' }) as HTMLButtonElement).disabled).toBe(
      true,
    );
  });
  it('offers the account link after a full surrender closes the contract', async () => {
    api.readLifeInsuranceContract.mockResolvedValue(
      ok({
        ...overviewFixture(),
        contract: { ...contractFixture(), status: 'CLOSED', closedOn: '2026-06-10' },
      }),
    );
    mount();
    expect(await screen.findByText(/Contrat soldé le 10\/06\/2026/)).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Voir le compte' }).getAttribute('href')).toBe(
      '/accounts/account-1',
    );
  });
  it('creates through the shared modal from the empty state', async () => {
    api.readLifeInsuranceContract.mockResolvedValue(ok({ ...overviewFixture(), contract: null }));
    mount();
    fireEvent.click(await screen.findByRole('button', { name: 'Renseigner le contrat' }));
    const modal = screen.getByRole('dialog', { name: 'Renseigner le contrat' });
    expect(within(modal).getByLabelText('Assureur')).toBeTruthy();
    expect(
      screen.getByText("Renseignez le contrat d'assurance-vie pour enregistrer ses opérations."),
    ).toBeTruthy();
    fireEvent.keyDown(modal, { key: 'Escape' });
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });
  it('does not refill the masked number and omits an unchanged number', async () => {
    api.saveLifeInsuranceContract.mockResolvedValue(ok(contractFixture()));
    mount();
    await screen.findByText('•••• 2345');
    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
    const number = screen.getByLabelText('Numéro de contrat') as HTMLInputElement;
    expect(number.value).toBe('');
    expect(screen.getByText('Actuel : •••• 2345. Laissez vide pour le conserver.')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.saveLifeInsuranceContract).toHaveBeenCalled());
    expect(api.saveLifeInsuranceContract.mock.calls[0][0].body.contractNumber).toBeUndefined();
    expect(api.saveLifeInsuranceContract.mock.calls[0][0].body.version).toBe(3);
  });
  it('clears the number only through its explicit checkbox', async () => {
    api.saveLifeInsuranceContract.mockResolvedValue(ok(contractFixture()));
    mount();
    await screen.findByText('•••• 2345');
    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
    fireEvent.click(screen.getByLabelText('Effacer le numéro'));
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.saveLifeInsuranceContract).toHaveBeenCalled());
    expect(api.saveLifeInsuranceContract.mock.calls[0][0].body.contractNumber).toBeNull();
  });
  it('shows empty fees, empty rates and stale rate warnings without invented values', async () => {
    mount();
    expect(await screen.findByText('Aucun barème de frais renseigné.')).toBeTruthy();
    expect(screen.getByText('Aucun taux renseigné.')).toBeTruthy();
    expect(screen.getByText(/^Taux \d{4} non renseigné$/)).toBeTruthy();
    expect(
      screen.getByText("Taux annoncés par l'assureur, distincts de la performance du contrat."),
    ).toBeTruthy();
    expect(screen.getAllByText('Non renseigné').length).toBeGreaterThan(0);
  });
  it('reloads after a stale save and asks to reopen the current contract', async () => {
    api.saveLifeInsuranceContract.mockResolvedValue({
      error: { code: 'stale_contract_version' },
      response: new Response('{}', { status: 409 }),
    });
    mount();
    await screen.findByText('•••• 2345');
    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByRole('alert')).toHaveProperty(
      'textContent',
      expect.stringContaining('modifié'),
    );
    await waitFor(() => expect(api.readLifeInsuranceContract.mock.calls.length).toBeGreaterThan(1));
  });
  it('retains the edited baseline after conflict and loads concurrent changes only on reopening', async () => {
    const initial = overviewFixture();
    initial.contract = { ...contractFixture(), version: 1 };
    const refreshed = {
      ...initial,
      contract: {
        ...initial.contract,
        version: 2,
        insurer: 'Assureur concurrent',
        contractNumberMasked: '•••• 9876',
      },
    };
    api.readLifeInsuranceContract
      .mockResolvedValueOnce(ok(initial))
      .mockResolvedValue(ok(refreshed));
    api.saveLifeInsuranceContract.mockResolvedValue({
      error: { code: 'stale_contract_version' },
      response: new Response('{}', { status: 409 }),
    });
    mount();
    await screen.findByText('•••• 2345');
    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
    const modal = screen.getByRole('dialog', { name: 'Modifier le contrat' });
    fireEvent.change(within(modal).getByLabelText('Assureur'), {
      target: { value: 'Mon assureur saisi' },
    });
    fireEvent.click(within(modal).getByRole('button', { name: 'Enregistrer' }));
    await screen.findByText('Assureur concurrent');
    expect((within(modal).getByLabelText('Assureur') as HTMLInputElement).value).toBe(
      'Mon assureur saisi',
    );
    expect(
      within(modal).getByText('Actuel : •••• 2345. Laissez vide pour le conserver.'),
    ).toBeTruthy();
    await waitFor(() =>
      expect(
        (within(modal).getByRole('button', { name: 'Enregistrer' }) as HTMLButtonElement).disabled,
      ).toBe(false),
    );
    fireEvent.click(within(modal).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.saveLifeInsuranceContract).toHaveBeenCalledTimes(2));
    expect(api.saveLifeInsuranceContract.mock.calls[1][0].body).toMatchObject({
      version: 1,
      insurer: 'Mon assureur saisi',
    });
    fireEvent.keyDown(modal, { key: 'Escape' });
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
    const reopened = screen.getByRole('dialog', { name: 'Modifier le contrat' });
    expect((within(reopened).getByLabelText('Assureur') as HTMLInputElement).value).toBe(
      'Assureur concurrent',
    );
    expect(
      within(reopened).getByText('Actuel : •••• 9876. Laissez vide pour le conserver.'),
    ).toBeTruthy();
    fireEvent.click(within(reopened).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.saveLifeInsuranceContract).toHaveBeenCalledTimes(3));
    expect(api.saveLifeInsuranceContract.mock.calls[2][0].body.version).toBe(2);
  });
  it('keeps creation as creation when a concurrent contract appears', async () => {
    api.readLifeInsuranceContract
      .mockResolvedValueOnce(ok({ ...overviewFixture(), contract: null }))
      .mockResolvedValue(ok(overviewFixture()));
    api.saveLifeInsuranceContract.mockResolvedValue({
      error: { code: 'life_insurance_contract_exists' },
      response: new Response('{}', { status: 409 }),
    });
    mount();
    fireEvent.click(await screen.findByRole('button', { name: 'Renseigner le contrat' }));
    const modal = screen.getByRole('dialog', { name: 'Renseigner le contrat' });
    fireEvent.change(within(modal).getByLabelText('Assureur'), {
      target: { value: 'Mon assureur' },
    });
    fireEvent.change(within(modal).getByLabelText('Souscrit le'), {
      target: { value: '2020-01-01' },
    });
    fireEvent.click(within(modal).getByRole('button', { name: 'Enregistrer' }));
    await screen.findByText('•••• 2345');
    await waitFor(() =>
      expect(
        (within(modal).getByRole('button', { name: 'Enregistrer' }) as HTMLButtonElement).disabled,
      ).toBe(false),
    );
    expect(screen.getByRole('dialog', { name: 'Renseigner le contrat' })).toBeTruthy();
    expect(within(modal).queryByLabelText('Effacer le numéro')).toBeNull();
    fireEvent.click(within(modal).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.saveLifeInsuranceContract).toHaveBeenCalledTimes(2));
    expect(api.saveLifeInsuranceContract.mock.calls[1][0].body.version).toBeUndefined();
  });
  it('shows exact sourced values and confirms deletion before mutating', async () => {
    const overview = overviewFixture();
    overview.feeTerms = [
      {
        id: 'fee-1',
        validFrom: '2020-01-01',
        entryFeeRate: '0',
        euroFundManagementFeeRate: '0.60',
        unitLinkedManagementFeeRate: '0.85',
        switchFeeRate: null,
        switchFeeFixed: { value: '1234567890123456789.12', assetCode: 'EUR' },
        sourceNote: 'Notice 2020',
      },
    ];
    overview.euroFundRates = [
      {
        id: 'rate-1',
        instrumentId: 'euro-1',
        year: 2025,
        servedRate: '2.80',
        bonusRate: null,
        basis: 'NET_OF_MANAGEMENT_FEES',
        sourceNote: 'Avis annuel',
      },
    ];
    api.readLifeInsuranceContract.mockResolvedValue(ok(overview));
    api.removeLifeInsuranceFeeTerms.mockResolvedValue(ok(undefined, 204));
    mount();
    await screen.findByText('•••• 2345');
    expect(screen.getByText('0 %')).toBeTruthy();
    expect(screen.getByText('0,60 %')).toBeTruthy();
    expect(screen.getByText('2,80 %')).toBeTruthy();
    expect(screen.getByText('Net de frais de gestion')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Supprimer le barème du 01/01/2020' }));
    expect(api.removeLifeInsuranceFeeTerms).not.toHaveBeenCalled();
    fireEvent.click(
      within(screen.getByRole('dialog', { name: 'Supprimer le barème' })).getByRole('button', {
        name: 'Supprimer',
      }),
    );
    await waitFor(() =>
      expect(api.removeLifeInsuranceFeeTerms).toHaveBeenCalledWith(
        expect.objectContaining({ path: { id: 'fee-1' } }),
      ),
    );
  });
  it('sends percentage and fixed fee inputs as exact decimal strings', async () => {
    api.addLifeInsuranceFeeTerms.mockResolvedValue(ok({}, 201));
    mount();
    await screen.findByText('•••• 2345');
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter un barème' }));
    fireEvent.change(screen.getByLabelText('Gestion UC (%)'), { target: { value: '0,8500' } });
    fireEvent.change(screen.getByLabelText('Arbitrage fixe (EUR)'), {
      target: { value: '1234567890123456789,12' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter le barème' }));
    await waitFor(() => expect(api.addLifeInsuranceFeeTerms).toHaveBeenCalled());
    expect(api.addLifeInsuranceFeeTerms.mock.calls[0][0].body).toMatchObject({
      unitLinkedManagementFeeRate: '0.8500',
      entryFeeRate: null,
      switchFeeFixed: { value: '1234567890123456789.12', assetCode: 'EUR' },
    });
  });
  it('keeps the create modal open when the API refuses duplicate fees', async () => {
    api.addLifeInsuranceFeeTerms.mockResolvedValue({
      error: { code: 'fee_terms_exist' },
      response: new Response('{}', { status: 409 }),
    });
    mount();
    await screen.findByText('•••• 2345');
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter un barème' }));
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter le barème' }));
    expect(
      await screen.findByText('Un barème existe déjà à cette date. Choisissez une autre date.'),
    ).toBeTruthy();
    expect(screen.getByRole('dialog', { name: 'Ajouter un barème' })).toBeTruthy();
  });
  it('records a served rate on an inactive euro fund without using contract performance', async () => {
    const instrument = overviewFixture().euroFunds[0]!.instrument;
    api.listInstruments.mockResolvedValue(
      ok({ items: [{ ...instrument, status: 'INACTIVE' }], total: 1 }),
    );
    api.recordEuroFundRate.mockResolvedValue(ok({}, 201));
    mount();
    await screen.findByText('•••• 2345');
    fireEvent.click(screen.getByRole('button', { name: 'Renseigner un taux' }));
    await screen.findByRole('option', { name: 'Fonds euros Démo (inactif)' });
    fireEvent.change(screen.getByLabelText('Support'), { target: { value: 'euro-1' } });
    fireEvent.change(screen.getByLabelText('Année'), { target: { value: '2025' } });
    fireEvent.change(screen.getByLabelText('Taux servi (%)'), { target: { value: '-2,8000' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer le taux' }));
    await waitFor(() => expect(api.recordEuroFundRate).toHaveBeenCalled());
    expect(api.listInstruments.mock.calls[0][0].query).toMatchObject({
      status: 'all',
      pageSize: 50,
    });
    expect(api.recordEuroFundRate.mock.calls[0][0].body).toMatchObject({
      instrumentId: 'euro-1',
      year: 2025,
      servedRate: '-2.8000',
      bonusRate: null,
      basis: 'UNKNOWN',
    });
  });
  it('retries a failed request', async () => {
    api.readLifeInsuranceContract.mockResolvedValueOnce({
      response: new Response('{}', { status: 500 }),
    });
    mount();
    fireEvent.click(await screen.findByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByText('•••• 2345')).toBeTruthy();
  });
});
