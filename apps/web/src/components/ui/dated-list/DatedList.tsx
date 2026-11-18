import { Fragment, Suspense, use, type ReactNode } from 'react';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import type { DatedListLayout, DatedRowSelection, RenderDatedRow } from './datedListTypes';
import styles from './DatedList.module.css';
import type { VirtualDatedDaysProps } from './VirtualDatedDays';

type Windowing = <T>(props: VirtualDatedDaysProps<T>) => ReactNode;

/**
 * The windowing and its virtualizer load on demand, so a screen showing no list never pays for
 * them. The request starts when this module loads, long before the first rows arrive: once it is
 * done the list renders at once, and the placeholder rows are only a fallback for a slow load.
 */
let windowing: Windowing | null = null;
const loadingWindowing = import('./VirtualDatedDays').then((module) => {
  windowing = module.VirtualDatedDays;
});
// A failed load reaches the error boundary through `use`; this only keeps it from going unhandled.
loadingWindowing.catch(() => undefined);

function LoadedWindowing<T>(props: VirtualDatedDaysProps<T>) {
  if (windowing === null) use(loadingWindowing);
  const Days = windowing as Windowing;
  return <Days {...props} />;
}

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
  const skeleton = (
    <DatedListSkeleton
      announce={announce}
      headingLevel={headingLevel}
      label={label}
      loadingLabel={loadingLabel}
      renderRow={renderRow}
      selection={selection}
      skeletonDays={skeletonDays}
      skeletonTestId={skeletonTestId}
    />
  );
  if (items === null) return skeleton;

  return (
    <Suspense fallback={skeleton}>
      <LoadedWindowing
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
    </Suspense>
  );
}

interface DatedListSkeletonProps<T> {
  announce: boolean;
  headingLevel: 2 | 3 | 4;
  label: string;
  loadingLabel: string;
  renderRow: RenderDatedRow<T>;
  selection: DatedListLayout<T>['selection'];
  skeletonDays: readonly number[];
  skeletonTestId: string;
}

/** The final markup with placeholder rows: the loading list and the fallback of the windowed one. */
function DatedListSkeleton<T>({
  announce,
  headingLevel,
  label,
  loadingLabel,
  renderRow,
  selection,
  skeletonDays,
  skeletonTestId,
}: DatedListSkeletonProps<T>) {
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
