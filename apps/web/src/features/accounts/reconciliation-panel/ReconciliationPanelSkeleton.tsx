import {
  SkeletonKpiCard,
  SkeletonStatus,
  SkeletonTableRows,
} from '@/components/ui/skeleton/Skeleton';

export function ReconciliationPanelSkeleton({ status }: { status: string }) {
  return (
    <section aria-busy="true" className="card" data-testid="reconciliation-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div>
        <SkeletonKpiCard />
        <SkeletonKpiCard />
      </div>
      <table>
        <SkeletonTableRows columns={3} rows={3} />
      </table>
    </section>
  );
}
