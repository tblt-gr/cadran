import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { BulkSelectionToolbar } from './BulkSelectionToolbar';
import type { BulkSelection } from './useBulkSelection';

vi.mock('./bulk-edit-modal/BulkEditModal', () => ({
  BulkEditModal: () => <div role="dialog">Modale d’édition</div>,
}));

function bulk(selectedCount: number): BulkSelection {
  return {
    allPageSelected: false,
    clear: vi.fn(),
    filterScopeSelected: false,
    matchingCount: null,
    offerFilterScope: false,
    pageCount: 3,
    pageItems: [],
    reloadAfterWrite: () => Promise.resolve(),
    rowSelection: { onToggle: vi.fn(), selectedIds: new Set() },
    scope: selectedCount > 0 ? { type: 'IDS', ids: ['a'] } : null,
    selectedCount,
    selectFilterScope: vi.fn(),
    somePageSelected: selectedCount > 0,
    togglePage: vi.fn(),
  };
}

function renderToolbar(selectedCount: number) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <BulkSelectionToolbar active bulk={bulk(selectedCount)} onToggleActive={vi.fn()} />
    </QueryClientProvider>,
  );
}

describe('BulkSelectionToolbar', () => {
  afterEach(cleanup);

  it('offers Modifier only once something is selected and opens the edit modal', () => {
    renderToolbar(0);
    expect((screen.getByRole('button', { name: 'Modifier' }) as HTMLButtonElement).disabled).toBe(
      true,
    );
    cleanup();

    renderToolbar(2);
    expect(screen.queryByRole('button', { name: 'Changer la catégorie' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
    expect(screen.getByRole('dialog').textContent).toBe('Modale d’édition');
  });
});
