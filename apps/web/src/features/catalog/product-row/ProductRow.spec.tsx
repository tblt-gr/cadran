import type { Product, ProductRule } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { ProductRow } from './ProductRow';

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

function renderRow(item: Product) {
  return render(
    <table>
      <tbody>
        <ProductRow product={item} />
      </tbody>
    </table>,
  );
}

function withoutNarrowSpaces(value: string) {
  return value.replace(/\s/g, ' ');
}

function openDetails(name: string) {
  fireEvent.click(screen.getByRole('button', { name: `Détails de ${name}` }));
}

function figure(name: string) {
  const region = screen.getByRole('region', { name: /Valeurs au/ });

  return within(region).getByText(name, { selector: 'dt' }).closest('div');
}

describe('ProductRow', () => {
  afterEach(cleanup);

  it('states the kind of return as plain text, with no yield badge', () => {
    renderRow(product);

    expect(screen.getByText('Taux réglementé')).toBeTruthy();
    expect(screen.queryByText('Rendement garanti')).toBeNull();
    // The unavailable rules are told in the details, with the date they were looked up on.
    openDetails('Livret jeune');
    expect(
      within(screen.getByRole('dialog', { name: 'Livret jeune' })).getAllByText(
        /aucune valeur sourcée pour cette date/,
      ),
    ).toHaveLength(2);
  });

  it('shows a sourced rate and ceiling as key figures, with their provenance in the details', () => {
    renderRow({ ...product, rules: [ceiling, rate], unavailableRuleKinds: [] });

    const rateFigure = figure('Taux annuel');
    expect(withoutNarrowSpaces(rateFigure?.textContent ?? '')).toContain('4,00 %');
    // Only a label and a value: the period and the verification are in the details.
    expect(rateFigure?.textContent).not.toContain('Depuis le');
    expect(rateFigure?.textContent).not.toContain('À revérifier');

    const ceilingFigure = figure('Plafond de dépôt');
    expect(withoutNarrowSpaces(ceilingFigure?.textContent ?? '')).toContain('1 600,00 €');
    expect(ceilingFigure?.textContent).not.toContain('Depuis le');
    expect(ceilingFigure?.textContent).not.toContain('Vérifiée');

    openDetails('Livret jeune');
    const dialog = within(screen.getByRole('dialog', { name: 'Livret jeune' }));
    expect(dialog.getByRole('rowheader', { name: 'Taux annuel' })).toBeTruthy();
    expect(dialog.getByRole('rowheader', { name: 'Plafond de dépôt' })).toBeTruthy();
  });

  it('never substitutes zero for an unavailable figure', () => {
    renderRow(product);

    expect(figure('Taux annuel')?.textContent).toContain('Non disponible');
    expect(figure('Taux annuel')?.textContent).not.toContain('0 %');
    expect(figure('Plafond de dépôt')?.textContent).not.toContain('0 €');
  });

  it('states jurisdiction, ceiling basis and default group from the catalogue payload', () => {
    renderRow(product);
    openDetails('Livret jeune');

    expect(screen.getByText('Juridiction').nextElementSibling?.textContent).toBe('France');
    expect(screen.getByText('Base du plafond').nextElementSibling?.textContent).toContain(
      'sommes déposées, hors intérêts',
    );
    expect(screen.getByText('Groupe patrimonial').nextElementSibling?.textContent).toBe(
      'Épargne liquide',
    );
  });

  it('names a withdrawn product as withdrawn', () => {
    renderRow({ ...product, archivedAt: '2026-03-15T00:00:00Z' });

    expect(screen.getByText('Retiré du catalogue')).toBeTruthy();
    openDetails('Livret jeune');
    expect(screen.getByText('Statut').nextElementSibling?.textContent).toContain('15 mars 2026');
  });

  it('never presents a market return as guaranteed', () => {
    renderRow({
      ...product,
      code: 'FR_CTO',
      displayName: 'Compte-titres ordinaire',
      accountKind: 'PORTFOLIO',
      wrapperKind: 'SECURITIES_ACCOUNT',
      yieldKind: 'MARKET',
      yieldGuaranteed: false,
      unavailableRuleKinds: [],
    });

    expect(screen.getByText('Marché')).toBeTruthy();
    expect(screen.queryByText('Rendement garanti')).toBeNull();
    expect(screen.queryByText('Taux garanti')).toBeNull();
  });

  it('keeps classification out of the row until the details are opened', () => {
    renderRow(product);

    expect(screen.queryByText('Juridiction')).toBeNull();
    expect(screen.queryByRole('dialog')).toBeNull();
    openDetails('Livret jeune');
    expect(screen.getByRole('dialog', { name: 'Livret jeune' })).toBeTruthy();
  });

  it('names every explicit capability in a semantic list', () => {
    renderRow(product);
    openDetails('Livret jeune');

    expect(screen.getByRole('heading', { name: 'Fonctions prises en charge' })).toBeTruthy();
    const items = screen.getAllByRole('listitem');
    expect(items.map((item) => item.textContent)).toEqual([
      'Soldes et rapprochement',
      'Transactions et virements',
      'Intérêts',
    ]);
  });
});
