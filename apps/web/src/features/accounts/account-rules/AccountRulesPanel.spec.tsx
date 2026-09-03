import type { Account, AccountRules } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountRulesPanel } from './AccountRulesPanel';

const api = vi.hoisted(() => ({ readAccountRules: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const account = {
  id: '00000000-0000-7000-8000-0000000000d1',
  label: 'Livret A Banque X',
  assetCode: 'EUR',
  productCode: 'FR_LIVRET_A',
} as Account;

const source = {
  publisher: 'Direction de l’information légale et administrative',
  title: 'Livret A',
  url: 'https://www.service-public.fr/particuliers/vosdroits/F2365',
  publishedOn: '2025-04-25',
  retrievedOn: '2026-08-22',
};

const passbook: AccountRules = {
  accountId: account.id,
  assetCode: 'EUR',
  productCode: 'FR_LIVRET_A',
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
      source,
    },
  ],
  rates: [
    {
      kind: 'ANNUAL_RATE',
      guaranteed: true,
      application: 'WHOLE_BALANCE',
      brackets: [{ percentage: '1.7', lowerBound: '0', upperBound: null }],
      validFrom: '2026-08-01',
      validTo: '2027-01-31',
      verification: 'VERIFIED',
      source,
    },
  ],
  terms: [],
  unavailableRuleKinds: [],
};

function success(data: AccountRules) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function failure(status: number) {
  return Promise.resolve({ data: undefined, response: new Response('{}', { status }) });
}

function renderPanel() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={queryClient}>
      <AccountRulesPanel account={account} />
    </QueryClientProvider>,
  );
}

describe('AccountRulesPanel', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('shows the ceiling with the measure it is checked against and the rate of the day', async () => {
    api.readAccountRules.mockImplementation(() => success(passbook));
    renderPanel();

    expect(await screen.findByText('Plafond de dépôt')).toBeTruthy();
    expect(screen.getByText(/22\s950\s€/)).toBeTruthy();

    // The amount alone would settle nothing: a passbook carried past 22 950 €
    // by its own interest has broken no rule, so the measure is stated.
    expect(screen.getByText('Sommes déposées, hors intérêts')).toBeTruthy();
    expect(
      screen.getByText(
        'Les intérêts versés n’entrent pas dans la mesure : ils peuvent porter le compte au-delà du plafond sans qu’aucune règle soit enfreinte.',
      ),
    ).toBeTruthy();
    expect(
      screen.getByText(
        'Un dépassement de plafond est signalé, jamais refusé : les intérêts versés par l’établissement et un import historique enregistrent ce que le compte a réellement porté.',
      ),
    ).toBeTruthy();

    // A period left open is in force for every later date, so it is shown as a
    // start and never as an expiry.
    expect(screen.getByText('Depuis le 25 avril 2025')).toBeTruthy();
    expect(screen.getByText('Du 1 août 2026 au 31 janvier 2027')).toBeTruthy();

    expect(screen.getByText('1,7 %')).toBeTruthy();
    expect(screen.getByText('Appliqué à la totalité du solde')).toBeTruthy();
    expect(screen.getByText('Taux dû au titulaire')).toBeTruthy();
    // Every figure travels with the publication it was read from, so the
    // ceiling and the rate each carry their own link.
    expect(
      screen.getAllByRole('link', { name: 'Livret A' }).map((link) => link.getAttribute('href')),
    ).toEqual([source.url, source.url]);
  });

  it('resolves the rules again on the business date the reader chooses', async () => {
    api.readAccountRules.mockImplementation(({ query }: { query: { asOf: string } }) =>
      success(
        query.asOf === '2026-09-03'
          ? passbook
          : { ...passbook, asOf: query.asOf, rates: [], unavailableRuleKinds: ['ANNUAL_RATE'] },
      ),
    );
    renderPanel();

    expect(await screen.findByText('1,7 %')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Date métier'), { target: { value: '2025-06-01' } });

    // The rate of a past semester is not the rate of today, and a semester no
    // publication covers is unknown rather than nought per cent.
    expect(
      await screen.findByText(
        '— Non disponible au 1 juin 2025 : aucune valeur sourcée pour cette date.',
      ),
    ).toBeTruthy();
    expect(screen.queryByText('1,7 %')).toBeNull();
    // The ceiling has no known end, so an earlier date keeps it.
    expect(screen.getByText(/22\s950\s€/)).toBeTruthy();
    await waitFor(() =>
      expect(api.readAccountRules.mock.calls.map((call) => call[0].query.asOf)).toEqual([
        '2026-09-03',
        '2025-06-01',
      ]),
    );
  });

  it('separates the allowance of one plan from the one it shares', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        productCode: 'FR_PEA',
        rates: [],
        ceilings: [
          {
            ...passbook.ceilings[0]!,
            kind: 'CONTRIBUTION_CEILING',
            basis: 'CONTRIBUTIONS',
            amount: { value: '150000', assetCode: 'EUR' },
          },
          {
            ...passbook.ceilings[0]!,
            kind: 'COMBINED_CONTRIBUTION_CEILING',
            basis: 'COMBINED_CONTRIBUTIONS',
            spansSeveralAccounts: true,
            amount: { value: '225000', assetCode: 'EUR' },
          },
        ],
      }),
    );
    renderPanel();

    expect(await screen.findByText('Versements cumulés')).toBeTruthy();
    expect(screen.getByText('Versements cumulés de plusieurs comptes')).toBeTruthy();
    expect(
      screen.getByText(
        'Plafond atteint à plusieurs comptes : la lecture de ce compte seul ne permet pas de le trancher.',
      ),
    ).toBeTruthy();

    // A plan is not carried past its allowance by credited interest but by what
    // its assets earned, so the caveat names that and not a passbook's interest.
    expect(
      screen.getAllByText(
        'Les plus-values et les revenus du compte n’entrent pas dans la mesure : ils peuvent porter le compte au-delà du plafond sans qu’aucune règle soit enfreinte.',
      ),
    ).toHaveLength(2);
    expect(
      screen.queryByText(
        'Les intérêts versés n’entrent pas dans la mesure : ils peuvent porter le compte au-delà du plafond sans qu’aucune règle soit enfreinte.',
      ),
    ).toBeNull();
  });

  it('reports a ceiling published in another unit as not comparable', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        assetCode: 'USD',
        rates: [],
        ceilings: [{ ...passbook.ceilings[0]!, measurable: false }],
      }),
    );
    renderPanel();

    // No conversion rate ships with the application, so no verdict is produced
    // rather than a converted one.
    expect(
      await screen.findByText(
        'Plafond publié en EUR et compte tenu en USD : non comparable, aucune conversion n’est appliquée.',
      ),
    ).toBeTruthy();
  });

  it('says an account described by hand inherits no rule instead of showing none', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        productCode: null,
        origin: 'NO_PRODUCT',
        ceilings: [],
        rates: [],
      }),
    );
    renderPanel();

    expect(
      await screen.findByText(
        'Ce compte est décrit à la main : aucun produit du catalogue ne lui rattache de plafond ni de taux.',
      ),
    ).toBeTruthy();
    expect(
      screen.getByText('Aucune règle sourcée au 3 septembre 2026 pour ce compte.'),
    ).toBeTruthy();
    expect(screen.queryByRole('table')).toBeNull();
  });

  it('warns when the product left the catalogue and no rule can be resolved', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({ ...passbook, origin: 'PRODUCT_WITHDRAWN', ceilings: [], rates: [] }),
    );
    renderPanel();

    const warning = await screen.findByRole('alert');
    expect(warning.textContent).toContain('FR_LIVRET_A');
    expect(warning.textContent).toContain('ne figure plus au catalogue système');
    // The date settles nothing here: no rule can be resolved on any date. Saying
    // "aucune règle sourcée au 3 septembre 2026" beneath the alert would blame
    // the date and read as "this account has no ceiling".
    expect(screen.queryByText(/Aucune règle sourcée au/)).toBeNull();
  });

  it('offers a retry on a failed read and asks for a sign-in on an expired session', async () => {
    api.readAccountRules.mockImplementationOnce(() => failure(500));
    api.readAccountRules.mockImplementationOnce(() => success(passbook));
    renderPanel();

    expect(
      await screen.findByText('Les règles de ce compte n’ont pas pu être lues. Réessayez.'),
    ).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByText('Plafond de dépôt')).toBeTruthy();

    cleanup();
    vi.clearAllMocks();
    api.readAccountRules.mockImplementation(() => failure(401));
    renderPanel();

    expect(
      await screen.findByText('Reconnectez-vous pour consulter les règles de ce compte.'),
    ).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Réessayer' })).toBeNull();
  });
});
