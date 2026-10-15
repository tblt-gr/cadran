import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus, SkeletonTitle } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarDay } from '@/lib/decimal';
import cardStyles from './product-card/ProductCard.module.css';
import styles from './ProductCatalogPage.module.css';
import skeletonStyles from './ProductCatalogPageSkeleton.module.css';

const CLASSIFICATION_FIELDS = [
  'catalog.fields.jurisdiction',
  'catalog.fields.wrapper',
  'catalog.fields.yield',
  'catalog.fields.ceilingBasis',
  'catalog.fields.group',
  'catalog.fields.catalogVersion',
] as const;

function ProductCardSkeleton() {
  const { t } = useTranslation();
  return (
    <article aria-hidden="true" className={`card ${cardStyles.card}`}>
      <header className={cardStyles.header}>
        <div>
          <SkeletonTitle />
          <SkeletonBlock className={skeletonStyles.code} />
        </div>
        <div className={cardStyles.badges}>
          <SkeletonBlock className={skeletonStyles.badge} />
          <SkeletonBlock className={skeletonStyles.badge} />
        </div>
      </header>
      <SkeletonBlock className={skeletonStyles.figures} />
      <dl className={cardStyles.classification}>
        {CLASSIFICATION_FIELDS.map((field) => (
          <div key={field}>
            <dt>{t(field)}</dt>
            <dd>
              <SkeletonBlock className={skeletonStyles.field} />
            </dd>
          </div>
        ))}
      </dl>
      <SkeletonBlock className={skeletonStyles.rules} />
    </article>
  );
}

/** Mirrors the loaded catalogue element for element: the resolution date is known, products pending. */
export function ProductCatalogPageSkeleton({ asOf, status }: { asOf: string; status: string }) {
  const { i18n, t } = useTranslation();
  return (
    <div
      aria-busy="true"
      className={skeletonStyles.contents}
      data-testid="product-catalog-skeleton"
    >
      <SkeletonStatus>{status}</SkeletonStatus>
      <p className={styles.resolved}>
        {t('catalog.resolvedOn', { date: formatCalendarDay(asOf, i18n.language) })}
      </p>
      <div className={styles.products}>
        {Array.from({ length: 4 }, (_, index) => (
          <ProductCardSkeleton key={index} />
        ))}
      </div>
    </div>
  );
}
