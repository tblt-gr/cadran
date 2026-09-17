import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionListFooter } from './TransactionListFooter';

describe('TransactionListFooter', () => {
  afterEach(() => {
    cleanup();
  });

  it('offers to load more while more pages remain', () => {
    const onLoadMore = vi.fn();
    render(
      <TransactionListFooter
        hasMore
        loadingMore={false}
        onLoadMore={onLoadMore}
        onReloadFromFirstPage={vi.fn()}
        stale={false}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Mouvements plus anciens' }));
    expect(onLoadMore).toHaveBeenCalledOnce();
  });

  it('shows an end-of-list notice once there is no next page', () => {
    render(
      <TransactionListFooter
        hasMore={false}
        loadingMore={false}
        onLoadMore={vi.fn()}
        onReloadFromFirstPage={vi.fn()}
        stale={false}
      />,
    );

    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.getByText(/n’a pas d’autre mouvement/i)).not.toBeNull();
  });

  it('shows a stale indicator, taking priority over hasMore, and reloads from the first page', () => {
    const onReloadFromFirstPage = vi.fn();
    render(
      <TransactionListFooter
        hasMore
        loadingMore={false}
        onLoadMore={vi.fn()}
        onReloadFromFirstPage={onReloadFromFirstPage}
        stale
      />,
    );

    expect(screen.getByRole('alert')).not.toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /recharger/i }));
    expect(onReloadFromFirstPage).toHaveBeenCalledOnce();
  });

  it('disables the load-more button while a page is in flight, operable from the keyboard', () => {
    render(
      <TransactionListFooter
        hasMore
        loadingMore
        onLoadMore={vi.fn()}
        onReloadFromFirstPage={vi.fn()}
        stale={false}
      />,
    );

    const button = screen.getByRole('button', { name: 'Chargement…' }) as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(button.tagName).toBe('BUTTON');
  });
});
