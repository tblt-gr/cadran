import type { ParseKeys } from 'i18next';
import { Suspense, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
  SkeletonChartArea,
  SkeletonKpiCard,
  SkeletonStatus,
} from '@/components/ui/skeleton/Skeleton';
import styles from './RouteSuspense.module.css';

function RouteFallback({ titleKey }: { titleKey: ParseKeys }) {
  const { t } = useTranslation();

  // The shell header already shows the title; the heading here keeps the region
  // named for assistive technology without painting it twice.
  return (
    <section aria-busy="true" className={styles.fallback}>
      <h2 className="sr-only">{t(titleKey)}</h2>
      <SkeletonStatus>{t('routes.pageLoading')}</SkeletonStatus>
      <div className={styles.kpis}>
        <SkeletonKpiCard />
        <SkeletonKpiCard />
        <SkeletonKpiCard />
      </div>
      <SkeletonChartArea />
    </section>
  );
}

/**
 * Boundary for a lazily loaded page. Navigation runs in a transition, so this
 * fallback only appears when no page was on screen yet (first load or reload)
 * and the page chunk is still in flight.
 */
export function RouteSuspense({
  children,
  titleKey,
}: {
  children: ReactNode;
  titleKey: ParseKeys;
}) {
  return <Suspense fallback={<RouteFallback titleKey={titleKey} />}>{children}</Suspense>;
}
