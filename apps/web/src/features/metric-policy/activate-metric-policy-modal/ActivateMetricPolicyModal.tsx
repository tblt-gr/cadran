import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { MetricPolicy } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { MetricPolicyDefinition } from '@/features/metric-policy/MetricPolicyDefinition';
import { metricPolicyErrorKind } from '@/features/metric-policy/metricPolicyError';
import { useMetricPolicyLabel } from '@/features/metric-policy/useMetricPolicyLabel';
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
  const labelOf = useMetricPolicyLabel();
  const [reason, setReason] = useState('');
  const [advancedOpen, setAdvancedOpen] = useState(false);
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
      intent="confirm"
      title={t('metricPolicy.activate.title', { version: target.version })}
    >
      <ModalForm className={styles.form} onSubmit={handleSubmit}>
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
                <p>
                  {t('metricPolicy.version', { version: active.version, label: labelOf(active) })}
                </p>
                <MetricPolicyDefinition policy={active} />
              </>
            ) : (
              <p>{t('metricPolicy.active.unresolved', { version: activeVersion })}</p>
            )}
          </section>
          <section aria-label={t('metricPolicy.activate.after')}>
            <h3>{t('metricPolicy.activate.after')}</h3>
            <p>{t('metricPolicy.version', { version: target.version, label: labelOf(target) })}</p>
            <MetricPolicyDefinition policy={target} />
          </section>
        </div>
        <p className={styles.note}>{t('metricPolicy.activate.closedMonths')}</p>
        <FormGrid>
          <FormSection
            title={t('transactions.form.advanced')}
            open={advancedOpen}
            onToggle={setAdvancedOpen}
          >
            <label>
              <span>{t('metricPolicy.activate.reason')}</span>
              <input
                maxLength={MAX_REASON_LENGTH}
                onChange={(event) => setReason(event.target.value)}
                type="text"
                value={reason}
              />
            </label>
          </FormSection>
        </FormGrid>
        <ModalActions>
          <button className="secondary-action" onClick={close} type="button">
            {t('actions.cancel')}
          </button>
          <button className="primary-action" disabled={activate.isPending} type="submit">
            {t('metricPolicy.activate.submit')}
          </button>
        </ModalActions>
      </ModalForm>
    </Modal>
  );
}
