import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { ValueHistoryTable } from './ValueHistoryTable';
afterEach(cleanup);
it('makes the valuation chart data table keyboard-scrollable with its own name', () => {
  render(<ValueHistoryTable locale="fr" points={[]} />);
  const region = screen.getByRole('region', { name: 'Valorisations du portefeuille par date' });
  expect(region.tabIndex).toBe(0);
  expect(region.querySelector('table')).toBeTruthy();
});
