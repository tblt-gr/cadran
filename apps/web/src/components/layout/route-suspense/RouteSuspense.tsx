import type { ParseKeys } from 'i18next';
import { Suspense, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';

/**
 * Without the page chunk there is no layout to reproduce: drawing generic cards
 * would show a page that does not exist, so the region stays empty, named and
 * announced until the page (or its own skeleton) paints.
 */
function RouteFallback({ titleKey }: { titleKey: ParseKeys }) {
  const { t } = useTranslation();

  // The shell header already shows the title; the heading here keeps the region
  // named for assistive technology without painting it twice.
  return (
    <section aria-busy="true">
      <h2 className="sr-only">{t(titleKey)}</h2>
      <SkeletonStatus>{t('routes.pageLoading')}</SkeletonStatus>
    </section>
  );
}

/**
 * Boundary for a lazily loaded page. Navigation runs in a transition, so this
 * fallback only appears when no page was on screen yet (first load or reload)
 * and the page chunk is still in flight. A page whose chunk may be missing on
 * first paint (the landing dashboard) passes its own faithful `fallback`.
 */
export function RouteSuspense({
  children,
  fallback,
  titleKey,
}: {
  children: ReactNode;
  fallback?: ReactNode;
  titleKey: ParseKeys;
}) {
  return (
    <Suspense fallback={fallback ?? <RouteFallback titleKey={titleKey} />}>{children}</Suspense>
  );
}
