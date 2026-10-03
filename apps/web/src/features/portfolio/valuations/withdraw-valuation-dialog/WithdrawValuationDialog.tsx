import styles from './WithdrawValuationDialog.module.css';

export function WithdrawValuationDialog({
  error,
  onConfirm,
  pending,
}: {
  error?: string;
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
      <button
        className="primary-action"
        data-autofocus
        disabled={pending}
        onClick={onConfirm}
        type="button"
      >
        {pending ? 'Retrait…' : 'Retirer'}
      </button>
    </div>
  );
}
