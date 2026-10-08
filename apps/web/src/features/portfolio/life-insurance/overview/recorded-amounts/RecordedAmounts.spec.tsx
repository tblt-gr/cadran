import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { summaryFixture } from '@/features/portfolio/life-insurance/overview/summaryFixture';
import { RecordedAmounts } from './RecordedAmounts';
afterEach(cleanup);
it('keeps all 15 known financial row labels and skeletonizes only their amounts', () => {
  const labels = [
    'Versements bruts',
    'Rachats bruts',
    'Rachats nets versés',
    'Frais d’entrée',
    'Frais de gestion',
    'Frais d’arbitrage',
    'Frais de rachat',
    'Autres frais',
    'Impôt retenu',
    'Prélèvements sociaux',
    'Autres retenues',
    'Intérêts crédités bruts',
    'Intérêts crédités nets',
    'Participation aux bénéfices brute',
    'Participation aux bénéfices nette',
  ];
  const view = render(<RecordedAmounts summary={null} accountId="account-1" />);
  expect(screen.getByText('Sources des montants cumulés').closest('summary')).toBeTruthy();
  expect(screen.getByText('Sources des montants cumulés').closest('details')?.open).toBe(false);
  const table = screen.getByRole('table', { name: 'Montants cumulés enregistrés' });
  expect(
    within(table)
      .getAllByRole('rowheader')
      .map((cell) => cell.textContent),
  ).toEqual(labels);
  expect(within(table).getAllByRole('cell')).toHaveLength(15);
  for (const cell of within(table).getAllByRole('cell')) {
    expect(cell.textContent).toBe('');
    expect(cell.querySelector('[aria-hidden="true"]')).toBeTruthy();
  }
  view.rerender(<RecordedAmounts summary={summaryFixture()} accountId="account-1" />);
  expect(
    within(table)
      .getAllByRole('rowheader')
      .map((cell) => cell.textContent),
  ).toEqual(labels);
  expect(
    within(table)
      .getAllByRole('cell')
      .every((cell) => !cell.querySelector('[aria-hidden="true"]')),
  ).toBe(true);
  expect(within(table).getByRole('row', { name: /Intérêts crédités nets/ }).textContent).toContain(
    '124,20',
  );
});
