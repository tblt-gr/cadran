import type { Product, ProductRule } from '@cadran/api-client';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { ProductCard } from './ProductCard';

const source = {
  publisher: 'Direction de l’information légale et administrative',
  title: 'Livret jeune',
  url: 'https://www.service-public.fr/particuliers/vosdroits/F2380',
  publishedOn: null,
  retrievedOn: '2026-08-22',
};

const ceiling: ProductRule = {
  kind: 'DEPOSIT_CEILING',
  valueType: 'AMOUNT',
  amount: { value: '1600', assetCode: 'EUR' },
  percentage: null,
  text: null,
  validFrom: '2019-01-01',
  validTo: null,
  verification: 'VERIFIED',
  verifiedOn: '2026-08-22',
  verifiedBy: 'cadran-maintainer',
  source,
};

const rate: ProductRule = {
  kind: 'ANNUAL_RATE',
  valueType: 'PERCENTAGE',
  amount: null,
  percentage: '4',
  text: null,
  validFrom: '2023-02-01',
  validTo: null,
  verification: 'STALE',
  verifiedOn: '2025-01-01',
  verifiedBy: 'cadran-maintainer',
  source,
};

const product: Product = {
  code: 'FR_LIVRET_JEUNE',
  displayName: 'Livret jeune',
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
  asOf: '2026-09-02',
  rules: [],
  unavailableRuleKinds: ['DEPOSIT_CEILING', 'ANNUAL_RATE'],
};

function withoutNarrowSpaces(value: string) {
  return value.replace(/\s/g, ' ');
}

function figure(name: string) {
  const region = screen.getByRole('region', { name: /Valeurs au/ });

  return within(region).getByText(name, { selector: 'dt' }).closest('div');
}

describe('ProductCard', () => {
  afterEach(cleanup);

  it('uses an informational guaranteed-yield badge while the sourced rate is unavailable', () => {
    render(<ProductCard product={product} />);

    const badge = screen.getByText('Rendement garanti').parentElement;
    expect(badge?.className).toContain('info');
    expect(screen.getAllByText(/aucune valeur sourcée pour cette date/)).toHaveLength(2);
  });

  it('lifts a sourced rate and ceiling as figures above the provenance table', () => {
    render(
      <ProductCard product={{ ...product, rules: [ceiling, rate], unavailableRuleKinds: [] }} />,
    );

    const rateFigure = figure('Taux annuel');
    expect(withoutNarrowSpaces(rateFigure?.textContent ?? '')).toContain('4 %');
    expect(rateFigure?.textContent).toContain('Depuis le 1 février 2023');
    expect(rateFigure?.textContent).toContain('À revérifier');

    const ceilingFigure = figure('Plafond de dépôt');
    expect(withoutNarrowSpaces(ceilingFigure?.textContent ?? '')).toContain('1 600 €');
    expect(ceilingFigure?.textContent).toContain('Depuis le 1 janvier 2019');
    expect(ceilingFigure?.textContent).toContain('Vérifiée');

    expect(screen.getByRole('rowheader', { name: 'Taux annuel' })).toBeTruthy();
    expect(screen.getByRole('rowheader', { name: 'Plafond de dépôt' })).toBeTruthy();
  });

  it('never substitutes zero for an unavailable figure', () => {
    render(<ProductCard product={product} />);

    expect(withoutNarrowSpaces(figure('Taux annuel')?.textContent ?? '')).toContain(
      'Non disponible au 2 septembre 2026',
    );
    expect(figure('Taux annuel')?.textContent).not.toContain('0 %');
    expect(figure('Plafond de dépôt')?.textContent).not.toContain('0 €');
  });

  it('states jurisdiction, ceiling basis and default group from the catalogue payload', () => {
    render(<ProductCard product={product} />);

    expect(screen.getByText('Juridiction').nextElementSibling?.textContent).toBe('France');
    expect(screen.getByText('Base du plafond').nextElementSibling?.textContent).toContain(
      'sommes déposées, hors intérêts',
    );
    expect(screen.getByText('Groupe patrimonial').nextElementSibling?.textContent).toBe(
      'Épargne liquide',
    );
  });

  it('names a withdrawn product as withdrawn', () => {
    render(<ProductCard product={{ ...product, archivedAt: '2026-03-15T00:00:00Z' }} />);

    expect(screen.getByText('Retiré du catalogue')).toBeTruthy();
    expect(screen.getByText('Statut').nextElementSibling?.textContent).toContain('15 mars 2026');
  });

  it('never presents a market return as guaranteed', () => {
    render(
      <ProductCard
        product={{
          ...product,
          code: 'FR_CTO',
          displayName: 'Compte-titres ordinaire',
          accountKind: 'PORTFOLIO',
          wrapperKind: 'SECURITIES_ACCOUNT',
          yieldKind: 'MARKET',
          yieldGuaranteed: false,
          unavailableRuleKinds: [],
        }}
      />,
    );

    expect(screen.getByText('Rendement non garanti')).toBeTruthy();
    expect(screen.queryByText('Rendement garanti')).toBeNull();
  });

  it('names every explicit capability in a semantic list', () => {
    render(<ProductCard product={product} />);

    expect(screen.getByRole('heading', { name: 'Fonctions prises en charge' })).toBeTruthy();
    const items = screen.getAllByRole('listitem');
    expect(items.map((item) => item.textContent)).toEqual([
      'Soldes et rapprochement',
      'Transactions et virements',
      'Intérêts',
    ]);
  });
});
