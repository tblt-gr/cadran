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

const onOverride = vi.fn();
const onWithdraw = vi.fn();

function renderPanel() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={queryClient}>
      <AccountRulesPanel account={account} onOverride={onOverride} onWithdraw={onWithdraw} />
    </QueryClientProvider>,
  );
}

describe('AccountRulesPanel', () => {
  beforeEach(() => {
    api.listAccountRuleOverrides.mockImplementation(() => success(noOverrides));
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('shows the ceiling with the measure it is checked against and the rate of the day', async () => {
    api.readAccountRules.mockImplementation(() => success(passbook));
    renderPanel();

    expect(await screen.findByText('Plafond de dépôt')).toBeTruthy();
    expect(screen.getByText(/22\s950\s€/)).toBeTruthy();
    // One tbody per rule so a spanning header with scope="rowgroup" names
    // only that rule's cells, not every later rate or term in the table.
    expect(screen.getByRole('table').querySelectorAll('tbody')).toHaveLength(2);

    // The amount alone would settle nothing: a passbook carried past 22 950 €
    // by its own interest has broken no rule, so the measure is stated.
    expect(screen.getByText('Sommes déposées, hors intérêts')).toBeTruthy();
    expect(
      screen.getByText(
        'Les intérêts versés n’entrent pas dans la mesure : ils peuvent porter le compte au-delà du plafond sans qu’aucune règle soit enfreinte.',
      ),
    ).toBeTruthy();
    // A period left open is in force for every later date, so it is shown as a
    // start and never as an expiry.
    expect(screen.getByText('Depuis le 25 avril 2025')).toBeTruthy();
    expect(screen.getByText('Du 1 août 2026 au 31 janvier 2027')).toBeTruthy();

    expect(screen.getByText('1,7 %')).toBeTruthy();
    // One bracket covering every amount reads the same under both application
    // modes, so naming the mode beside it would be noise.
    expect(screen.queryByText(/tranche/i)).toBeNull();
    expect(screen.getAllByText('Taux dû au titulaire').length).toBeGreaterThan(0);
    expect(
      screen.getByText('Aucun solde observé : le barème n’est pas appliqué à un exemple tapé.'),
    ).toBeTruthy();
    expect(screen.getByText('Lecture du rendement')).toBeTruthy();
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

    // The answer of the previous date stays on screen while the next one is
    // resolved — walking a year digit by digit would otherwise tear the table
    // down at every keystroke — and it is labelled as the earlier answer rather
    // than passed off as the one being asked for.
    expect(screen.getByText('1,7 %')).toBeTruthy();
    expect(screen.queryByText('Résolution des règles du compte…')).toBeNull();
    expect(
      screen.getByText(
        'Résolution des règles au 1 juin 2025 : le tableau montre encore la date précédente.',
      ),
    ).toBeTruthy();

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
      screen.getByText('Aucune règle en vigueur au 3 septembre 2026 pour ce compte.'),
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

    // The three authorities answer the same question and none of them is
    // hidden behind the winner.
    expect(await screen.findByText(/22\s950\s€/)).toBeTruthy();
    expect(screen.getByText(/25\s000\s€/)).toBeTruthy();
    expect(screen.getByText(/30\s000\s€/)).toBeTruthy();
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

    const actions = await screen.findAllByRole('button', { name: 'Déroger' });
    // One per rule the account carries, plus the gap it is expected to carry
    // and does not: a claim is exactly how that gap gets filled.
    expect(actions).toHaveLength(3);
    fireEvent.click(actions[0]!);

    expect(onOverride).toHaveBeenCalledWith({
      kind: 'DEPOSIT_CEILING',
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

    fireEvent.click(await screen.findByRole('button', { name: 'Retirer la dérogation' }));

    expect(onWithdraw).toHaveBeenCalledWith(claim);
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
    expect(await screen.findByText('Retirée')).toBeTruthy();
    expect(screen.getByText('Retirée le 10 septembre 2026')).toBeTruthy();
    expect(screen.getByText(claim.reason)).toBeTruthy();
    expect(screen.getByText('Owner')).toBeTruthy();
    expect(screen.getByText(/22\s950\s€/)).toBeTruthy();
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

    expect(
      await screen.findByText(
        /Au-dessus du plafond de .* : autorisé. Le barème peut changer le rendement/,
      ),
    ).toBeTruthy();
    expect(screen.getByText(/1,6016\s%/)).toBeTruthy();
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
    fireEvent.change(screen.getByLabelText('Date métier'), { target: { value: '0202-06-01' } });

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
});
