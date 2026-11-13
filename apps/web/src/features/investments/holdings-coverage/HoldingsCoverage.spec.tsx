import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { totalAccountSummary } from '@/features/investments/holdingsFixture';
import { HoldingsCoverage } from './HoldingsCoverage';

afterEach(cleanup);

const unvalued = {
  ...totalAccountSummary,
  accountId: 'account-2',
  label: 'PER',
  detail: 'NONE' as const,
  securitiesValue: null,
};

describe('HoldingsCoverage', () => {
  it('counts the valued accounts and lists the others with the link to their valuations', () => {
    render(<HoldingsCoverage total={3} unvalued={[unvalued]} />);

    expect(screen.getByText('2 comptes sur 3 valorisés à cette date')).toBeTruthy();
    expect(screen.getByText('1 compte sans valorisation')).toBeTruthy();
    expect(
      screen
        .getByRole('link', { name: /Saisir une valorisation détaillée\s*PER/, hidden: true })
        .getAttribute('href'),
    ).toBe('/portfolios/account-2?tab=valuations');
  });

  it('is absent while every account is valued', () => {
    const { container } = render(<HoldingsCoverage total={2} unvalued={[]} />);
    expect(container.textContent).toBe('');
  });
});
