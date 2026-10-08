import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { useLifeInsuranceSummary } from '@/features/portfolio/life-insurance/useLifeInsuranceSummary';
import { TaxTrackingCard } from './TaxTrackingCard';
export function TaxTrackingPanel({ accountId, asOf }: { accountId: string; asOf: string }) {
  const query = useLifeInsuranceSummary(accountId, asOf);
  return (
    <>
      {query.isPending ? <SkeletonStatus>Chargement du suivi fiscal…</SkeletonStatus> : null}
      {query.isError ? (
        <section className="card" role="alert">
          <h3>Le suivi fiscal est indisponible</h3>
          <p>
            {query.data
              ? 'Les dernières données restent affichées.'
              : 'Réessayez pour lire les montants enregistrés.'}
          </p>
          <button className="secondary-action" type="button" onClick={() => void query.refetch()}>
            Réessayer
          </button>
        </section>
      ) : null}
      {query.isPending || query.data ? <TaxTrackingCard summary={query.data ?? null} /> : null}
    </>
  );
}
