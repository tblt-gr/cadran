import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { summaryFixture } from '@/features/portfolio/life-insurance/overview/summaryFixture';
import { ContractFigures } from './ContractFigures';

afterEach(cleanup);

it('leads with the value and its qualified gain, then the cumulated flows and the fees', () => {
  render(<ContractFigures accountId="account-1" summary={summaryFixture()} />);
  const region = within(screen.getByRole('region', { name: 'Indicateurs du contrat' }));

  expect(
    region.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent),
  ).toEqual([
    'Valeur du contrat',
    "Gain depuis l'ouverture",
    'Versements cumulés',
    'Rachats nets versés',
    'Frais cumulés',
  ]);
});
