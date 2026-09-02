import type { Product } from '@cadran/api-client';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { ProductRuleList } from './ProductRuleList';

const product: Product = {
  code: 'FR_LIVRET_A',
  displayName: 'Livret A',
  jurisdiction: 'FR',
  accountKind: 'SAVINGS',
  wrapperKind: 'REGULATED_SAVINGS',
  yieldKind: 'REGULATED_RATE',
  yieldGuaranteed: true,
  defaultGroupCode: 'LIQUIDITY_SAVINGS',
  catalogVersion: 1,
  archivedAt: null,
  asOf: '2026-09-02',
  unavailableRuleKinds: [],
  rules: [
    {
      kind: 'DEPOSIT_CEILING',
      valueType: 'AMOUNT',
      amount: { value: '22950', assetCode: 'EUR' },
      percentage: null,
      text: null,
      validFrom: '2025-04-25',
      validTo: null,
      verification: 'VERIFIED',
      verifiedOn: '2026-08-22',
      verifiedBy: 'cadran-maintainer',
      source: {
        publisher: 'Direction de l’information légale et administrative',
        title: 'Livret A',
        url: 'https://www.service-public.fr/particuliers/vosdroits/F2365',
        publishedOn: null,
        retrievedOn: '2026-08-22',
      },
    },
  ],
};

describe('ProductRuleList', () => {
  afterEach(cleanup);

  it('shows the exact value, verification words and official source without an implicit alert icon', () => {
    const { container } = render(<ProductRuleList product={product} />);

    const row = screen.getByRole('rowheader', { name: 'Plafond de dépôt' }).closest('tr');
    expect(row?.textContent?.replace(/\s/g, ' ')).toContain('22 950 €');
    expect(row?.textContent).toContain('Vérifiée');
    expect(screen.getByRole('link', { name: 'Livret A' }).getAttribute('href')).toBe(
      'https://www.service-public.fr/particuliers/vosdroits/F2365',
    );
    expect(container.querySelector('svg')).toBeNull();
  });

  it('renders every unavailable rule explicitly and never substitutes zero', () => {
    render(
      <ProductRuleList
        product={{
          ...product,
          rules: [],
          unavailableRuleKinds: ['DEPOSIT_CEILING', 'ANNUAL_RATE'],
        }}
      />,
    );

    expect(screen.getByRole('rowheader', { name: 'Plafond de dépôt' })).toBeTruthy();
    expect(screen.getByRole('rowheader', { name: 'Taux annuel' })).toBeTruthy();
    expect(screen.getAllByText(/Non disponible au 2 septembre 2026/)).toHaveLength(2);
    expect(screen.queryByText('0 %')).toBeNull();
  });
});
