import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionsState } from './TransactionsState';

describe('TransactionsState', () => {
  afterEach(() => {
    cleanup();
  });

  it('shows the empty-result state distinctly from an error, without an error role', () => {
    render(<TransactionsState kind="empty" onCreate={vi.fn()} onRetry={vi.fn()} />);

    expect(screen.getByText('Aucune transaction')).not.toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('shows a dedicated state for a filter combination that can never match, offering a reset', () => {
    const onResetFilters = vi.fn();
    render(
      <TransactionsState
        kind="impossible"
        onCreate={vi.fn()}
        onResetFilters={onResetFilters}
        onRetry={vi.fn()}
      />,
    );

    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.getByText(/ne peut rien trouver/)).not.toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Réinitialiser les filtres' }));
    expect(onResetFilters).toHaveBeenCalledOnce();
  });
});
