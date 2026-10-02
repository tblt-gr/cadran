import { SkeletonButton, SkeletonField, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';

export function AccountEditorSkeleton({ status }: { status: string }) {
  return (
    <section aria-busy="true" data-testid="account-editor-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <SkeletonField />
      <SkeletonField />
      <SkeletonField />
      <SkeletonField />
      <SkeletonButton />
    </section>
  );
}
