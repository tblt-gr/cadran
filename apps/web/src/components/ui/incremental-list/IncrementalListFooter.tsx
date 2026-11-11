import { useEffect, useLayoutEffect, useRef } from 'react';
import styles from './IncrementalListFooter.module.css';

export interface IncrementalListFooterProps {
  count: number;
  hasMore: boolean;
  loading: boolean;
  failed: boolean;
  stale: boolean;
  onLoadMore: () => void;
  onRestart: () => void;
  windowFull?: boolean;
  hasNextLoaded?: boolean;
  hasPrevious?: boolean;
  onNextLoaded?: () => void;
  onPreviousLoaded?: () => void;
}

/** A visible fallback always accompanies automatic loading; errors never retry in a loop. */
export function IncrementalListFooter(props: IncrementalListFooterProps) {
  const {
    count,
    hasMore,
    loading,
    failed,
    stale,
    onLoadMore,
    onRestart,
    windowFull,
    hasNextLoaded,
    hasPrevious,
    onNextLoaded,
    onPreviousLoaded,
  } = props;
  const sentinel = useRef<HTMLDivElement>(null);
  const wasVisible = useRef(false);
  const hadMore = useRef(hasMore);
  const reservedHeight = useRef(0);
  const complete = !hasMore && !hasNextLoaded && !hasPrevious;
  const focusedControl = sentinel.current?.contains(document.activeElement)
    ? document.activeElement
    : null;
  useLayoutEffect(() => {
    if (hasMore) hadMore.current = true;
    reservedHeight.current = Math.max(
      reservedHeight.current,
      sentinel.current?.getBoundingClientRect().height ?? 0,
    );
    // A boundary can remove the activated control; keep its focus in the same footer.
    if (focusedControl && !focusedControl.isConnected) {
      sentinel.current?.focus({ preventScroll: true });
    }
  });
  const latest = useRef(props);
  useEffect(() => {
    latest.current = props;
  });
  useEffect(() => {
    if (!sentinel.current || typeof IntersectionObserver === 'undefined') return;
    const observer = new IntersectionObserver(
      ([entry]) => {
        const approaching = Boolean(entry?.isIntersecting) && !wasVisible.current;
        wasVisible.current = Boolean(entry?.isIntersecting);
        const current = latest.current;
        if (
          approaching &&
          current.hasMore &&
          !current.loading &&
          !current.failed &&
          !current.stale &&
          !current.windowFull
        )
          current.onLoadMore();
      },
      { rootMargin: '0px 0px 240px 0px' },
    );
    observer.observe(sentinel.current);
    return () => observer.disconnect();
  }, [count > 0, hasMore, failed, stale, windowFull]);
  if (count === 0) return null;
  if (complete && !hadMore.current && !stale) return null;
  return (
    <div
      className={styles.footer}
      ref={sentinel}
      style={{ minHeight: reservedHeight.current || undefined }}
      tabIndex={-1}
    >
      {stale ? (
        <>
          <p role="alert">La liste a changé. Rechargez-la pour continuer sans omettre d’élément.</p>
          <button className="secondary-action" onClick={onRestart} type="button">
            Recharger la liste
          </button>
        </>
      ) : complete ? (
        <p>Tous les éléments sont affichés.</p>
      ) : null}
      {hasPrevious ? (
        <button className="secondary-action" onClick={onPreviousLoaded} type="button">
          Éléments précédents déjà chargés
        </button>
      ) : null}
      {hasNextLoaded ? (
        <button className="secondary-action" onClick={onNextLoaded} type="button">
          Éléments suivants déjà chargés
        </button>
      ) : null}
      {failed && !stale ? (
        <p role="alert">La suite de la liste est indisponible. Vous pouvez réessayer.</p>
      ) : null}
      {hasMore && !hasNextLoaded && !stale ? (
        <button
          aria-busy={loading || undefined}
          className="secondary-action"
          disabled={loading}
          onClick={onLoadMore}
          type="button"
        >
          <span aria-live="polite">
            {loading ? 'Chargement de la suite…' : failed ? 'Réessayer' : 'Afficher plus'}
          </span>
        </button>
      ) : null}
    </div>
  );
}
