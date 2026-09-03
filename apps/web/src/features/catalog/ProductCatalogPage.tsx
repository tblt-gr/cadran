import { listProducts } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { BusinessDateField } from '@/features/catalog/business-date-field/BusinessDateField';
import { ProductCard } from '@/features/catalog/product-card/ProductCard';
import { todayInBrowser } from '@/lib/businessDay';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './ProductCatalogPage.module.css';

const PAGE_SIZE = 25;

class ProductRequestError extends Error {
  readonly status: number;

  constructor(status: number) {
    super(`Product catalogue request failed with status ${status}.`);
    this.status = status;
  }
}

/**
 * The system product catalogue, read on a business date.
 *
 * Every regulatory figure on this page comes from the API with its effective
 * period, its verification state and its official source. Nothing here computes
 * a financial value, and nothing substitutes a zero for a value the catalogue
 * cannot vouch for on the chosen date.
 */
export function ProductCatalogPage() {
  const { i18n, t } = useTranslation();
  const today = todayInBrowser();
  const [asOf, setAsOf] = useState(today);
  const [page, setPage] = useState(1);

  const catalogue = useQuery({
    queryKey: ['products', asOf, page],
    queryFn: async ({ signal }) => {
      const result = await listProducts({
        ...authApiOptions(),
        query: { asOf, page, perPage: PAGE_SIZE },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw new ProductRequestError(result.response?.status ?? 0);
      }
      return result.data;
    },
    retry: false,
  });

  const unauthorized =
    catalogue.error instanceof ProductRequestError && catalogue.error.status === 401;
  const items = catalogue.data?.items ?? [];
  const totalPages = Math.max(1, Math.ceil((catalogue.data?.total ?? 0) / PAGE_SIZE));

  // The catalogue can shrink under the current page after a migration removes a
  // model. React re-renders with the corrected page before committing, so the
  // empty state is never shown for a catalogue that still holds products.
  if (catalogue.data && page > totalPages) {
    setPage(totalPages);
  }

  function chooseBusinessDate(value: string) {
    setAsOf(value);
    setPage(1);
  }

  return (
    <div className={styles.page}>
      <section className={styles.intro} aria-labelledby="catalog-intro-title">
        <div>
          <p>{t('catalog.eyebrow')}</p>
          <h2 id="catalog-intro-title">{t('catalog.title')}</h2>
          <span>{t('catalog.description')}</span>
        </div>
        <BusinessDateField onChange={chooseBusinessDate} today={today} value={asOf} />
      </section>

      {catalogue.isPending ? (
        <section className={`card ${styles.state}`} aria-busy="true" role="status">
          <h2>{t('catalog.loading')}</h2>
        </section>
      ) : catalogue.isError ? (
        <section className={`card ${styles.state}`} role="alert">
          <h2>{t(unauthorized ? 'catalog.unauthorized.title' : 'catalog.error.title')}</h2>
          <p>
            {t(unauthorized ? 'catalog.unauthorized.description' : 'catalog.error.description')}
          </p>
          {!unauthorized ? (
            <button
              className="secondary-action"
              onClick={() => void catalogue.refetch()}
              type="button"
            >
              {t('foundation.retry')}
            </button>
          ) : null}
        </section>
      ) : items.length === 0 ? (
        <section className={`card ${styles.state}`}>
          <h2>{t('catalog.empty.title')}</h2>
          <p>{t('catalog.empty.description')}</p>
        </section>
      ) : (
        <>
          <p className={styles.resolved} role="status">
            {t('catalog.resolvedOn', { date: formatCalendarDay(asOf, i18n.language) })}
          </p>
          <div className={styles.products}>
            {items.map((product) => (
              <ProductCard key={product.code} product={product} />
            ))}
          </div>
        </>
      )}

      {catalogue.isSuccess && (totalPages > 1 || page > 1) ? (
        <nav className={styles.pagination} aria-label={t('catalog.pagination.label')}>
          <button
            className="secondary-action"
            disabled={page === 1}
            onClick={() => setPage((current) => current - 1)}
            type="button"
          >
            {t('catalog.pagination.previous')}
          </button>
          <span>{t('catalog.pagination.position', { page, total: totalPages })}</span>
          <button
            className="secondary-action"
            disabled={page >= totalPages}
            onClick={() => setPage((current) => current + 1)}
            type="button"
          >
            {t('catalog.pagination.next')}
          </button>
        </nav>
      ) : null}
    </div>
  );
}
