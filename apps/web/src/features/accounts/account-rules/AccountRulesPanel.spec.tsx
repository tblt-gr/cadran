import type {
  Account,
  AccountCeiling,
  AccountRate,
  AccountRuleOverride,
  AccountRuleOverridePage,
  AccountRules,
} from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
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

/** Opens the three-dot menu that ends a rule's line. */
function openActions(rule: string) {
  fireEvent.click(screen.getByRole('button', { name: `Actions : ${rule}` }));
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

  it('reads each rule as a label, the value in force and since when, with nothing else', async () => {
    api.readAccountRules.mockImplementation(() => success(passbook));
    renderPanel();

    expect(await screen.findByText('Plafond de dépôt')).toBeTruthy();
    expect([...document.querySelectorAll('dt')].map((term) => term.textContent)).toEqual([
      'Ouverture',
      'Rendement',
      'Plafond de dépôt',
    ]);
    expect(screen.getByText('15 janvier 2020')).toBeTruthy();
    expect(screen.queryByText('Clôture')).toBeNull();
    expect(screen.getByText(/22\s950,00\s€/)).toBeTruthy();
    // A period left open is in force for every later date, so it is shown as a
    // start and never as an expiry.
    expect(screen.getByText('Depuis le 25 avril 2025')).toBeTruthy();
    expect(screen.getByText('Du 1 août 2026 au 31 janvier 2027')).toBeTruthy();
    expect(screen.getByText('Taux garanti')).toBeTruthy();
    expect(screen.getByText('1,70 %')).toBeTruthy();
    expect(screen.queryByText(/tranche/i)).toBeNull();
    expect(screen.queryByRole('table')).toBeNull();

    // The technical readings belong to the product catalogue: no info button,
    // measure, verification, source or badge here.
    expect(screen.queryByRole('button', { name: /^Détails/ })).toBeNull();
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.queryByText('Sommes déposées, hors intérêts')).toBeNull();
    expect(screen.queryByText('Dérogation')).toBeNull();
    expect(screen.queryByText('Hérité')).toBeNull();
    expect(screen.queryByText(/Historique/)).toBeNull();
    // Nothing is withdrawable on a rule that was never overridden.
    openActions('Plafond de dépôt');
    expect(screen.getByRole('button', { name: 'Déroger : Plafond de dépôt' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: /^Retirer la dérogation/ })).toBeNull();
    expect(screen.queryByLabelText('Situation au')).toBeNull();
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

  it('says an account described by hand inherits no rule in one state line', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({ ...passbook, productCode: null, origin: 'NO_PRODUCT', ceilings: [], rates: [] }),
    );
    renderPanel();

    expect(
      await screen.findByText(
        'Ce compte est décrit à la main : aucun produit du catalogue ne lui rattache de plafond ni de taux.',
      ),
    ).toBeTruthy();
    expect(screen.queryByText(/Aucune règle en vigueur/)).toBeNull();
    expect(screen.queryByRole('table')).toBeNull();
  });

  it('says a catalogue account with nothing in force on the date once, without blaming a rule', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        ceilings: [],
        rates: [],
        yieldReading: { ...passbook.yieldReading, contractual: null },
      }),
    );
    renderPanel();

    expect(
      await screen.findByText('Aucune règle en vigueur au 3 septembre 2026 pour ce compte.'),
    ).toBeTruthy();
    expect(screen.getByText('Aucun taux contractuel')).toBeTruthy();
  });

  it('warns when the product left the catalogue and no rule can be resolved', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({ ...passbook, origin: 'PRODUCT_WITHDRAWN', ceilings: [], rates: [] }),
    );
    renderPanel();

    const warning = await screen.findByRole('alert');
    expect(warning.textContent).toContain('FR_LIVRET_A');
    expect(warning.textContent).toContain('ne figure plus au catalogue système');
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

  it('keeps one short alert under a ceiling exceeded or published in another unit', async () => {
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

    expect(await screen.findByText(/Plafond dépassé de .* \(autorisé\)/)).toBeTruthy();
    expect(
      screen.getByText('Le barème change le rendement au-delà de la première tranche'),
    ).toBeTruthy();
    expect(screen.queryByText(/interdit/i)).toBeNull();
  });

  it('reports a ceiling published in another unit as not comparable in one line', async () => {
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

    expect(await screen.findByText('Plafond non comparable à ce compte')).toBeTruthy();
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
    openActions('Taux minimum');
    expect(screen.getByRole('button', { name: 'Déroger : Taux minimum' })).toBeTruthy();
  });

  it('hands the rule and the kinds this account may state to the override form', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({ ...passbook, unavailableRuleKinds: ['MIN_RATE'] }),
    );
    renderPanel();

    await screen.findByText('Plafond de dépôt');
    // One per rule the account carries, plus the gap it is expected to carry
    // and does not: a claim is exactly how that gap gets filled.
    expect(screen.getAllByRole('button', { name: /^Actions : / })).toHaveLength(3);
    openActions('Plafond de dépôt');
    fireEvent.click(screen.getByRole('button', { name: 'Déroger : Plafond de dépôt' }));

    expect(onOverride).toHaveBeenCalledWith({
      kind: 'DEPOSIT_CEILING',
      kinds: ['DEPOSIT_CEILING', 'ANNUAL_RATE', 'MIN_RATE'],
    });
  });

  it('shows the value in force of an override with no badge and no menu entry to withdraw it', async () => {
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        rates: [],
        ceilings: [
          {
            ...passbook.ceilings[0]!,
            effectiveLayer: 'OVERRIDE',
            catalog: publishedCeiling,
            inherited: { ...publishedCeiling, amount: { value: '25000', assetCode: 'EUR' } },
            override: { ...publishedCeiling, amount: { value: '30000', assetCode: 'EUR' }, claim },
          },
        ],
      }),
    );
    renderPanel();

    // Only the value in force is on the line; the other authorities are the catalogue's to show.
    expect(await screen.findByText(/30\s000,00\s€/)).toBeTruthy();
    expect(screen.queryByText(/25\s000,00\s€/)).toBeNull();
    expect(screen.queryByText(/22\s950,00\s€/)).toBeNull();
    expect(screen.queryByText('Dérogation')).toBeNull();

    // The menu holds "Déroger" once and nothing to withdraw: that is the history's.
    openActions('Plafond de dépôt');
    expect(screen.getAllByText('Déroger')).toHaveLength(1);
    expect(screen.queryByRole('button', { name: /^Retirer/ })).toBeNull();
  });

  it('lists every claim of one rule, newest first, the one in force marked in words', async () => {
    const earlier: AccountRuleOverride = {
      id: '00000000-0000-7000-8000-0000000000c0',
      accountId: account.id,
      kind: 'DEPOSIT_CEILING',
      valueType: 'AMOUNT',
      amount: { value: '28000', assetCode: 'EUR' },
      text: null,
      application: null,
      brackets: null,
      validFrom: '2025-01-01',
      validTo: '2025-12-31',
      standing: false,
      withdrawnAt: '2026-01-10T09:00:00+00:00',
      withdrawnBy: claim.authorId,
      reason: 'Ancien accord écrit',
      authorId: claim.authorId,
      authorDisplayName: 'Owner',
      recordedAt: '2025-01-02T09:00:00+00:00',
    };
    const current: AccountRuleOverride = {
      ...earlier,
      id: claim.overrideId,
      amount: { value: '30000', assetCode: 'EUR' },
      validFrom: '2026-01-01',
      validTo: null,
      standing: true,
      withdrawnAt: null,
      reason: claim.reason,
    };
    api.readAccountRules.mockImplementation(() =>
      success({
        ...passbook,
        rates: [],
        ceilings: [
          {
            ...passbook.ceilings[0]!,
            effectiveLayer: 'OVERRIDE',
            override: { ...publishedCeiling, amount: { value: '30000', assetCode: 'EUR' }, claim },
          },
        ],
      }),
    );
    api.listAccountRuleOverrides.mockImplementation(() =>
      success({ overrides: [earlier, current] }),
    );
    renderPanel();

    const summary = await screen.findByText('Historique des dérogations');
    const history = summary.closest('details')!;
    expect(history.open).toBe(false);
    expect(within(history).getByText(/28\s000,00\s€/)).toBeTruthy();
    expect(within(history).getByText('Ancien accord écrit')).toBeTruthy();
    expect(within(history).getByText(/Retirée/)).toBeTruthy();
    // The value in force is listed too, marked in words, newest period first.
    expect(within(history).getByText(/30\s000,00\s€/)).toBeTruthy();
    expect(within(history).getByText(/En vigueur/)).toBeTruthy();
    const items = within(history).getAllByRole('listitem');
    expect(items[0]?.textContent).toContain('30');
    expect(items[1]?.textContent).toContain('28');
    // The claim in force is withdrawable from its own entry, named by value and
    // period; the withdrawn one carries no button.
    const withdraw = within(history).getAllByRole('button');
    expect(withdraw).toHaveLength(1);
    expect(withdraw[0]?.getAttribute('aria-label')).toMatch(
      /^Retirer la dérogation de 30.000,00.€ : Depuis le 1 janvier 2026$/,
    );
    fireEvent.click(withdraw[0]!);
    expect(onWithdraw).toHaveBeenCalledWith(
      expect.objectContaining({ overrideId: claim.overrideId }),
      'DEPOSIT_CEILING',
      expect.stringContaining('Plafond de dépôt : '),
    );
  });

  it('offers to withdraw a standing claim that is not the one in force from the history', async () => {
    const future: AccountRuleOverride = {
      id: '00000000-0000-7000-8000-0000000000c2',
      accountId: account.id,
      kind: 'DEPOSIT_CEILING',
      valueType: 'AMOUNT',
      amount: { value: '31000', assetCode: 'EUR' },
      text: null,
      application: null,
      brackets: null,
      validFrom: '2027-01-01',
      validTo: null,
      standing: true,
      withdrawnAt: null,
      withdrawnBy: null,
      reason: 'Prévu pour 2027',
      authorId: claim.authorId,
      authorDisplayName: 'Owner',
      recordedAt: '2026-09-01T09:00:00+00:00',
    };
    api.readAccountRules.mockImplementation(() => success({ ...passbook, rates: [] }));
    api.listAccountRuleOverrides.mockImplementation(() => success({ overrides: [future] }));
    renderPanel();

    const summary = await screen.findByText('Historique des dérogations');
    fireEvent.click(
      within(summary.closest('details')!).getByRole('button', {
        name: /^Retirer la dérogation de /,
      }),
    );

    expect(onWithdraw).toHaveBeenCalledWith(
      expect.objectContaining({ overrideId: future.id, reason: 'Prévu pour 2027' }),
      'DEPOSIT_CEILING',
      expect.any(String),
    );
  });

  it('never prints a raw rule token and leaves out a term without French wording', async () => {
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

    expect(await screen.findByText('Ouverture')).toBeTruthy();
    expect(screen.queryByText('Méthode de calcul des intérêts')).toBeNull();
    expect(screen.queryByText(/FORTNIGHTLY/i)).toBeNull();
    expect(screen.queryByText(/Condition particulière|Autres conditions/)).toBeNull();
  });

  it('keeps the known labels and the final order while the rules load', () => {
    api.readAccountRules.mockImplementation(() => new Promise(() => undefined));
    renderPanel();

    expect(screen.getByText('Ouverture')).toBeTruthy();
    expect(screen.getByText('Rendement')).toBeTruthy();
    const actions = screen.getAllByRole('button', { name: 'Actions' });
    expect(actions.every((button) => (button as HTMLButtonElement).disabled)).toBe(true);
    expect(screen.getAllByRole('status')).toHaveLength(1);
  });
});
