import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import styles from './WithdrawValuationDialog.module.css';

export function WithdrawValuationDialog({
  error,
  onCancel,
  onConfirm,
  pending,
}: {
  error?: string;
  onCancel?: () => void;
  onConfirm: () => void;
  pending: boolean;
}) {
  return (
    <div className={styles.dialog}>
      <p>
        La valeur publiée dans le patrimoine sera retirée ; la valorisation reste dans l’historique.
      </p>
      {error ? (
        <p className={styles.error} role="alert">
          {error}
        </p>
      ) : null}
      <ModalActions>
        {onCancel ? (
          <button className="secondary-action" type="button" onClick={onCancel}>
            Annuler
          </button>
        ) : null}
        <button
          className="primary-action"
          data-autofocus
          disabled={pending}
          onClick={onConfirm}
          type="button"
        >
          {pending ? 'Retrait…' : 'Retirer'}
        </button>
      </ModalActions>
    </div>
  );
}
