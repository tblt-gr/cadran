import { SkeletonField, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './ValuationForm.module.css';

/** Step 2 while the draft loads: the form's real labels, every value pending. */
export function ValuationDraftSkeleton() {
  return (
    <div aria-busy="true" className={styles.form} data-testid="valuation-draft-skeleton">
      <SkeletonStatus>Chargement du brouillon de valorisation…</SkeletonStatus>
      <div className={styles.fields}>
        <SkeletonField label="Valeur totale du compte" />
        <SkeletonField label="Espèces constatées" />
        <SkeletonField label="Note" />
      </div>
    </div>
  );
}
