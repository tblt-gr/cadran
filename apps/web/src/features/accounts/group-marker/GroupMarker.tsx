import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { ColorDot } from '@/components/ui/color-dot/ColorDot';
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
      {pending ? <SkeletonBlock className={styles.fill} /> : <ColorDot color={groupColor(color)} />}
    </span>
  );
}
