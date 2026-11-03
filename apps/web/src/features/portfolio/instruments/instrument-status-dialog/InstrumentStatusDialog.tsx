import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { Instrument } from '@cadran/api-client';
import styles from './InstrumentStatusDialog.module.css';
export function InstrumentStatusDialog({
  error,
  instrument,
  pending,
  onCancel,
  onConfirm,
}: {
  error?: boolean;
  instrument: Instrument;
  pending: boolean;
  onCancel?: () => void;
  onConfirm: () => void;
}) {
  const deactivate = instrument.status === 'ACTIVE';
  return (
    <form
      className={styles.dialog}
      onSubmit={(event) => {
        event.preventDefault();
        onConfirm();
      }}
    >
      <p>
        {deactivate
          ? 'Cet instrument ne sera plus proposé lors des nouvelles opérations. Son historique restera lisible.'
          : 'Cet instrument sera de nouveau proposé lors des nouvelles opérations.'}
      </p>
      {error ? <p role="alert">La modification est indisponible. Réessayez.</p> : null}
      <ModalActions>
        {onCancel ? (
          <button className="secondary-action" type="button" onClick={onCancel}>
            Annuler
          </button>
        ) : null}
        <button className="primary-action" disabled={pending} type="submit">
          {pending
            ? 'Enregistrement…'
            : error
              ? 'Réessayer'
              : deactivate
                ? 'Désactiver'
                : 'Réactiver'}
        </button>
      </ModalActions>
    </form>
  );
}
