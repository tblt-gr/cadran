import type { MetricPolicy } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { MetricPolicyDefinition } from './MetricPolicyDefinition';
import styles from './MetricPolicyPage.module.css';

const DATE_TIME = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeStyle: 'short' });

interface ActiveMetricPolicyCardProps {
  active: MetricPolicy | null;
  activeSince: string | null;
  activeVersion: number;
}

/** The active definition, or an explicit error when the active version cannot be loaded. */
export function ActiveMetricPolicyCard({
  active,
  activeSince,
  activeVersion,
}: ActiveMetricPolicyCardProps) {
  const { t } = useTranslation();

  if (active === null) {
    return (
      <section
        aria-labelledby="metric-policy-active-title"
        className={`card ${styles.active}`}
        role="alert"
      >
        <h3 id="metric-policy-active-title">{t('metricPolicy.active.title')}</h3>
        <p className={styles.unresolved}>
          {t('metricPolicy.active.unresolved', { version: activeVersion })}
        </p>
        <p className={styles.since}>{t('metricPolicy.active.unresolvedHint')}</p>
      </section>
    );
  }

  return (
    <section
      aria-labelledby="metric-policy-active-title"
      className={`card ${styles.active}`}
      role="region"
    >
      <h3 id="metric-policy-active-title">{t('metricPolicy.active.title')}</h3>
      <p className={styles.activeName}>
        {t('metricPolicy.version', { version: active.version, label: active.label })}
      </p>
      <p className={styles.since}>
        {activeSince === null
          ? t('metricPolicy.active.builtIn')
          : t('metricPolicy.active.since', { date: DATE_TIME.format(new Date(activeSince)) })}
      </p>
      <MetricPolicyDefinition policy={active} />
    </section>
  );
}
