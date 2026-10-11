import type { MetricPolicy } from '@cadran/api-client';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { SettingsTabs } from '@/components/layout/settings-tabs/SettingsTabs';
import { Icon } from '@/components/ui/icon/Icon';
import { ActivateMetricPolicyModal } from './activate-metric-policy-modal/ActivateMetricPolicyModal';
import { CreateMetricPolicyModal } from './create-metric-policy-modal/CreateMetricPolicyModal';
import { ActiveMetricPolicyCard } from './ActiveMetricPolicyCard';
import { MetricPolicyRequestError } from './metricPolicyError';
import { MetricPolicyHistory } from './MetricPolicyHistory';
import { MetricPolicyPageSkeleton } from './MetricPolicyPageSkeleton';
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
      <SettingsTabs path="/parameters/metric-policy" />
      <HeaderActions>
        <button
          className="primary-action"
          disabled={!policies.data}
          onClick={() => setCreating(true)}
          ref={createButton}
          type="button"
        >
          <Icon name="add" size={18} />
          {t('metricPolicy.create.open')}
        </button>
      </HeaderActions>

      {policies.isPending && policies.data === undefined ? (
        <MetricPolicyPageSkeleton status={t('metricPolicy.loading')} />
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
