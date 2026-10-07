import { useState, type FormEvent } from 'react';
import styles from './VoidOperationDialog.module.css';

export function VoidOperationDialog({
  error,
  type,
  onConfirm,
  pending,
}: {
  error?: string;
  type?: string;
  onConfirm: (reason: string) => void;
  pending: boolean;
}) {
  const [reason, setReason] = useState('');
  const [missing, setMissing] = useState(false);

  function submit(event: FormEvent) {
    event.preventDefault();
    const trimmed = reason.trim();
    if (trimmed === '') {
      setMissing(true);
      return;
    }
    onConfirm(trimmed);
  }

  return (
    <form className={styles.dialog} noValidate onSubmit={submit}>
      {type === 'SWITCH' ? (
        <p>L’arbitrage entier sera annulé : supports vendus et achetés.</p>
      ) : null}
      <p>
        L’opération restera visible dans l’historique avec le statut Annulée. Cette action ne peut
        pas être défaite.
      </p>
      <label>
        <span>Motif</span>
        <textarea
          data-autofocus
          maxLength={200}
          onChange={(event) => setReason(event.target.value)}
          rows={3}
          value={reason}
        />
        {missing ? (
          <small className={styles.error} role="alert">
            Le motif est obligatoire.
          </small>
        ) : null}
      </label>
      {error ? (
        <p className={styles.error} role="alert">
          {error}
        </p>
      ) : null}
      <button className="primary-action" disabled={pending} type="submit">
        {pending ? 'Annulation…' : 'Annuler l’opération'}
      </button>
    </form>
  );
}
