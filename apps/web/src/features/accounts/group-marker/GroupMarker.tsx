import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { Icon } from '@/components/ui/icon/Icon';
import { ColorDot } from '@/components/ui/color-dot/ColorDot';
import { groupColor } from '@/features/accounts/group-color';
import styles from './GroupMarker.module.css';

function tileStyle(color: string) {
  return { color, backgroundColor: `color-mix(in srgb, ${color} 16%, transparent)` };
}

export function GroupMarker({
  color,
  pending = false,
  tile = false,
}: {
  color: string | null | undefined;
  pending?: boolean;
  /** A tinted tile holding the group icon, for a heading: the colour is the tile's tint. */
  tile?: boolean;
}) {
  if (tile) {
    return (
      <span
        aria-hidden="true"
        className={pending ? styles.tileSkeleton : styles.tile}
        data-group-marker
        style={pending ? undefined : tileStyle(groupColor(color))}
      >
        {pending ? <SkeletonBlock className={styles.tileFill} /> : <Icon name="folder" size={14} />}
      </span>
    );
  }

  return (
    <span aria-hidden="true" className={styles.marker} data-group-marker>
      {pending ? <SkeletonBlock className={styles.fill} /> : <ColorDot color={groupColor(color)} />}
    </span>
  );
}
