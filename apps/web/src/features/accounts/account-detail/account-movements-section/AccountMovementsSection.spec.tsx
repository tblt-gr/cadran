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
        language="fr"
        loadingMore={false}
        month={null}
        onClearMonth={vi.fn()}
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

  it('shows the selection chrome and a disabled checkbox per row while loading', () => {
    renderSection({ isPending: true });

    expect(
      screen.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }),
    ).toBeTruthy();
    expect(
      (screen.getByRole('button', { name: 'Changer la catégorie' }) as HTMLButtonElement).disabled,
    ).toBe(true);
    const rows = screen.getAllByRole('checkbox', { name: 'Sélectionner un mouvement' });
    expect(rows.every((row) => (row as HTMLInputElement).disabled)).toBe(true);
  });
});

function renderSection(state: { isPending: boolean }) {
  render(
    <AccountMovementsSection
      canCreate
      displayedPageHasMore={false}
      items={[]}
      language="fr"
      loadingMore={false}
      month={null}
      onClearMonth={vi.fn()}
      onCreate={vi.fn()}
      onLoadMore={vi.fn()}
      onReloadFromFirstPage={vi.fn()}
      staleCursorError={false}
      transactions={{ ...state, isError: false, refetch: vi.fn() }}
      unauthorized={false}
    />,
  );
}
