import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { InstrumentCandidate } from '@cadran/api-client';
import styles from './DuplicateNotice.module.css';
export function DuplicateNotice({
  candidates,
  editing = false,
  onCancel,
  onConfirm,
  onUse,
  pending = false,
}: {
  candidates: InstrumentCandidate[];
  editing?: boolean;
  onCancel: () => void;
  onConfirm: () => void;
  onUse?: (id: string) => void;
  pending?: boolean;
}) {
  return (
    <section className={styles.notice} aria-labelledby="duplicate-title">
      <h3 id="duplicate-title">Instruments similaires</h3>
      <ul>
        {candidates.map((candidate) => (
          <li key={candidate.id}>
            {candidate.name} · {candidate.isin ?? 'sans ISIN'} · {candidate.ticker ?? 'sans ticker'}{' '}
            · {candidate.status === 'ACTIVE' ? 'Actif' : 'Archivé'}
            {onUse ? (
              <button
                aria-label={`Utiliser ${candidate.name}`}
                className="secondary-action"
                disabled={pending}
                type="button"
                onClick={() => onUse(candidate.id)}
              >
                {candidate.status === 'ACTIVE' ? 'Utiliser' : 'Réactiver et utiliser'}
              </button>
            ) : null}
          </li>
        ))}
      </ul>
      <ModalActions>
        <button className="secondary-action" disabled={pending} onClick={onCancel} type="button">
          Annuler
        </button>
        <button className="primary-action" disabled={pending} onClick={onConfirm} type="button">
          {editing ? 'Enregistrer quand même' : 'Créer quand même'}
        </button>
      </ModalActions>
    </section>
  );
}
