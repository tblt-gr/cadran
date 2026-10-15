import type { NetWorthContribution } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { MissingValuationList } from './missing-valuation-list/MissingValuationList';
import { MissingValuationRecorder } from './missing-valuation-recorder/MissingValuationRecorder';

interface MissingValuationsModalProps {
  close: () => void;
  contributions: NetWorthContribution[];
}

/**
 * Names the accounts behind "N valorisations manquantes" and lets each one be
 * fixed where it is: a balance recorded in place, or the account opened.
 * The list reads the live net worth, so a recorded balance drops its row.
 */
export function MissingValuationsModal({ close, contributions }: MissingValuationsModalProps) {
  const { t } = useTranslation();
  const [recording, setRecording] = useState<NetWorthContribution | null>(null);
  const missing = contributions.filter(
    (contribution) => contribution.eligible && contribution.quality === 'MISSING',
  );

  return (
    <Modal
      close={close}
      eyebrow={t('dashboard.missingValuations.eyebrow')}
      title={
        recording
          ? t('accounts.balances.title', { label: recording.label })
          : t('dashboard.missingValuations.title', { count: missing.length })
      }
    >
      {recording ? (
        <MissingValuationRecorder
          accountId={recording.accountId}
          key={recording.accountId}
          onBack={() => setRecording(null)}
          onNavigate={close}
          onRecorded={() => setRecording(null)}
        />
      ) : (
        <MissingValuationList missing={missing} onNavigate={close} onRecord={setRecording} />
      )}
    </Modal>
  );
}
