import type { InstrumentCandidate } from '@cadran/api-client';
import styles from './DuplicateNotice.module.css';
export function DuplicateNotice({
  candidates,
  editing = false,
  onCancel,
  onConfirm,
}: {
  candidates: InstrumentCandidate[];
  editing?: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  return (
    <section className={styles.notice} aria-labelledby="duplicate-title">
      <h3 id="duplicate-title">Instruments similaires</h3>
      <ul>
        {candidates.map((candidate) => (
          <li key={candidate.id}>
            {candidate.name} · {candidate.isin ?? 'sans ISIN'} · {candidate.ticker ?? 'sans ticker'}{' '}
            · {candidate.status === 'ACTIVE' ? 'Actif' : 'Inactif'}
          </li>
        ))}
      </ul>
      <div className={styles.actions}>
        <button className="secondary-action" onClick={onCancel} type="button">
          Annuler
        </button>
        <button className="primary-action" onClick={onConfirm} type="button">
          {editing ? 'Enregistrer quand même' : 'Créer quand même'}
        </button>
      </div>
    </section>
  );
}
