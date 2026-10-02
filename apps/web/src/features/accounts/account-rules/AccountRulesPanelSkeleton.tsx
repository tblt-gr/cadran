import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';

export function AccountRulesPanelSkeleton({ status }: { status: string }) {
  return (
    <section aria-busy="true" className="card" data-testid="account-rules-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <table>
        <SkeletonTableRows columns={3} rows={3} />
      </table>
    </section>
  );
}
