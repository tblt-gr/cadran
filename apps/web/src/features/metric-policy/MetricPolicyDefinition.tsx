import type { MetricPolicy } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './MetricPolicyDefinition.module.css';

interface MetricPolicyDefinitionProps {
  policy: MetricPolicy;
}

/** A policy's definition in plain language: excluded kinds and both rate formulas. */
export function MetricPolicyDefinition({ policy }: MetricPolicyDefinitionProps) {
  const { t } = useTranslation();
  const excluded = policy.cashExcludedAccountKinds;

  return (
    <dl className={styles.definition}>
      <dt>{t('metricPolicy.definition.excludedKinds')}</dt>
      <dd>
        {excluded.length === 0
          ? t('metricPolicy.definition.noExclusion')
          : excluded.map((kind) => t(`catalog.accountKinds.${kind}`)).join(', ')}
      </dd>
      <dt>{t('metricPolicy.definition.savingsRate')}</dt>
      <dd>{t(`metricPolicy.formulas.${policy.savingsRateFormula}`)}</dd>
      <dt>{t('metricPolicy.definition.netSavingsRate')}</dt>
      <dd>{t(`metricPolicy.formulas.${policy.netSavingsRateFormula}`)}</dd>
    </dl>
  );
}
