import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { summaryFixture } from '@/features/portfolio/life-insurance/overview/summaryFixture';
import { TaxTrackingCard } from './TaxTrackingCard';
afterEach(cleanup);
it('presents only recorded annual amounts and factual age with the no-estimate notice', () => {
  render(<TaxTrackingCard summary={summaryFixture()} />);
  expect(screen.getByRole('heading', { name: 'Suivi fiscal (informatif)' })).toBeTruthy();
  expect(
    screen.getByText('Contrat ouvert depuis 6 ans et 2 mois (date fiscale du 01/01/2020).'),
  ).toBeTruthy();
  expect(
    screen.getByText("Aucune estimation d'impôt : aucun barème fiscal identifié pour ce contrat."),
  ).toBeTruthy();
  expect(screen.getByRole('columnheader', { name: 'Impôt retenu' })).toBeTruthy();
  expect(screen.getByText(/34,40/)).toBeTruthy();
});
it('keeps real titles and annual table headers while loading', () => {
  render(<TaxTrackingCard summary={null} />);
  expect(screen.getByRole('heading', { name: 'Suivi fiscal (informatif)' })).toBeTruthy();
  expect(screen.getByRole('columnheader', { name: 'Année' })).toBeTruthy();
});
it('shows unavailable age and empty recorded years explicitly', () => {
  render(<TaxTrackingCard summary={{ ...summaryFixture(), contractAge: null, taxYears: [] }} />);
  expect(screen.getByText('Date fiscale indisponible.')).toBeTruthy();
  expect(screen.getByText('Aucune année enregistrée à cette date.')).toBeTruthy();
});
