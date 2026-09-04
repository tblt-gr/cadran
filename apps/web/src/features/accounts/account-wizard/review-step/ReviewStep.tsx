import type { CreateAccountRequest } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import type { AccountOrigin } from '@/features/accounts/account-form/accountOrigin';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import { ProductCapabilityList } from '@/features/catalog/product-capability-list/ProductCapabilityList';
import { ProductRuleList } from '@/features/catalog/product-rule-list/ProductRuleList';
import { ProductModelPeriodTable } from '@/features/product-models/product-model-periods/ProductModelPeriodTable';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './ReviewStep.module.css';

interface ReviewStepProps {
  draft: CreateAccountRequest;
  onBack: () => void;
  onConfirm: () => void;
  origin: AccountOrigin;
  pending: boolean;
  submitError: AccountErrorKind | null;
}

/**
 * The last step: what is about to be created, and what the account inherits
 * from its product or its template.
 *
 * The inherited block is read straight from that origin, with each rule's
 * effective period. A catalogue product also carries its verification state
 * and its official source; a workspace template carries neither, since
 * nobody published it — its periods are declared by the workspace itself.
 * Nothing here is copied into the account: the account stores the reference,
 * and these figures are read again on the business date they are needed. A
 * market product shows no rate at all, and a rule the catalogue cannot vouch
 * for on this date is shown as unavailable rather than as zero.
 */
export function ReviewStep({
  draft,
  onBack,
  onConfirm,
  origin,
  pending,
  submitError,
}: ReviewStepProps) {
  const { i18n, t } = useTranslation();

  return (
    <div className={styles.step}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`accounts.errors.${submitError}`)}
        </p>
      ) : null}

      <section aria-labelledby="account-review-summary" className={styles.summary}>
        <h3 id="account-review-summary">{t('accounts.wizard.review.summaryTitle')}</h3>
        <dl>
          <div>
            <dt>{t('accounts.fields.label')}</dt>
            <dd>{draft.label}</dd>
          </div>
          <div>
            <dt>{t('accounts.fields.institution')}</dt>
            <dd>{draft.institution ?? t('accounts.wizard.review.notProvided')}</dd>
          </div>
          <div>
            <dt>{t('accounts.fields.assetCode')}</dt>
            <dd>{draft.assetCode}</dd>
          </div>
          <div>
            <dt>{t('accounts.fields.kind')}</dt>
            <dd>{t(`accounts.kinds.${draft.kind}`)}</dd>
          </div>
          <div>
            <dt>{t('accounts.fields.valuationMode')}</dt>
            <dd>{t(`accounts.valuationModes.${draft.valuationMode}`)}</dd>
          </div>
          <div>
            <dt>{t('accounts.fields.liquidityLevel')}</dt>
            <dd>{t(`accounts.liquidityLevels.${draft.liquidityLevel}`)}</dd>
          </div>
          <div>
            <dt>{t('accounts.fields.openedOn')}</dt>
            <dd>{formatCalendarDay(draft.openedOn, i18n.language)}</dd>
          </div>
          <div>
            {/* The net-worth sign is the server's to state once the account
                exists; the review reports the inclusion policy only. */}
            <dt>{t('accounts.fields.inclusion')}</dt>
            <dd>
              {t(
                draft.includeInNetWorth
                  ? 'accounts.wizard.review.counted'
                  : 'accounts.contribution.excluded',
              )}
            </dd>
          </div>
        </dl>
      </section>

      {origin?.type === 'product' ? (
        <section aria-labelledby="account-review-inherited" className={styles.inherited}>
          <h3 id="account-review-inherited">
            {t('accounts.wizard.review.inheritedTitle', { product: origin.product.displayName })}
          </h3>
          <p className={styles.reference}>{t('accounts.wizard.review.referenceOnly')}</p>

          <div className={styles.badges}>
            <StatusBadge
              icon={origin.product.yieldGuaranteed ? 'goals' : 'investments'}
              tone={origin.product.yieldGuaranteed ? 'positive' : 'warning'}
            >
              {t(
                origin.product.yieldGuaranteed
                  ? 'catalog.yield.guaranteed'
                  : 'catalog.yield.market',
              )}
            </StatusBadge>
            <StatusBadge tone="info">
              {t(`accounts.wizard.review.ceilingBases.${origin.product.ceilingBasis}`)}
            </StatusBadge>
          </div>

          {!origin.product.yieldGuaranteed ? (
            <p className={styles.noYield}>{t('accounts.wizard.review.noPromisedYield')}</p>
          ) : null}

          <dl className={styles.classification}>
            <div>
              <dt>{t('accounts.wizard.review.group')}</dt>
              <dd>{origin.product.defaultGroupCode ?? t('accounts.wizard.review.notProvided')}</dd>
            </div>
            <div>
              <dt>{t('catalog.fields.wrapper')}</dt>
              <dd>{t(`catalog.wrapperKinds.${origin.product.wrapperKind}`)}</dd>
            </div>
            <div>
              <dt>{t('catalog.fields.catalogVersion')}</dt>
              <dd>{origin.product.catalogVersion}</dd>
            </div>
          </dl>

          <ProductCapabilityList
            capabilities={origin.product.capabilities}
            productCode={origin.product.code}
          />
          <ProductRuleList product={origin.product} />
        </section>
      ) : origin?.type === 'template' ? (
        <section aria-labelledby="account-review-inherited" className={styles.inherited}>
          <h3 id="account-review-inherited">
            {t('accounts.wizard.review.inheritedFromTemplateTitle', {
              template: origin.template.name,
            })}
          </h3>
          <p className={styles.reference}>{t('accounts.wizard.review.templateReferenceOnly')}</p>
          <p className={styles.reference}>{t('accounts.wizard.review.templateSchedule')}</p>

          <div className={styles.badges}>
            <StatusBadge
              icon={origin.template.yieldGuaranteed ? 'goals' : 'investments'}
              tone={origin.template.yieldGuaranteed ? 'positive' : 'warning'}
            >
              {t(
                origin.template.yieldGuaranteed
                  ? 'catalog.yield.guaranteed'
                  : 'catalog.yield.market',
              )}
            </StatusBadge>
            <StatusBadge tone="info">
              {t(`accounts.wizard.review.ceilingBases.${origin.template.ceilingBasis}`)}
            </StatusBadge>
          </div>

          {!origin.template.yieldGuaranteed ? (
            <p className={styles.noYield}>{t('accounts.wizard.review.noPromisedYield')}</p>
          ) : null}

          <dl className={styles.classification}>
            <div>
              <dt>{t('accounts.wizard.review.group')}</dt>
              <dd>{origin.template.defaultGroupCode ?? t('accounts.wizard.review.notProvided')}</dd>
            </div>
            <div>
              <dt>{t('catalog.fields.wrapper')}</dt>
              <dd>{t(`catalog.wrapperKinds.${origin.template.wrapperKind}`)}</dd>
            </div>
          </dl>

          <ProductCapabilityList
            capabilities={origin.template.capabilities}
            productCode={origin.template.id}
          />
          <ProductModelPeriodTable name={origin.template.name} rules={origin.template.rules} />
        </section>
      ) : (
        <p className={styles.noProduct}>{t('accounts.wizard.review.withoutProduct')}</p>
      )}

      <div className={styles.actions}>
        <button className="secondary-action" onClick={onBack} type="button">
          {t('accounts.form.back')}
        </button>
        <button className="primary-action" disabled={pending} onClick={onConfirm} type="button">
          {t(pending ? 'accounts.form.saving' : 'accounts.wizard.review.confirm')}
        </button>
      </div>
    </div>
  );
}
