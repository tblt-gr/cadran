import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { useState, type FormEvent } from 'react';
import styles from './VoidOperationDialog.module.css';

export function VoidOperationDialog({
  error,
  type,
  onCancel,
  onConfirm,
  pending,
}: {
  error?: string;
  type?: string;
  onCancel?: () => void;
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
      <FormGrid>
        <label data-span="full">
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
      </FormGrid>
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
        <button className="primary-action" disabled={pending} type="submit">
          {pending ? 'Annulation…' : 'Annuler l’opération'}
        </button>
      </ModalActions>
    </form>
  );
}
