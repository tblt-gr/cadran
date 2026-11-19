import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { holdingsFixture, unsplitAccount } from '@/features/investments/holdingsFixture';
import { HoldingsView } from './HoldingsView';

afterEach(cleanup);

describe('HoldingsView', () => {
  it('keeps the total-valued account notices in a closed disclosure even without totals', () => {
    const base = holdingsFixture();
    const holdings = {
      ...base,
      items: [],
      accounts: [],
      unsplitAccounts: [unsplitAccount],
      totals: { ...base.totals, securitiesValue: null },
    };
    render(
      <HoldingsView
        holdings={holdings}
        onSortChange={vi.fn()}
        onViewChange={vi.fn()}
        sort={{ key: 'name', direction: 'asc' } as never}
        view="asset"
      />,
    );

    const summary = screen.getByText('1 avis');
    expect((summary.closest('details') as HTMLDetailsElement).open).toBe(false);
    expect(summary.closest('details')?.textContent).toContain('est valorisé en total');
  });

  it('shows the view switch in the empty card when accounts exist but no line', () => {
    const base = holdingsFixture();
    const render1 = (accounts: typeof base.accounts) =>
      render(
        <HoldingsView
          holdings={{ ...base, items: [], accounts, unsplitAccounts: [] }}
          onSortChange={vi.fn()}
          onViewChange={vi.fn()}
          sort={{ key: 'name', direction: 'asc' } as never}
          view="asset"
        />,
      );
    const { unmount } = render1(base.accounts);
    const group = screen.getByRole('group', { name: 'Affichage des positions' });
    expect(group.closest('section')?.textContent).toContain('Aucune position');
    unmount();
    render1([]);
    expect(screen.queryByRole('group', { name: 'Affichage des positions' })).toBeNull();
  });
});
