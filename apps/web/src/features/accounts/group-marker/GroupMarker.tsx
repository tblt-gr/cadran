import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { groupColor } from '@/features/accounts/group-color';
import styles from './GroupMarker.module.css';

export function GroupMarker({
  color,
  pending = false,
}: {
  color: string | null | undefined;
  pending?: boolean;
}) {
  return (
    <span aria-hidden="true" className={styles.marker} data-group-marker>
      {pending ? (
        <SkeletonBlock className={styles.fill} />
      ) : (
        <svg className={styles.fill} viewBox="0 0 4 20">
          <rect
            fill={groupColor(color)}
            height="18"
            rx="1"
            stroke="var(--border-strong)"
            width="3"
            x="0.5"
            y="1"
          />
        </svg>
      )}
    </span>
  );
}
