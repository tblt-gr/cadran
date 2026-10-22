import type { MetricPolicyReference } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { Modal } from '@/components/ui/modal/Modal';
import { MetricPolicyBadge } from '@/features/metric-policy/metric-policy-badge/MetricPolicyBadge';
import styles from './RecapInfo.module.css';

interface RecapInfoProps {
  pending: boolean;
  policy: MetricPolicyReference;
}

/** Metric policy and pending-operations notice behind an "i" button; absent when there is no pending operation to explain. */
export function RecapInfo({ pending, policy }: RecapInfoProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  if (!pending) return null;
  return (
    <>
      <InfoButton
        aria-haspopup="dialog"
        label={t('budget.monthly.recap.info.open')}
        onClick={() => setOpen(true)}
      />
      {open ? (
        <Modal close={() => setOpen(false)} title={t('budget.monthly.recap.info.title')}>
          <div className={styles.content}>
            <p>
              <MetricPolicyBadge policy={policy} />
            </p>
            <p role="status">{t('budget.monthly.recap.pending')}</p>
          </div>
        </Modal>
      ) : null}
    </>
  );
}
