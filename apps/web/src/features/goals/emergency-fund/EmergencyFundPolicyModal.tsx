import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { goalErrorKind } from '@/features/goals/goalError';
import { EmergencyFundPolicyForm } from './EmergencyFundPolicyForm';
import styles from './EmergencyFund.module.css';
import { useEmergencyFundPolicy, useUpdateEmergencyFundPolicy } from './useEmergencyFund';

/** Edits the workspace emergency-fund policy; a 409 reloads the latest version before a retry. */
export function EmergencyFundPolicyModal({ close }: { close: () => void }) {
  const { t } = useTranslation();
  const policy = useEmergencyFundPolicy(true);
  const save = useUpdateEmergencyFundPolicy();
  return (
    <Modal
      close={close}
      eyebrow={t('goals.emergencyFund.eyebrow')}
      title={t('goals.emergencyFund.policyTitle')}
    >
      {policy.isPending ? (
        <p aria-busy="true" className={styles.hint} role="status">
          {t('goals.emergencyFund.loading')}
        </p>
      ) : policy.data ? (
        <EmergencyFundPolicyForm
          onSubmit={(body) => save.mutate(body, { onSuccess: close })}
          pending={save.isPending}
          policy={policy.data}
          submitError={goalErrorKind(save.error, save.isError)}
        />
      ) : (
        <p role="alert">
          {t(
            goalErrorKind(policy.error, policy.isError) === 'emergency_fund_scope_too_large'
              ? 'goals.emergencyFund.errors.emergency_fund_scope_too_large'
              : 'goals.emergencyFund.error',
          )}
        </p>
      )}
    </Modal>
  );
}
