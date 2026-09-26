import type { MetricPolicy } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './MetricPolicyPage.module.css';

const DATE_TIME = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' });

interface MetricPolicyHistoryProps {
  activeVersion: number;
  onActivate: (policy: MetricPolicy) => void;
  versions: MetricPolicy[];
}

export function MetricPolicyHistory({
  activeVersion,
  onActivate,
  versions,
}: MetricPolicyHistoryProps) {
  const { t } = useTranslation();

  return (
    <section aria-labelledby="metric-policy-history-title" className={`card ${styles.history}`}>
      <h3 id="metric-policy-history-title">{t('metricPolicy.history.title')}</h3>
      {versions.every((policy) => policy.system) ? (
        <p className={styles.empty}>{t('metricPolicy.history.empty')}</p>
      ) : null}
      <div className={styles.tableWrap}>
        <table>
          <caption className={styles.srOnly}>{t('metricPolicy.history.title')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('metricPolicy.history.version')}</th>
              <th scope="col">{t('metricPolicy.history.label')}</th>
              <th scope="col">{t('metricPolicy.history.excluded')}</th>
              <th scope="col">{t('metricPolicy.history.createdAt')}</th>
              <th scope="col">{t('metricPolicy.history.action')}</th>
            </tr>
          </thead>
          <tbody>
            {versions.map((policy) => (
              <tr key={policy.version}>
                <th scope="row">{policy.version}</th>
                <td>{policy.label}</td>
                <td>
                  {policy.cashExcludedAccountKinds.length === 0
                    ? t('metricPolicy.definition.noExclusion')
                    : policy.cashExcludedAccountKinds
                        .map((kind) => t(`catalog.accountKinds.${kind}`))
                        .join(', ')}
                </td>
                <td>
                  {policy.createdAt === null
                    ? t('metricPolicy.history.builtIn')
                    : DATE_TIME.format(new Date(policy.createdAt))}
                </td>
                <td>
                  <button
                    aria-label={t('metricPolicy.history.activate', { version: policy.version })}
                    className="secondary-action"
                    disabled={policy.version === activeVersion}
                    onClick={() => onActivate(policy)}
                    type="button"
                  >
                    {t('metricPolicy.history.activateShort')}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
