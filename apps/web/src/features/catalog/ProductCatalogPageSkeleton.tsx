import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { SkeletonBlock, SkeletonStatus, SkeletonTitle } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarDay } from '@/lib/decimal';
import cardStyles from './product-card/ProductCard.module.css';
import styles from './ProductCatalogPage.module.css';
import skeletonStyles from './ProductCatalogPageSkeleton.module.css';

function ProductCardSkeleton() {
  const { t } = useTranslation();
  return (
    <article aria-hidden="true" className={`card ${cardStyles.card}`}>
      <header className={cardStyles.header}>
        <div className={cardStyles.title}>
          <SkeletonTitle />
          <InfoButton disabled label={t('catalog.details.open', { product: '' })} />
        </div>
        <div className={cardStyles.badges}>
          <SkeletonBlock className={skeletonStyles.badge} />
          <SkeletonBlock className={skeletonStyles.badge} />
        </div>
      </header>
      <SkeletonBlock className={skeletonStyles.figures} />
      <Disclosure
        disabled
        onToggle={() => undefined}
        open={false}
        title={t('catalog.rules.disclosure')}
      >
        {null}
      </Disclosure>
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
