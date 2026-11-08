import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { DashboardPageFallback } from './DashboardPageFallback';

describe('DashboardPageFallback', () => {
  afterEach(cleanup);

  it('mirrors the dashboard cards, the emergency fund one included, with real titles', () => {
    render(<DashboardPageFallback />);

    expect(screen.getByTestId('dashboard-skeleton')).toBeTruthy();
    const fund = screen.getByTestId('emergency-fund-skeleton');
    expect(within(fund).getByRole('heading', { name: 'Fonds d’urgence' })).toBeTruthy();
    expect(
      within(fund).getByRole('button', { name: 'Modifier la politique' }).hasAttribute('disabled'),
    ).toBe(true);
    expect(fund.querySelector('[data-skeleton]')).toBeTruthy();
  });
});
