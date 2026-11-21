import { useTranslation } from 'react-i18next';
import { AMOUNT_MASK } from '@/lib/amountPrivacy';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import styles from './MoneyValue.module.css';

interface MoneyValueProps {
  className?: string;
  value: string | null;
  /** Keep existing single-line readouts intact when migrating their privacy announcement. */
  wrapGroups?: boolean;
  skeletonClassName?: string;
}

export function MoneyValue({
  className,
  value,
  skeletonClassName,
  wrapGroups = true,
}: MoneyValueProps) {
  const { t } = useTranslation();
  if (value === null) {
    return (
      <span className={className} data-visual-mask>
        <span className={styles.tail}>
          <SkeletonBlock className={skeletonClassName} />
        </span>
      </span>
    );
  }
  // Assistive technologies get a sentence, never a run of bullet characters.
  if (value.includes(AMOUNT_MASK)) {
    return (
      <span className={className} data-visual-mask>
        <span aria-hidden="true" className={styles.tail}>
          {AMOUNT_MASK}
        </span>
        <span className="sr-only">{t('privacy.hidden')}</span>
      </span>
    );
  }
  const groups = wrapGroups ? value.split('\u202f') : [value];

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
