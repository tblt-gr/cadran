import type { LifeInsuranceContract, LifeInsuranceOverview, Instrument } from '@cadran/api-client';
export function contractFixture(): LifeInsuranceContract {
  return {
    id: 'contract-1',
    accountId: 'account-1',
    insurer: 'Assureur Démo',
    distributor: null,
    commercialName: null,
    contractNumberMasked: '•••• 2345',
    subscribedOn: '2020-01-01',
    taxStartOn: '2020-01-01',
    managementMode: 'FREE',
    status: 'OPEN',
    closedOn: null,
    version: 3,
  };
}
export function overviewFixture(): LifeInsuranceOverview {
  return {
    contract: contractFixture(),
    feeTerms: [],
    euroFundRates: [],
    euroFunds: [
      {
        instrument: {
          id: 'euro-1',
          name: 'Fonds euros Démo',
          instrumentType: 'EURO_FUND',
        } as Instrument,
        latestRateYear: null,
        missingLastYear: true,
      },
    ],
  };
}
