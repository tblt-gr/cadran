import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { ProductCapabilityList } from './ProductCapabilityList';

describe('ProductCapabilityList', () => {
  afterEach(cleanup);

  it('renders server-declared capabilities as a named semantic list', () => {
    render(
      <ProductCapabilityList
        capabilities={[
          'SUPPORTS_BALANCE',
          'SUPPORTS_TRANSACTIONS',
          'SUPPORTS_HOLDINGS',
          'SUPPORTS_TRADES',
        ]}
        productCode="FR_CTO"
      />,
    );

    expect(screen.getByRole('heading', { name: 'Fonctions prises en charge' })).toBeTruthy();
    expect(screen.getAllByRole('listitem').map((item) => item.textContent)).toEqual([
      'Soldes et rapprochement',
      'Transactions et virements',
      'Titres et positions',
      'Achats et ventes de titres',
    ]);
  });
});
