import type { Instrument } from '@cadran/api-client';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';

/** The same notice holds its queried name while the scoped instrument read is pending. */
export function ExistingInstrumentChoice({
  instrument,
  pending,
  failed,
  compatible,
  onUse,
  onRetry,
}: {
  instrument: Instrument | null;
  pending: boolean;
  failed: boolean;
  compatible: boolean;
  onUse: () => void;
  onRetry: () => void;
}) {
  return (
    <>
      {pending && !instrument ? (
        <SkeletonStatus>Recherche de l’instrument existant</SkeletonStatus>
      ) : null}
      <p>
        Cet ISIN existe déjà :{' '}
        <strong>{instrument?.name ?? (failed ? 'Indisponible' : <SkeletonBlock />)}</strong>.
      </p>
      {failed ? (
        <p>
          L’instrument est indisponible.{' '}
          <button className="secondary-action" type="button" onClick={onRetry}>
            Réessayer
          </button>
        </p>
      ) : null}
      {instrument && !compatible ? (
        <p>Cet instrument ne correspond pas à la devise ou au type du compte.</p>
      ) : null}
      <button
        className="primary-action"
        disabled={pending || !instrument || !compatible}
        type="button"
        onClick={onUse}
      >
        {instrument?.status === 'INACTIVE' ? 'Réactiver et utiliser' : 'Utiliser cet instrument'}
      </button>
    </>
  );
}
