import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';

/** Stacked over the takeover modal when closing it would lose typed positions. */
export function DiscardTakeoverDialog({
  onContinue,
  onDiscard,
}: {
  onContinue: () => void;
  onDiscard: () => void;
}) {
  const { t } = useTranslation();
  return (
    <Modal close={onContinue} intent="confirm" title={t('portfolio.takeover.discard.title')}>
      <p>{t('portfolio.takeover.discard.text')}</p>
      <ModalActions>
        <button className="secondary-action" onClick={onDiscard} type="button">
          {t('portfolio.takeover.discard.discard')}
        </button>
        <button className="primary-action" data-autofocus onClick={onContinue} type="button">
          {t('portfolio.takeover.discard.continue')}
        </button>
      </ModalActions>
    </Modal>
  );
}
