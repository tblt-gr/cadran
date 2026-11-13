import { listProducts } from '@cadran/api-client';
import { useIncrementalList } from '@/hooks/incremental-list/useIncrementalList';
import { IncrementalList } from '@/components/ui/incremental-list/IncrementalList';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { SettingsTabs } from '@/components/layout/settings-tabs/SettingsTabs';
import { CatalogFilters } from './catalog-filters/CatalogFilters';
import { ProductCatalogTable } from './product-catalog-table/ProductCatalogTable';
import { ProductCatalogPageSkeleton } from './ProductCatalogPageSkeleton';
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

  const catalogue = useIncrementalList({
    queryKey: ['products', asOf],
    request: async ({ signal, page, revision }) => {
      const result = await listProducts({
        ...authApiOptions(),
        headers: { 'X-List-Revision': revision },
        query: { asOf, page, pageSize: PAGE_SIZE },
        signal,
      });
      return result;
    },
    requestError: (result) => new ProductRequestError(result.response?.status ?? 0),
  });

  const unauthorized =
    catalogue.error instanceof ProductRequestError && catalogue.error.status === 401;
  const items = catalogue.data?.items ?? [];
  function chooseBusinessDate(value: string) {
    setAsOf(value);
  }

  return (
    <div className={styles.page}>
      <SettingsTabs path="/parameters/catalog" />
      <CatalogFilters
        asOf={asOf}
        disabled={catalogue.isPending && catalogue.data === undefined}
        onChange={chooseBusinessDate}
        today={today}
      />

      {catalogue.isPending && catalogue.data === undefined ? (
        <ProductCatalogPageSkeleton asOf={asOf} status={t('catalog.loading')} />
      ) : catalogue.isError && !catalogue.data ? (
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
          <IncrementalList items={items} query={catalogue}>
            {(visibleItems) => <ProductCatalogTable products={visibleItems} />}
          </IncrementalList>
        </>
      )}
    </div>
  );
}
