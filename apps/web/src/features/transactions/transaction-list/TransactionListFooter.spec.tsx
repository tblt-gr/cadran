import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionListFooter } from './TransactionListFooter';

describe('TransactionListFooter', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('requests once per approach, does not cascade while visible, and leaves retry explicit', () => {
    let notify: IntersectionObserverCallback | undefined;
    const disconnect = vi.fn();
    vi.stubGlobal(
      'IntersectionObserver',
      class {
        constructor(callback: IntersectionObserverCallback) {
          notify = callback;
        }
        observe() {}
        disconnect = disconnect;
      },
    );
    const onLoadMore = vi.fn();
    const props = {
      hasMore: true,
      loadingMore: false,
      onLoadMore,
      onReloadFromFirstPage: vi.fn(),
      stale: false,
    };
    const { rerender, unmount } = render(<TransactionListFooter {...props} />);
    const intersect = (isIntersecting: boolean) =>
      act(() =>
        notify?.([{ isIntersecting } as IntersectionObserverEntry], {} as IntersectionObserver),
      );
    intersect(true);
    intersect(true);
    rerender(<TransactionListFooter {...props} loadingMore />);
    rerender(<TransactionListFooter {...props} />);
    intersect(true);
    expect(onLoadMore).toHaveBeenCalledTimes(1);
    intersect(false);
    intersect(true);
    expect(onLoadMore).toHaveBeenCalledTimes(2);
    rerender(<TransactionListFooter {...props} failed />);
    intersect(false);
    intersect(true);
    expect(onLoadMore).toHaveBeenCalledTimes(2);
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(onLoadMore).toHaveBeenCalledTimes(3);
    unmount();
    expect(disconnect).toHaveBeenCalled();
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

    fireEvent.click(screen.getByRole('button', { name: 'Afficher plus' }));
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

    const button = screen.getByRole('button', {
      name: 'Chargement des mouvements suivants…',
    }) as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(button.tagName).toBe('BUTTON');
  });
});
