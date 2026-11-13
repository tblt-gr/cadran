import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { holdingsFixture, unsplitAccount } from '@/features/investments/holdingsFixture';
import { HoldingsAlerts } from './HoldingsAlerts';

afterEach(cleanup);

describe('HoldingsAlerts', () => {
  it('groups the notices in one closed disclosure and keeps the aggregation bound visible', () => {
    const holdings = holdingsFixture({
      warnings: [
        { code: 'STALE_PRICES', accountIds: [] },
        { code: 'TRUNCATED_ACCOUNTS', accountIds: [] },
      ],
      unsplitAccounts: [unsplitAccount],
    });
    render(<HoldingsAlerts holdings={holdings} showUnsplit />);

    const summary = screen.getByText('2 avis');
    expect((summary.closest('details') as HTMLDetailsElement).open).toBe(false);
    expect(summary.closest('details')?.textContent).toContain(
      'Certains cours datent de plus de 7 jours.',
    );
    expect(summary.closest('details')?.textContent).not.toContain('50 premiers comptes');
    expect(screen.getByText('Seuls les 50 premiers comptes sont agrégés.')).toBeTruthy();
  });

  it('renders nothing when there is no notice', () => {
    const { container } = render(
      <HoldingsAlerts
        holdings={holdingsFixture({ warnings: [], unsplitAccounts: [] })}
        showUnsplit
      />,
    );
    expect(container.textContent).toBe('');
  });
});
