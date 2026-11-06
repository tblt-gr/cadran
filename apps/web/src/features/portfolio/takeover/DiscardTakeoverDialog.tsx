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
  return (
    <Modal close={onContinue} intent="confirm" title="Abandonner la saisie ?">
      <p>Les positions saisies ne seront pas enregistrées.</p>
      <ModalActions>
        <button className="secondary-action" onClick={onDiscard} type="button">
          Abandonner
        </button>
        <button className="primary-action" data-autofocus onClick={onContinue} type="button">
          Continuer la saisie
        </button>
      </ModalActions>
    </Modal>
  );
}
