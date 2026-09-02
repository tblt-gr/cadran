import type { Product } from '@cadran/api-client';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { ProductCard } from './ProductCard';

const product: Product = {
  code: 'FR_LIVRET_JEUNE',
  displayName: 'Livret jeune',
  jurisdiction: 'FR',
  accountKind: 'SAVINGS',
  wrapperKind: 'REGULATED_SAVINGS',
  yieldKind: 'REGULATED_RATE',
  yieldGuaranteed: true,
  defaultGroupCode: 'LIQUIDITY_SAVINGS',
  catalogVersion: 1,
  archivedAt: null,
  asOf: '2026-09-02',
  rules: [],
  unavailableRuleKinds: ['DEPOSIT_CEILING', 'ANNUAL_RATE'],
};

describe('ProductCard', () => {
  afterEach(cleanup);

  it('uses an informational guaranteed-yield badge while the sourced rate is unavailable', () => {
    render(<ProductCard product={product} />);

    const badge = screen.getByText('Rendement garanti').parentElement;
    expect(badge?.className).toContain('info');
    expect(screen.getAllByText(/Non disponible au 2 septembre 2026/)).toHaveLength(2);
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
});
