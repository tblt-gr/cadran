import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountMovementsSection } from './AccountMovementsSection';

afterEach(cleanup);

describe('AccountMovementsSection loading', () => {
  it('keeps the title and column headers as real text over a skeleton table, announced once', () => {
    render(
      <AccountMovementsSection
        canCreate
        displayedPageHasMore={false}
        items={[]}
        loadingMore={false}
        onCreate={vi.fn()}
        onLoadMore={vi.fn()}
        onReloadFromFirstPage={vi.fn()}
        staleCursorError={false}
        transactions={{ isPending: true, isError: false, refetch: vi.fn() }}
        unauthorized={false}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Mouvements' })).toBeTruthy();
    expect(screen.getByRole('list', { name: 'Mouvements de ce compte' })).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(screen.getByTestId('transaction-day-list-skeleton').getAttribute('aria-busy')).toBe(
      'true',
    );
    expect(screen.queryByRole('heading', { level: 2 })).toBeNull();
  });

  it('shows the pen as disabled chrome and no checkbox while loading', () => {
    renderSection({ isPending: true }, vi.fn());

    expect(screen.queryByRole('checkbox')).toBeNull();
    expect(
      (screen.getByRole('button', { name: 'Édition groupée' }) as HTMLButtonElement).disabled,
    ).toBe(true);
  });
});

function renderSection(state: { isPending: boolean }, onBulkModeChange?: () => void) {
  render(
    <AccountMovementsSection
      canCreate
      displayedPageHasMore={false}
      items={[]}
      loadingMore={false}
      onBulkModeChange={onBulkModeChange}
      onCreate={vi.fn()}
      onLoadMore={vi.fn()}
      onReloadFromFirstPage={vi.fn()}
      staleCursorError={false}
      transactions={{ ...state, isError: false, refetch: vi.fn() }}
      unauthorized={false}
    />,
  );
}

describe('AccountMovementsSection states', () => {
  it('names contradictory filters and offers to reset them, without a list', () => {
    const onResetFilters = vi.fn();
    render(
      <AccountMovementsSection
        canCreate
        displayedPageHasMore={false}
        impossible
        items={[]}
        loadingMore={false}
        onCreate={vi.fn()}
        onLoadMore={vi.fn()}
        onReloadFromFirstPage={vi.fn()}
        onResetFilters={onResetFilters}
        staleCursorError={false}
        transactions={{ isPending: true, isError: false, refetch: vi.fn() }}
        unauthorized={false}
      />,
    );

    expect(screen.queryByTestId('transaction-day-list-skeleton')).toBeNull();
    screen.getByRole('button', { name: 'Réinitialiser les filtres' }).click();
    expect(onResetFilters).toHaveBeenCalled();
  });
});
