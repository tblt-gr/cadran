import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
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
  const { t } = useTranslation();
  const window = useListWindow(items, query.resetKey);
  return (
    <>
      {children(window.items)}
      {items.length > 0 ? (
        <p className="sr-only" role="status" aria-atomic="true">
          {items.length > LIST_WINDOW_SIZE
            ? t('incrementalList.window', {
                start: window.start + 1,
                end: window.end,
                total: items.length,
              })
            : t('incrementalList.loaded', { count: items.length })}
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
