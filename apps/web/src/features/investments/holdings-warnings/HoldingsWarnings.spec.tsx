import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { holdingsFixture } from '@/features/investments/holdingsFixture';
import { HoldingsWarnings } from './HoldingsWarnings';

afterEach(cleanup);

describe('HoldingsWarnings', () => {
  it('words the changed positions with the account label and the other warnings', () => {
    const holdings = holdingsFixture({
      warnings: [
        { code: 'POSITIONS_CHANGED', accountIds: ['00000000-0000-7000-8000-0000000000a1'] },
        { code: 'STALE_PRICES', accountIds: [] },
        { code: 'TRUNCATED_ACCOUNTS', accountIds: [] },
        { code: 'MIXED_VALUATION_DATES', accountIds: [] },
      ],
    });
    render(<HoldingsWarnings holdings={holdings} />);
    expect(
      screen.getByText('Des opérations sont postérieures à la dernière valorisation de PEA.'),
    ).toBeTruthy();
    expect(screen.getByText('Certains cours datent de plus de 7 jours.')).toBeTruthy();
    expect(screen.getByText('Seuls les 50 premiers comptes sont agrégés.')).toBeTruthy();
    // The mixed dates are told by the date line of the summary, not as a second warning.
    expect(screen.queryByText(/MIXED_VALUATION_DATES/)).toBeNull();
  });
});
