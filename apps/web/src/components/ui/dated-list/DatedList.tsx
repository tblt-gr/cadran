import { Fragment } from 'react';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import type { DatedListLayout, DatedRowSelection, RenderDatedRow } from './datedListTypes';
import styles from './DatedList.module.css';
import { VirtualDatedDays } from './VirtualDatedDays';

interface DatedListProps<T> extends Omit<DatedListLayout<T>, 'headingLevel' | 'renderRow'> {
  /** Announced once while loading, unless `announce` is false. */
  loadingLabel: string;
  announce?: boolean;
  headingLevel?: 2 | 3 | 4;
  /** Accessible names of the two keyboard controls that reveal rows left out of the window. */
  nextLoadedLabel: string;
  previousLoadedLabel: string;
  /** Items, or null while loading: the list then mirrors its loaded markup with placeholder rows. */
  items: readonly T[] | null;
  renderRow: RenderDatedRow<T>;
  /** Row counts of the placeholder days. */
  skeletonDays?: readonly number[];
  skeletonTestId?: string;
}

/** Three, three and two rows: enough to fill a screen without inventing data. */
const SKELETON_DAYS = [3, 3, 2];

/**
 * Day headings, one card per day, and the rows the caller renders: the shared reading of the
 * transaction register. Generic and display only; the caller owns the row, its dialog and its data.
 */
export function DatedList<T>({
  announce = true,
  dayLabel,
  getDay,
  getKey,
  headingLevel = 3,
  items,
  label,
  loadingLabel,
  nextLoadedLabel,
  previousLoadedLabel,
  renderRow,
  selection,
  skeletonDays = SKELETON_DAYS,
  skeletonTestId = 'dated-list-skeleton',
}: DatedListProps<T>) {
  if (items !== null)
    return (
      <VirtualDatedDays
        dayLabel={dayLabel}
        getDay={getDay}
        getKey={getKey}
        headingLevel={headingLevel}
        items={items}
        label={label}
        nextLoadedLabel={nextLoadedLabel}
        previousLoadedLabel={previousLoadedLabel}
        renderRow={renderRow}
        selection={selection}
      />
    );

  const Heading = `h${headingLevel}` as const;
  const placeholder: DatedRowSelection | undefined = selection
    ? { disabled: true, selected: false, toggle: () => {} }
    : undefined;

  return (
    <div aria-busy className={styles.panel} data-testid={skeletonTestId}>
      {announce ? <SkeletonStatus>{loadingLabel}</SkeletonStatus> : null}
      <ol aria-label={label} className={styles.days}>
        {skeletonDays.map((size, index) => (
          <li className={styles.day} key={index}>
            <Heading className={styles.dayTitle}>
              <time className={styles.date}>
                <SkeletonBlock />
              </time>
            </Heading>
            <div className={`card ${styles.dayCard}`}>
              <ul className={styles.rows}>
                {Array.from({ length: size }, (_, rowIndex) => (
                  <Fragment key={rowIndex}>{renderRow(null, { selection: placeholder })}</Fragment>
                ))}
              </ul>
            </div>
          </li>
        ))}
      </ol>
    </div>
  );
}
