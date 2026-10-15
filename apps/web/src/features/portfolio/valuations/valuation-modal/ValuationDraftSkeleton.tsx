import { SkeletonBlock, SkeletonField, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './ValuationForm.module.css';

/**
 * Mirrors ValuationForm in its default "Valeur totale" mode element for
 * element: labels, mode choice and submit are real, every draft value pending.
 */
export function ValuationDraftSkeleton() {
  return (
    <div aria-busy="true" className={styles.form} data-testid="valuation-draft-skeleton">
      <SkeletonStatus>Chargement du brouillon de valorisation…</SkeletonStatus>
      <p className={styles.notice}>
        Espèces calculées : <SkeletonBlock className={styles.skeletonInline} />
      </p>
      <fieldset className={styles.segmented} disabled>
        <legend>Mode de saisie</legend>
        <label className={styles.segment}>
          <input name="valuation-detail-skeleton" type="radio" />
          Valeur totale
        </label>
        <label className={styles.segment}>
          <input name="valuation-detail-skeleton" type="radio" />
          Détail par ligne
        </label>
      </fieldset>
      <div className={styles.fields}>
        <SkeletonField label="Valeur totale du compte" />
        <SkeletonField label="Espèces constatées" />
      </div>
      <SkeletonField label="Note" />
      <div className={styles.actions}>
        <button className="primary-action" disabled type="button">
          Enregistrer
        </button>
      </div>
    </div>
  );
}
