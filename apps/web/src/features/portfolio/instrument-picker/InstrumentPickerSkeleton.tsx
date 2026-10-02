import { SkeletonStatus, SkeletonText } from '@/components/ui/skeleton/Skeleton';

export function InstrumentPickerSkeleton({ status }: { status: string }) {
  return (
    <div aria-busy="true" data-testid="instrument-picker-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <SkeletonText lines={3} />
    </div>
  );
}
