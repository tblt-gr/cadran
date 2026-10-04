import { Icon } from '@/components/ui/icon/Icon';
import styles from './YearNavigator.module.css';

/** Previous and next year around the year label; both are disabled at the bounds or while unknown. */
export function YearNavigator({
  year,
  bounds,
  onChange,
}: {
  year: number;
  /** `null` while the bounds are unknown: both buttons stay disabled. */
  bounds: { from: number; to: number } | null;
  onChange: (year: number) => void;
}) {
  return (
    <div className={styles.navigator}>
      <button
        aria-label="Année précédente"
        className="icon-button"
        disabled={!bounds || year <= bounds.from}
        onClick={() => onChange(year - 1)}
        type="button"
      >
        <Icon name="chevron-left" />
      </button>
      <h2 className={styles.year}>{year}</h2>
      <button
        aria-label="Année suivante"
        className="icon-button"
        disabled={!bounds || year >= bounds.to}
        onClick={() => onChange(year + 1)}
        type="button"
      >
        <Icon name="chevron-right" />
      </button>
    </div>
  );
}
