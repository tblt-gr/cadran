import type { MetricPolicyReference } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './MetricPolicyBadge.module.css';

interface MetricPolicyBadgeProps {
  policy: MetricPolicyReference;
}

/** Names the metric policy version behind a figure; the label is a tooltip and visually hidden text for assistive tech. */
export function MetricPolicyBadge({ policy }: MetricPolicyBadgeProps) {
  const { t } = useTranslation();

  return (
    <span className={styles.badge} title={policy.label ?? undefined}>
      <span>
        {policy.version === null
          ? t('metricPolicy.badge.mixed')
          : t('metricPolicy.badge.version', { version: policy.version })}
      </span>
      {policy.label ? <span className={styles.srOnly}>{`, ${policy.label}`}</span> : null}
    </span>
  );
}
