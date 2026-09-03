import type { Product } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import type { ProductOptions } from '@/features/accounts/account-wizard/useProductOptions';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './ProductStep.module.css';

const NO_PRODUCT = 'NONE';

interface ProductStepProps {
  asOf: string;
  onCancel: () => void;
  onContinue: () => void;
  onSelect: (product: Product | null) => void;
  products: ProductOptions;
  selected: Product | null;
}

/**
 * The first step: which catalogue model backs the account, if any.
 *
 * A product is a reference, not a copy. Choosing one here only records which
 * model the account follows; its ceilings, rates and methods stay in the
 * catalogue and are read on the business date they are needed.
 */
export function ProductStep({
  asOf,
  onCancel,
  onContinue,
  onSelect,
  products,
  selected,
}: ProductStepProps) {
  const { i18n, t } = useTranslation();

  if (products.isPending) {
    return (
      <section aria-busy="true" className={styles.state} role="status">
        <h3>{t('accounts.wizard.product.loading')}</h3>
      </section>
    );
  }

  if (products.isError) {
    return (
      <section className={styles.state} role="alert">
        <h3>{t('accounts.wizard.product.error.title')}</h3>
        <p>{t('accounts.wizard.product.error.description')}</p>
        <div className={styles.actions}>
          <button className="secondary-action" onClick={products.refetch} type="button">
            {t('foundation.retry')}
          </button>
          {/* A catalogue that cannot be read must not block an account the user
              can describe entirely by hand. */}
          <button
            className="primary-action"
            onClick={() => {
              onSelect(null);
              onContinue();
            }}
            type="button"
          >
            {t('accounts.wizard.product.withoutProduct')}
          </button>
        </div>
      </section>
    );
  }

  return (
    <div className={styles.step}>
      <fieldset className={styles.choices}>
        <legend>{t('accounts.wizard.product.legend')}</legend>
        <p className={styles.resolved}>
          {t('accounts.wizard.product.resolvedOn', {
            date: formatCalendarDay(asOf, i18n.language),
          })}
        </p>

        <label className={styles.choice}>
          <input
            checked={selected === null}
            name="account-product"
            onChange={() => onSelect(null)}
            type="radio"
            value={NO_PRODUCT}
          />
          <span>
            <strong>{t('accounts.wizard.product.none.title')}</strong>
            <small>{t('accounts.wizard.product.none.description')}</small>
          </span>
        </label>

        {products.items.length === 0 ? (
          <p className={styles.empty}>{t('accounts.wizard.product.empty')}</p>
        ) : null}

        {/* One page holds the catalogue today. Saying so when it stops being
            true beats a list that is silently short. */}
        {products.items.length < products.total ? (
          <p className={styles.empty} role="status">
            {t('accounts.wizard.product.truncated', {
              shown: products.items.length,
              total: products.total,
            })}
          </p>
        ) : null}

        {products.items.map((product) => (
          <label className={styles.choice} key={product.code}>
            <input
              checked={selected?.code === product.code}
              name="account-product"
              onChange={() => onSelect(product)}
              type="radio"
              value={product.code}
            />
            <span>
              <strong>{product.displayName}</strong>
              <small>{product.code}</small>
              <span className={styles.badges}>
                <StatusBadge icon="accounts" tone="info">
                  {t(`catalog.accountKinds.${product.accountKind}`)}
                </StatusBadge>
                {/* Stated by the server so this list never turns a product name
                    into a promise of return. */}
                <StatusBadge
                  icon={product.yieldGuaranteed ? 'goals' : 'investments'}
                  tone={product.yieldGuaranteed ? 'positive' : 'warning'}
                >
                  {t(product.yieldGuaranteed ? 'catalog.yield.guaranteed' : 'catalog.yield.market')}
                </StatusBadge>
              </span>
            </span>
          </label>
        ))}
      </fieldset>

      <div className={styles.actions}>
        <button className="secondary-action" onClick={onCancel} type="button">
          {t('accounts.form.cancel')}
        </button>
        <button className="primary-action" onClick={onContinue} type="button">
          {t('accounts.form.continue')}
        </button>
      </div>
    </div>
  );
}
