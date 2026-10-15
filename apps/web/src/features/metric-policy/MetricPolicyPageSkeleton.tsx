import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import {
  SkeletonBlock,
  SkeletonStatus,
  SkeletonTableRows,
} from '@/components/ui/skeleton/Skeleton';
import definitionStyles from './MetricPolicyDefinition.module.css';
import styles from './MetricPolicyPage.module.css';

const DEFINITION_LABELS = [
  'metricPolicy.definition.excludedKinds',
  'metricPolicy.definition.savingsRate',
  'metricPolicy.definition.netSavingsRate',
] as const;

/**
 * Mirrors ActiveMetricPolicyCard and MetricPolicyHistory element for element:
 * card titles and labels are real, only the policy values and versions are pending.
 */
export function MetricPolicyPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={styles.page} data-testid="metric-policy-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <section className={`card ${styles.active}`}>
        <h3>{t('metricPolicy.active.title')}</h3>
        <p className={styles.activeName}>
          <SkeletonBlock className={styles.skeletonName} />
        </p>
        <p className={styles.since}>
          <SkeletonBlock className={styles.skeletonName} />
        </p>
        <dl className={definitionStyles.definition}>
          {DEFINITION_LABELS.map((label) => (
            <Fragment key={label}>
              <dt>{t(label)}</dt>
              <dd>
                <SkeletonBlock className={styles.skeletonName} />
              </dd>
            </Fragment>
          ))}
        </dl>
      </section>
      <section className={`card ${styles.history}`}>
        <h3>{t('metricPolicy.history.title')}</h3>
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
            <SkeletonTableRows columns={5} rows={3} />
          </table>
        </div>
      </section>
    </div>
  );
}
