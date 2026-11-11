import type { ReactNode } from 'react';
import { LIST_WINDOW_SIZE, useListWindow } from '@/hooks/incremental-list/useListWindow';
import { IncrementalListFooter } from './IncrementalListFooter';

export function IncrementalList<T>({
  items,
  children,
  query,
}: {
  items: T[];
  children: (items: T[]) => ReactNode;
  query: {
    resetKey: string;
    hasNextPage: boolean;
    isFetching: boolean;
    isError: boolean;
    stale: boolean;
    loadMore: () => void;
    restart: () => void;
  };
}) {
  const window = useListWindow(items, query.resetKey);
  return (
    <>
      {children(window.items)}
      {items.length > 0 ? (
        <p className="sr-only" role="status" aria-atomic="true">
          {items.length > LIST_WINDOW_SIZE
            ? `Éléments ${window.start + 1} à ${window.end} affichés sur ${items.length} chargés`
            : items.length === 1
              ? '1 élément chargé'
              : `${items.length} éléments chargés`}
        </p>
      ) : null}
      <IncrementalListFooter
        count={items.length}
        hasMore={query.hasNextPage}
        loading={query.isFetching}
        failed={query.isError}
        stale={query.stale}
        onLoadMore={query.loadMore}
        onRestart={query.restart}
        windowFull={window.full}
        hasNextLoaded={window.hasNextLoaded}
        hasPrevious={window.hasPrevious}
        onNextLoaded={window.next}
        onPreviousLoaded={window.previous}
      />
    </>
  );
}
