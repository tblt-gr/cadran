import type {
  Account,
  AccountCeiling,
  AccountRate,
  AccountRuleOverridePage,
  AccountRules,
} from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountRulesPanel } from './AccountRulesPanel';

const api = vi.hoisted(() => ({
  listAccountRuleOverrides: vi.fn(),
  readAccountRules: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

// The panel resolves the rules on today's date by default. Pinning the day
// keeps the assertions about a chosen business date about that choice, instead
// of about the day the suite happens to run on.
const TODAY = '2026-09-03';

vi.mock('@/lib/businessDay', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/businessDay')>()),
  todayInBrowser: () => TODAY,
}));

const account = {
  id: '00000000-0000-7000-8000-0000000000d1',
  label: 'Livret A Banque X',
  assetCode: 'EUR',
  productCode: 'FR_LIVRET_A',
  openedOn: '2020-01-15',
  closedOn: null,
} as Account;

const source = {
  publisher: 'Direction de l’information légale et administrative',
  title: 'Livret A',
  url: 'https://www.service-public.fr/particuliers/vosdroits/F2365',
  publishedOn: '2025-04-25',
  retrievedOn: '2026-08-22',
};

const publishedCeiling: AccountCeiling = {
  measurable: true,
  amount: { value: '22950', assetCode: 'EUR' },
  validFrom: '2025-04-25',
  validTo: null,
  verification: 'VERIFIED',
  source,
  claim: null,
};

const publishedRate: AccountRate = {
  guaranteed: true,
  application: 'MARGINAL',
  brackets: [{ percentage: '1.7', lowerBound: '0', upperBound: null }],
  validFrom: '2026-08-01',
  validTo: '2027-01-31',
  verification: 'VERIFIED',
  source,
  claim: null,
};

const claim = {
  overrideId: '00000000-0000-7000-8000-0000000000c1',
  reason: 'La banque a confirmé un plafond plus élevé par écrit.',
  authorId: '00000000-0000-7000-8000-000000000001',
  authorDisplayName: 'Owner',
  recordedAt: '2026-09-04T09:00:00+00:00',
};

const passbook: AccountRules = {
  accountId: account.id,
  assetCode: 'EUR',
  productCode: 'FR_LIVRET_A',
  productModelId: null,
  origin: 'SYSTEM_CATALOG',
  asOf: TODAY,
  ceilings: [
    {
      kind: 'DEPOSIT_CEILING',
      basis: 'BALANCE_EXCLUDING_INTEREST',
      countsCreditedInterest: false,
      spansSeveralAccounts: false,
      effectiveLayer: 'CATALOG',
      catalog: publishedCeiling,
      inherited: null,
      override: null,
      check: {
        status: 'UNSETTLED',
        warning: false,
        measured: null,
        excess: null,
        unsettledReason: 'MISSING_VALUATION',
      },
    },
  ],
  rates: [
    {
      kind: 'ANNUAL_RATE',
      effectiveLayer: 'CATALOG',
      catalog: publishedRate,
      inherited: null,
      override: null,
      applied: null,
    },
  ],
  terms: [],
  unavailableRuleKinds: [],
  yieldReading: {
    contractual: { kind: 'ANNUAL_RATE', guaranteed: true },
    assumption: null,
    assumptionReason: 'NOT_RECORDED',
    observed: null,
    observedReason: 'NO_RETURN_SERIES',
  },
};

const noOverrides: AccountRuleOverridePage = { overrides: [] };

function success(data: AccountRules | AccountRuleOverridePage) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function failure(status: number) {
  return Promise.resolve({ data: undefined, response: new Response('{}', { status }) });
}

/** Opens the details behind a rule's (or the yield's) "i" button. */
function openDetails(name: string) {
  fireEvent.click(screen.getByRole('button', { name: `Détails : ${name}` }));
}

const onOverride = vi.fn();
const onWithdraw = vi.fn();

function renderPanel() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        <AccountRulesPanel account={account} onOverride={onOverride} onWithdraw={onWithdraw} />
      </QueryClientProvider>,
    ),
    queryClient,
  };
}

describe('AccountRulesPanel', () => {
  beforeEach(() => {
    api.listAccountRuleOverrides.mockImplementation(() => success(noOverrides));
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('announces one skeleton until both cold reads resolve and retains rules on refetch', async () => {
    let resolveRules: ((value: unknown) => void) | undefined;
    let resolveOverrides: ((value: unknown) => void) | undefined;
    api.readAccountRules.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveRules = resolve;
        }),
    );
    api.listAccountRuleOverrides.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveOverrides = resolve;
        }),
    );
    const { queryClient } = renderPanel();

    expect(screen.getByTestId('account-rules-skeleton')).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    resolveRules?.(await success(passbook));
    expect(screen.getByTestId('account-rules-skeleton')).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    resolveOverrides?.(await success(noOverrides));
    expect(await screen.findByText('Plafond de dépôt')).toBeTruthy();
    expect(screen.queryByTestId('account-rules-skeleton')).toBeNull();

    api.readAccountRules.mockImplementation(() => new Promise(() => undefined));
    void queryClient.invalidateQueries({ queryKey: ['account-rules'] });
    await waitFor(() => expect(api.readAccountRules).toHaveBeenCalledTimes(2));
    expect(screen.getByText('Plafond de dépôt')).toBeTruthy();
    expect(screen.queryByTestId('account-rules-skeleton')).toBeNull();
  });

  it('reads each rule as a name, the value in force and since when', async () => {
    api.readAccountRules.mockImplementation(() => success(passbook));
    renderPanel();

    expect(await screen.findByText('Plafond de dépôt')).toBeTruthy();
    expect(screen.getByText(/22\s950,00\s€/)).toBeTruthy();
    // The Modal is the only scroll area and no table is left to scroll sideways.
    expect([...document.querySelectorAll('dt')].map((term) => term.textContent)).toEqual([
      'Ouverture',
      'Rendement',
      'Plafond de dépôt',
    ]);
    expect(screen.queryByRole('table')).toBeNull();
    // A period left open is in force for every later date, so it is shown as a
    // start and never as an expiry.
    expect(screen.getByText('Depuis le 25 avril 2025')).toBeTruthy();
    expect(screen.getByText('Du 1 août 2026 au 31 janvier 2027')).toBeTruthy();
    expect(screen.getByText('1,70 %')).toBeTruthy();
    // One bracket covering every amount reads the same under both application modes.
    expect(screen.queryByText(/tranche/i)).toBeNull();
    // An inherited value is the default: only a local claim carries a badge.
    expect(screen.queryByText('Hérité')).toBeNull();
    expect(screen.queryByText('Dérogation')).toBeNull();
    // No claim was ever recorded: no empty history accordion either.
    expect(screen.queryByText('Historique des dérogations')).toBeNull();
    expect(screen.getByText('Ouverture')).toBeTruthy();
    expect(screen.getByText('15 janvier 2020')).toBeTruthy();
    expect(screen.queryByText('Clôture')).toBeNull();

    // Nothing else is on the main path: measure, verification, source and
    // their prose wait behind the details button.
    expect(screen.queryByText('Sommes déposées, hors intérêts')).toBeNull();
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.queryByText('Source')).toBeNull();
    expect(screen.queryByText('Vérifié')).toBeNull();
  });

  it('keeps the measure, the verification and the publication behind the details button', async () => {
    api.readAccountRules.mockImplementation(() => success(passbook));
    renderPanel();

    await screen.findByText('Plafond de dépôt');
    const button = screen.getByRole('button', { name: 'Détails : Plafond de dépôt' });
    expect(button.getAttribute('aria-expanded')).toBe('false');
    fireEvent.click(button);
    expect(button.getAttribute('aria-expanded')).toBe('true');

    // The amount alone would settle nothing: a passbook carried past 22 950 €
    // by its own interest has broken no rule, so the measure is stated.
    expect(screen.getByText('Sommes déposées, hors intérêts')).toBeTruthy();
    expect(
      screen.getByText(
        'Les intérêts versés n’entrent pas dans la mesure : ils peuvent porter le compte au-delà du plafond sans qu’aucune règle soit enfreinte.',
      ),
    ).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Livret A' }).getAttribute('href')).toBe(source.url);

    openDetails('Rendement');
    expect(screen.getAllByText('Taux dû au titulaire').length).toBeGreaterThan(0);
    expect(
      screen.getByText('Aucun solde observé : le barème n’est pas appliqué à un exemple tapé.'),
    ).toBeTruthy();
    // Every figure travels with the publication it was read from.
    expect(
      screen.getAllByRole('link', { name: 'Livret A' }).map((link) => link.getAttribute('href')),
    ).toEqual([source.url, source.url]);
  });

  it('lightens the yield reading and keeps the other two readings behind its button', async () => {
    api.readAccountRules.mockImplementation(() => success(passbook));
    renderPanel();

    expect(await screen.findByText('Taux garanti')).toBeTruthy();
    expect(screen.queryByText(/Non enregistrée/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Détails : Rendement' }));
    expect(
      screen.getByText(
        'Non enregistrée : une hypothèse n’est jamais stockée comme taux d’un produit de marché.',
      ),
    ).toBeTruthy();
    expect(
      screen.getByText(
        'Aucune série de rendements : inventer un rendement à partir de deux stocks présenterait un mouvement comme un rendement.',
      ),
    ).toBeTruthy();
  });

  it('shows the closing date when the account states one', async () => {
    api.readAccountRules.mockImplementation(() => success(passbook));
    const view = renderPanel();
    view.unmount();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <AccountRulesPanel
          account={{ ...account, closedOn: '2026-06-30' }}
          onOverride={onOverride}
          onWithdraw={onWithdraw}
        />
      </QueryClientProvider>,
    );

    expect(await screen.findByText('Clôture')).toBeTruthy();
    expect(screen.getByText('30 juin 2026')).toBeTruthy();
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

    expect(await screen.findByText('1,70 %')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Situation au'), { target: { value: '2025-06-01' } });

    // The answer of the previous date stays on screen while the next one is
    // resolved — walking a year digit by digit would otherwise tear the table
    // down at every keystroke — and it is labelled as the earlier answer rather
    // than passed off as the one being asked for.
    expect(screen.getByText('1,70 %')).toBeTruthy();
    expect(screen.queryByText('Résolution des règles du compte…')).toBeNull();
    expect(
      screen.getByText(
        'Résolution des règles au 1 juin 2025 : la liste montre encore la date précédente.',
      ),
    ).toBeTruthy();

    // The rate of a past semester is not the rate of today, and a semester no
    // publication covers is unknown rather than nought per cent.
    expect(
      await screen.findByText(
        'Non disponible au 1 juin 2025 : aucune valeur sourcée pour cette date.',
      ),
    ).toBeTruthy();
    expect(screen.queryByText('1,70 %')).toBeNull();
    // The ceiling has no known end, so an earlier date keeps it.
    expect(screen.getByText(/22\s950,00\s€/)).toBeTruthy();
    await waitFor(() =>
      expect(api.readAccountRules.mock.calls.map((call) => call[0].query.asOf)).toEqual([
        TODAY,
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
            catalog: { ...publishedCeiling, amount: { value: '150000', assetCode: 'EUR' } },
          },
          {
            ...passbook.ceilings[0]!,
            kind: 'COMBINED_CONTRIBUTION_CEILING',
            basis: 'COMBINED_CONTRIBUTIONS',
            spansSeveralAccounts: true,
            catalog: { ...publishedCeiling, amount: { value: '225000', assetCode: 'EUR' } },
          },
        ],
      }),
    );
    renderPanel();

    await screen.findByText('Plafond de versements');
    openDetails('Plafond de versements');
    openDetails('Plafond de versements commun');
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

  it('shows a workspace template rule as declared rather than sourced or verified', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        productCode: null,
        productModelId: '00000000-0000-7000-8000-0000000000e1',
        origin: 'WORKSPACE_MODEL',
        ceilings: [
          {
            ...passbook.ceilings[0]!,
            effectiveLayer: 'INHERITED',
            catalog: null,
            inherited: { ...publishedCeiling, verification: null, source: null },
          },
        ],
        rates: [
          {
            ...passbook.rates[0]!,
            effectiveLayer: 'INHERITED',
            catalog: null,
            inherited: { ...publishedRate, verification: null, source: null },
          },
        ],
      }),
    );
    renderPanel();

    expect(await screen.findByText('Plafond de dépôt')).toBeTruthy();
    openDetails('Plafond de dépôt');
    openDetails('Rendement');
    // Nobody published a workspace template's periods: no freshness is graded
    // and no publication is named for either row.
    expect(screen.getAllByText('Déclaré par l’espace de travail')).toHaveLength(2);
    expect(screen.getAllByText('— Aucune publication : donnée déclarée')).toHaveLength(2);
    expect(screen.queryByRole('link')).toBeNull();
  });

  it('reports a ceiling published in another unit as not comparable', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        assetCode: 'USD',
        rates: [],
        ceilings: [
          { ...passbook.ceilings[0]!, catalog: { ...publishedCeiling, measurable: false } },
        ],
      }),
    );
    renderPanel();

    // No conversion rate ships with the application, so no verdict is produced
    // rather than a converted one. The short line stays on the main path, the
    // full wording behind the button.
    expect(await screen.findByText('Plafond non comparable à ce compte')).toBeTruthy();
    openDetails('Plafond de dépôt');
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
    // The state line already says it: no second sentence about an empty list.
    expect(screen.queryByText(/Aucune règle en vigueur/)).toBeNull();
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
    expect(screen.queryByText(/Aucune règle en vigueur au/)).toBeNull();
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

  it('shows the published figure, the inherited one and the local claim side by side', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        productCode: 'FR_LIVRET_A',
        productModelId: '00000000-0000-7000-8000-0000000000e1',
        origin: 'WORKSPACE_MODEL',
        rates: [],
        ceilings: [
          {
            ...passbook.ceilings[0]!,
            effectiveLayer: 'OVERRIDE',
            catalog: publishedCeiling,
            inherited: {
              ...publishedCeiling,
              amount: { value: '25000', assetCode: 'EUR' },
              verification: null,
              source: null,
            },
            override: {
              ...publishedCeiling,
              amount: { value: '30000', assetCode: 'EUR' },
              verification: null,
              source: null,
              claim,
            },
          },
        ],
      }),
    );
    renderPanel();

    // The main path states the value in force and that it is the account's own.
    expect(await screen.findByText(/30\s000,00\s€/)).toBeTruthy();
    expect(screen.getByText('Dérogation')).toBeTruthy();
    expect(screen.queryByText(/25\s000,00\s€/)).toBeNull();
    expect(screen.queryByText(/22\s950,00\s€/)).toBeNull();
    expect(screen.queryByText('La banque a confirmé un plafond plus élevé par écrit.')).toBeNull();

    // The three authorities answer the same question; none is lost, they are
    // folded behind the rule's details button.
    openDetails('Plafond de dépôt');
    expect(screen.getByText(/22\s950,00\s€/)).toBeTruthy();
    expect(screen.getByText(/25\s000,00\s€/)).toBeTruthy();
    expect(screen.getByText('Catalogue système')).toBeTruthy();
    expect(screen.getByText("Modèle de l'espace")).toBeTruthy();
    expect(screen.getByText('Dérogation locale')).toBeTruthy();

    // Which one applies is stated in words, never by position or by shade.
    expect(screen.getAllByText('En vigueur')).toHaveLength(1);
    expect(screen.getAllByText('Pour comparaison')).toHaveLength(2);

    // A local figure with no reason beside it would be indistinguishable from
    // a sourced one at a glance.
    expect(screen.getByText('La banque a confirmé un plafond plus élevé par écrit.')).toBeTruthy();
    // Only the published layer names a publication.
    expect(screen.getAllByRole('link', { name: 'Livret A' })).toHaveLength(1);
  });

  it('hands the rule and the kinds this account may state to the override form', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({ ...passbook, unavailableRuleKinds: ['MIN_RATE'] }),
    );
    renderPanel();

    await screen.findByText('Plafond de dépôt');
    openDetails('Plafond de dépôt');
    openDetails('Rendement');
    openDetails('Taux minimum');
    const actions = screen.getAllByRole('button', { name: 'Déroger' });
    // One per rule the account carries, plus the gap it is expected to carry
    // and does not: a claim is exactly how that gap gets filled.
    expect(actions).toHaveLength(3);
    fireEvent.click(actions[0]!);

    expect(onOverride).toHaveBeenCalledWith({
      kind: 'ANNUAL_RATE',
      kinds: ['DEPOSIT_CEILING', 'ANNUAL_RATE', 'MIN_RATE'],
    });
  });

  it('hands the claim itself to the withdrawal, reason included', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        rates: [],
        ceilings: [
          {
            ...passbook.ceilings[0]!,
            effectiveLayer: 'OVERRIDE',
            override: { ...publishedCeiling, verification: null, source: null, claim },
          },
        ],
      }),
    );
    renderPanel();

    await screen.findByText('Plafond de dépôt');
    openDetails('Plafond de dépôt');
    fireEvent.click(screen.getByRole('button', { name: 'Retirer la dérogation' }));

    expect(onWithdraw).toHaveBeenCalledWith(claim, 'DEPOSIT_CEILING');
  });

  it('keeps a withdrawn claim visible in the history it no longer applies to', async () => {
    api.readAccountRules.mockImplementation(() => success(passbook));
    api.listAccountRuleOverrides.mockImplementation(() =>
      success({
        overrides: [
          {
            id: claim.overrideId,
            accountId: account.id,
            kind: 'DEPOSIT_CEILING',
            valueType: 'AMOUNT',
            amount: { value: '30000', assetCode: 'EUR' },
            text: null,
            application: null,
            brackets: null,
            validFrom: '2026-01-01',
            validTo: null,
            standing: false,
            withdrawnAt: '2026-09-10T09:00:00+00:00',
            withdrawnBy: claim.authorId,
            reason: claim.reason,
            authorId: claim.authorId,
            authorDisplayName: claim.authorDisplayName,
            recordedAt: claim.recordedAt,
          },
        ],
      }),
    );
    renderPanel();

    // The claim no longer applies on any date — the table above resolves the
    // published ceiling — and it is still readable, because it explains the
    // statements produced while it did apply.
    expect(await screen.findByText('Historique des dérogations')).toBeTruthy();
    expect(await screen.findByText('Retirée')).toBeTruthy();
    expect(screen.getByText('Retirée le 10 septembre 2026')).toBeTruthy();
    expect(screen.getByText(claim.reason)).toBeTruthy();
    expect(screen.getByText('Owner')).toBeTruthy();
    expect(screen.getByText(/22\s950,00\s€/)).toBeTruthy();
  });

  it('warns when a Livret Bleu sits above the Livret A ceiling and shifts the rate', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        productCode: 'FR_LIVRET_BLEU',
        ceilings: [
          {
            ...passbook.ceilings[0]!,
            check: {
              status: 'EXCEEDED',
              warning: true,
              measured: { value: '25000', assetCode: 'EUR' },
              excess: { value: '2050', assetCode: 'EUR' },
              unsettledReason: null,
            },
          },
        ],
        rates: [
          {
            ...passbook.rates[0]!,
            catalog: {
              ...publishedRate,
              application: 'MARGINAL',
              brackets: [
                { percentage: '1.7', lowerBound: '0', upperBound: '22950' },
                { percentage: '0.5', lowerBound: '22950', upperBound: null },
              ],
            },
            applied: {
              interest: '400.40',
              effectivePercentage: '1.6016',
              reachedPercentage: '0.5',
              reachedLowerBound: '22950',
              rateShiftsAboveFirstBracket: true,
              unsettledReason: null,
            },
          },
        ],
      }),
    );
    renderPanel();

    // The short lines stay beside the value; the full explanation is behind the button.
    expect(await screen.findByText(/Plafond dépassé de .* \(autorisé\)/)).toBeTruthy();
    expect(
      screen.getByText('Le barème change le rendement au-delà de la première tranche'),
    ).toBeTruthy();
    expect(screen.queryByText(/Le barème peut changer le rendement/)).toBeNull();
    openDetails('Plafond de dépôt');
    openDetails('Rendement');
    expect(
      screen.getByText(
        /Au-dessus du plafond de .* : autorisé. Le barème peut changer le rendement/,
      ),
    ).toBeTruthy();
    expect(screen.getByText(/1,60\s%/)).toBeTruthy();
    expect(
      screen.getByText(
        'Le solde a dépassé la première tranche : le rendement change, le dépassement reste autorisé.',
      ),
    ).toBeTruthy();
    expect(screen.queryByText(/interdit/i)).toBeNull();
  });

  it('names a business date the API refuses instead of calling it a failed read', async () => {
    // The field bounds are advisory outside a form: a year typed digit by digit
    // reaches the API, which refuses a date outside the years it covers. Retrying
    // it would fail identically, so no retry is offered.
    api.readAccountRules.mockImplementationOnce(() => success(passbook));
    api.readAccountRules.mockImplementation(() => failure(400));
    renderPanel();

    expect(await screen.findByText('Plafond de dépôt')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Situation au'), { target: { value: '0202-06-01' } });

    expect(
      await screen.findByText(
        'Cette date n’est pas une date d’activité que l’application couvre. Choisissez une autre date.',
      ),
    ).toBeTruthy();
    expect(
      screen.queryByText('Les règles de ce compte n’ont pas pu être lues. Réessayez.'),
    ).toBeNull();
    // The kept table is not still "being resolved" once the date was refused.
    expect(screen.queryByText(/Résolution des règles au/)).toBeNull();
    expect(screen.queryByRole('button', { name: 'Réessayer' })).toBeNull();
  });

  it('wraps a long publication title and keeps its link safe under the disclosure', async () => {
    const longTitle = `Livret A ${'réglementé '.repeat(30)}`.trim();
    const longUrl = `https://www.service-public.fr/${'très-long-chemin/'.repeat(20)}F2365`;
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        rates: [],
        ceilings: [
          {
            ...passbook.ceilings[0]!,
            catalog: { ...publishedCeiling, source: { ...source, title: longTitle, url: longUrl } },
          },
        ],
      }),
    );
    renderPanel();

    await screen.findByText('Plafond de dépôt');
    expect(screen.queryByRole('link')).toBeNull();
    openDetails('Plafond de dépôt');
    const link = await screen.findByRole('link', { name: longTitle });
    expect(link.getAttribute('href')).toBe(longUrl);
    expect(link.getAttribute('rel')).toBe('noreferrer noopener');
    expect(link.getAttribute('target')).toBe('_blank');
  });

  it('keeps an unknown rule unavailable with its reason and never as zero', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({ ...passbook, ceilings: [], rates: [], unavailableRuleKinds: ['MIN_RATE'] }),
    );
    renderPanel();

    expect(await screen.findByText('Taux minimum')).toBeTruthy();
    expect(
      screen.getByText(
        'Non disponible au 3 septembre 2026 : aucune valeur sourcée pour cette date.',
      ),
    ).toBeTruthy();
    expect(screen.queryByText(/0\s?%/)).toBeNull();
    expect(screen.queryByRole('button', { name: 'Déroger' })).toBeNull();
    openDetails('Taux minimum');
    expect(screen.getByRole('button', { name: 'Déroger' })).toBeTruthy();
  });

  it('keeps the known labels and the final section order while the rules load', () => {
    api.readAccountRules.mockImplementation(() => new Promise(() => undefined));
    renderPanel();

    expect(screen.getByText('Ouverture')).toBeTruthy();
    expect(screen.getByText('Rendement')).toBeTruthy();
    expect(screen.getByLabelText('Situation au')).toBeTruthy();
    const buttons = screen.getAllByRole('button', { name: /^Détails/ });
    expect(buttons.length).toBeGreaterThan(0);
    expect(buttons.every((button) => (button as HTMLButtonElement).disabled)).toBe(true);
    expect(screen.getAllByRole('status')).toHaveLength(1);
  });

  it('never prints a raw rule token and quotes an untranslated one in words under the details', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        ceilings: [],
        rates: [],
        terms: [
          {
            kind: 'INTEREST_ACCRUAL_METHOD',
            effectiveLayer: 'CATALOG',
            catalog: {
              token: 'FORTNIGHTLY_ANNUAL_CAPITALISATION',
              validFrom: '2025-04-25',
              validTo: null,
              verification: 'VERIFIED',
              source,
              claim: null,
            },
            inherited: null,
            override: null,
          },
        ],
      }),
    );
    renderPanel();

    // A term without French wording leaves the main path for one closed disclosure.
    const others = await screen.findByText('Autres conditions (1)');
    expect(others.closest('details')?.open).toBe(false);
    expect(screen.getByText('Condition particulière')).toBeTruthy();
    expect(screen.queryByText(/FORTNIGHTLY/)).toBeNull();
    openDetails('Méthode de calcul des intérêts');
    expect(screen.getByText('Libellé source : Fortnightly annual capitalisation')).toBeTruthy();
    expect(screen.queryByText(/FORTNIGHTLY/)).toBeNull();
  });
});
