import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import type { AccountOrigin } from '@/features/accounts/account-form/accountOrigin';
import type { ProductOptions } from '@/features/accounts/account-wizard/useProductOptions';
import type { TemplateOptions } from '@/features/accounts/account-wizard/useTemplateOptions';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './ProductStep.module.css';

const NO_PRODUCT = 'NONE';

interface ProductStepProps {
  asOf: string;
  onContinue: () => void;
  onSelect: (origin: AccountOrigin) => void;
  products: ProductOptions;
  selected: AccountOrigin;
  templates: TemplateOptions;
}

/**
 * The first step: what the account is created from, if anything — a system
 * catalogue product, a reusable template of the calling workspace, or a
 * description typed by hand.
 *
 * A product or a template is a reference, not a copy. Choosing one here only
 * records which one the account follows; its ceilings, rates and methods stay
 * where they are declared and are read on the business date they are needed.
 * The two lists load and fail independently, so a workspace with no template
 * yet — or a template list that failed to load — never blocks picking a
 * catalogue product, and the reverse holds too.
 */
export function ProductStep({
  asOf,
  onContinue,
  onSelect,
  products,
  selected,
  templates,
}: ProductStepProps) {
  const { i18n, t } = useTranslation();

  return (
    <div className={styles.step}>
      <fieldset className={styles.choices}>
        <legend>{t('accounts.wizard.product.legend')}</legend>

        <label className={styles.choice}>
          <input
            checked={selected === null}
            name="account-origin"
            onChange={() => onSelect(null)}
            type="radio"
            value={NO_PRODUCT}
          />
          <span>
            <strong>{t('accounts.wizard.product.none.title')}</strong>
            <small>{t('accounts.wizard.product.none.description')}</small>
          </span>
        </label>

        <fieldset className={styles.group}>
          <legend>{t('accounts.wizard.product.catalogGroup')}</legend>
          <p className={styles.resolved}>
            {t('accounts.wizard.product.resolvedOn', {
              date: formatCalendarDay(asOf, i18n.language),
            })}
          </p>

          {products.isPending ? (
            <p aria-busy="true" className={styles.empty} role="status">
              {t('accounts.wizard.product.loading')}
            </p>
          ) : products.isError ? (
            <div className={styles.state} role="alert">
              <p>{t('accounts.wizard.product.error.description')}</p>
              <button className="secondary-action" onClick={products.refetch} type="button">
                {t('foundation.retry')}
              </button>
            </div>
          ) : (
            <>
              {products.items.length === 0 ? (
                <p className={styles.empty}>{t('accounts.wizard.product.empty')}</p>
              ) : null}

              {/* One page holds the catalogue today. Saying so when it stops
                  being true beats a list that is silently short. */}
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
                    checked={selected?.type === 'product' && selected.product.code === product.code}
                    name="account-origin"
                    onChange={() => onSelect({ type: 'product', product })}
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
                      {/* Stated by the server so this list never turns a product
                          name into a promise of return. */}
                      <StatusBadge
                        icon={product.yieldGuaranteed ? 'goals' : 'investments'}
                        tone={product.yieldGuaranteed ? 'positive' : 'warning'}
                      >
                        {t(
                          product.yieldGuaranteed
                            ? 'catalog.yield.guaranteed'
                            : 'catalog.yield.market',
                        )}
                      </StatusBadge>
                    </span>
                  </span>
                </label>
              ))}
            </>
          )}
        </fieldset>

        <fieldset className={styles.group}>
          <legend>{t('accounts.wizard.product.templateGroup')}</legend>

          {templates.isPending ? (
            <p aria-busy="true" className={styles.empty} role="status">
              {t('accounts.wizard.product.templatesLoading')}
            </p>
          ) : templates.isError ? (
            <div className={styles.state} role="alert">
              <p>{t('accounts.wizard.product.templatesError')}</p>
              <button className="secondary-action" onClick={templates.refetch} type="button">
                {t('foundation.retry')}
              </button>
            </div>
          ) : (
            <>
              {templates.items.length === 0 ? (
                <p className={styles.empty}>{t('accounts.wizard.product.templatesEmpty')}</p>
              ) : null}

              {templates.items.length < templates.total ? (
                <p className={styles.empty} role="status">
                  {t('accounts.wizard.product.templatesTruncated', {
                    shown: templates.items.length,
                    total: templates.total,
                  })}
                </p>
              ) : null}

              {templates.items.map((template) => (
                <label className={styles.choice} key={template.id}>
                  <input
                    checked={selected?.type === 'template' && selected.template.id === template.id}
                    name="account-origin"
                    onChange={() => onSelect({ type: 'template', template })}
                    type="radio"
                    value={template.id}
                  />
                  <span>
                    <strong>{template.name}</strong>
                    <small>{t(`catalog.accountKinds.${template.family}`)}</small>
                    <span className={styles.badges}>
                      <StatusBadge
                        icon={template.yieldGuaranteed ? 'goals' : 'investments'}
                        tone={template.yieldGuaranteed ? 'positive' : 'warning'}
                      >
                        {t(
                          template.yieldGuaranteed
                            ? 'catalog.yield.guaranteed'
                            : 'catalog.yield.market',
                        )}
                      </StatusBadge>
                    </span>
                  </span>
                </label>
              ))}
            </>
          )}
        </fieldset>
      </fieldset>

      <div className={styles.actions}>
        <button className="primary-action" onClick={onContinue} type="button">
          {t('accounts.form.continue')}
        </button>
      </div>
    </div>
  );
}
