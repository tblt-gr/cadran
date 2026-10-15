import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './InstrumentPicker.module.css';

/** Mirrors the result listbox: the same floating list, its options pending. */
export function InstrumentPickerSkeleton({ status }: { status: string }) {
  return (
    <div aria-busy="true" data-testid="instrument-picker-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <ul aria-hidden="true" className={styles.options}>
        {Array.from({ length: 3 }, (_, index) => (
          <li key={index}>
            <SkeletonBlock className={styles.skeletonOption} />
          </li>
        ))}
      </ul>
    </div>
  );
}
