import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { Modal } from '@/components/ui/modal/Modal';

export function RefundMovementInfo() {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);

  return (
    <>
      <InfoButton
        aria-haspopup="dialog"
        label={t('budget.monthly.refundInfo')}
        onClick={() => setOpen(true)}
      />
      {open ? (
        <Modal close={() => setOpen(false)} intent="detail" title={t('budget.monthly.refundTitle')}>
          <p>{t('budget.monthly.refundHint')}</p>
        </Modal>
      ) : null}
    </>
  );
}
