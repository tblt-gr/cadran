import type { MetricPolicyReference } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { useMetricPolicyLabel } from '@/features/metric-policy/useMetricPolicyLabel';
import styles from './MetricPolicyBadge.module.css';

interface MetricPolicyBadgeProps {
  policy: MetricPolicyReference;
}

/** Names the metric policy version behind a figure; the label is a tooltip and visually hidden text for assistive tech. */
export function MetricPolicyBadge({ policy }: MetricPolicyBadgeProps) {
  const { t } = useTranslation();
  const labelOf = useMetricPolicyLabel();
  const label = labelOf(policy);

  return (
    <span className={styles.badge} title={label ?? undefined}>
      <span>
        {policy.version === null
          ? t('metricPolicy.badge.mixed')
          : t('metricPolicy.badge.version', { version: policy.version })}
      </span>
      {label ? <span className={styles.srOnly}>{`, ${label}`}</span> : null}
    </span>
  );
}
