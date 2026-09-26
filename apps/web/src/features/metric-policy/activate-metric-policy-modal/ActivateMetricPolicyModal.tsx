import type { MetricPolicy } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { MetricPolicyDefinition } from '@/features/metric-policy/MetricPolicyDefinition';
import { metricPolicyErrorKind } from '@/features/metric-policy/metricPolicyError';
import { useActivateMetricPolicy } from '@/features/metric-policy/useMetricPolicies';
import styles from './ActivateMetricPolicyModal.module.css';

// Mirrors the API bound; the server stays the authority.
const MAX_REASON_LENGTH = 200;

interface ActivateMetricPolicyModalProps {
  active: MetricPolicy | null;
  activeVersion: number;
  close: () => void;
  target: MetricPolicy;
}

export function ActivateMetricPolicyModal({
  active,
  activeVersion,
  close,
  target,
}: ActivateMetricPolicyModalProps) {
  const { t } = useTranslation();
  const [reason, setReason] = useState('');
  const activate = useActivateMetricPolicy(close);
  const error = metricPolicyErrorKind(activate.error);

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const trimmed = reason.trim();
    activate.mutate({
      version: target.version,
      expectedActiveVersion: activeVersion,
      ...(trimmed === '' ? {} : { reason: trimmed }),
    });
  }

  return (
    <Modal
      close={close}
      eyebrow={t('metricPolicy.eyebrow')}
      title={t('metricPolicy.activate.title', { version: target.version })}
    >
      <form className={styles.form} onSubmit={handleSubmit}>
        {error ? (
          <p className={styles.error} role="alert">
            {t(`metricPolicy.errors.${error}`)}
          </p>
        ) : null}
        <div className={styles.compare}>
          <section aria-label={t('metricPolicy.activate.before')}>
            <h3>{t('metricPolicy.activate.before')}</h3>
            {active ? (
              <>
                <p>{t('metricPolicy.version', { version: active.version, label: active.label })}</p>
                <MetricPolicyDefinition policy={active} />
              </>
            ) : (
              <p>{t('metricPolicy.active.unresolved', { version: activeVersion })}</p>
            )}
          </section>
          <section aria-label={t('metricPolicy.activate.after')}>
            <h3>{t('metricPolicy.activate.after')}</h3>
            <p>{t('metricPolicy.version', { version: target.version, label: target.label })}</p>
            <MetricPolicyDefinition policy={target} />
          </section>
        </div>
        <p className={styles.note}>{t('metricPolicy.activate.closedMonths')}</p>
        <label className={styles.field}>
          <span>{t('metricPolicy.activate.reason')}</span>
          <input
            maxLength={MAX_REASON_LENGTH}
            onChange={(event) => setReason(event.target.value)}
            type="text"
            value={reason}
          />
        </label>
        <div className={styles.actions}>
          <button className="secondary-action" onClick={close} type="button">
            {t('actions.cancel')}
          </button>
          <button className="primary-action" disabled={activate.isPending} type="submit">
            {t('metricPolicy.activate.submit')}
          </button>
        </div>
      </form>
    </Modal>
  );
}
