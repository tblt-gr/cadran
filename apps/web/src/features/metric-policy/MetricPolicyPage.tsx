import type { MetricPolicy } from '@cadran/api-client';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SettingsTabs } from '@/components/layout/settings-tabs/SettingsTabs';
import { ActivateMetricPolicyModal } from './activate-metric-policy-modal/ActivateMetricPolicyModal';
import { CreateMetricPolicyModal } from './create-metric-policy-modal/CreateMetricPolicyModal';
import { ActiveMetricPolicyCard } from './ActiveMetricPolicyCard';
import { MetricPolicyRequestError } from './metricPolicyError';
import { MetricPolicyHistory } from './MetricPolicyHistory';
import styles from './MetricPolicyPage.module.css';
import { useMetricPolicies } from './useMetricPolicies';

export function MetricPolicyPage() {
  const { t } = useTranslation();
  const policies = useMetricPolicies();
  const [creating, setCreating] = useState(false);
  const [activating, setActivating] = useState<MetricPolicy | null>(null);
  const createButton = useRef<HTMLButtonElement>(null);
  const unauthorized =
    policies.error instanceof MetricPolicyRequestError && policies.error.kind === 'unauthorized';

  return (
    <div className={styles.page}>
      <SettingsTabs path="/settings/metric-policy" />
      <section className={styles.intro} aria-labelledby="metric-policy-title">
        <div>
          <p>{t('metricPolicy.eyebrow')}</p>
          <h2 id="metric-policy-title">{t('metricPolicy.title')}</h2>
          <span>{t('metricPolicy.description')}</span>
        </div>
        <button
          className="primary-action"
          disabled={!policies.data}
          onClick={() => setCreating(true)}
          ref={createButton}
          type="button"
        >
          {t('metricPolicy.create.open')}
        </button>
      </section>

      {policies.isPending ? (
        <section className={`card ${styles.state}`} aria-busy="true" role="status">
          <h2>{t('metricPolicy.loading')}</h2>
        </section>
      ) : policies.isError ? (
        <section className={`card ${styles.state}`} role="alert">
          <h2>{t(unauthorized ? 'metricPolicy.unauthorized' : 'metricPolicy.error')}</h2>
          {!unauthorized ? (
            <button
              className="secondary-action"
              onClick={() => void policies.refetch()}
              type="button"
            >
              {t('foundation.retry')}
            </button>
          ) : null}
        </section>
      ) : (
        <>
          <ActiveMetricPolicyCard
            active={policies.data.active}
            activeSince={policies.data.activeSince}
            activeVersion={policies.data.activeVersion}
          />
          <MetricPolicyHistory
            activeVersion={policies.data.activeVersion}
            onActivate={setActivating}
            versions={policies.data.versions}
          />
          {activating ? (
            <ActivateMetricPolicyModal
              active={policies.data.active}
              activeVersion={policies.data.activeVersion}
              close={() => setActivating(null)}
              target={activating}
            />
          ) : null}
        </>
      )}
      {creating ? <CreateMetricPolicyModal close={() => setCreating(false)} /> : null}
    </div>
  );
}
