import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { ValuationCashHint } from './ValuationCashHint';
import { FormField } from './FormField';
import styles from './ValuationForm.module.css';

/**
 * Mirrors ValuationForm in its default "Valeur totale" mode element for
 * element: labels, mode choice and submit are real, every draft value pending.
 */
export function ValuationDraftSkeleton({ loading = true }: { loading?: boolean }) {
  return (
    <ModalForm
      aria-busy={loading || undefined}
      className={styles.form}
      onSubmit={(event) => event.preventDefault()}
      data-testid="valuation-draft-skeleton"
    >
      {loading ? <SkeletonStatus>Chargement du brouillon de valorisation…</SkeletonStatus> : null}
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
      <FormGrid>
        <FormField label="Valeur totale du compte">
          {() => <SkeletonBlock className={styles.skeletonControl} />}
        </FormField>
        <FormField label="Espèces du relevé" hint={<ValuationCashHint />}>
          {() => <SkeletonBlock className={styles.skeletonControl} />}
        </FormField>
        <FormSection title="Champs avancés" open={false} onToggle={() => undefined}>
          <FormField label="Note" wide>
            {() => <SkeletonBlock className={styles.skeletonControl} />}
          </FormField>
        </FormSection>
      </FormGrid>
      <ModalActions>
        <button className="primary-action" disabled type="button">
          Enregistrer
        </button>
      </ModalActions>
    </ModalForm>
  );
}
