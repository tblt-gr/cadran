import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import styles from './MoneyValue.module.css';

interface MoneyValueProps {
  className?: string;
  value: string | null;
  skeletonClassName?: string;
}

export function MoneyValue({ className, value, skeletonClassName }: MoneyValueProps) {
  if (value === null) {
    return (
      <span className={className} data-visual-mask>
        <span className={styles.tail}>
          <SkeletonBlock className={skeletonClassName} />
        </span>
      </span>
    );
  }
  const groups = value.split('\u202f');

  return (
    <span className={className} data-visual-mask>
      {groups.map((group, index) =>
        index === groups.length - 1 ? (
          <span className={styles.tail} key={group}>
            {group}
          </span>
        ) : (
          <span key={`${group}-${index}`}>
            {group}
            {'\u202f'}
            <wbr />
          </span>
        ),
      )}
    </span>
  );
}
