import type { ReactNode } from 'react';
import styles from './Explanation.module.css';

/** The figure being explained, first and large, under the modal title that names it: its value, then when and how sure. */
export function ExplanationFigure({ value, meta }: { value: ReactNode; meta?: ReactNode }) {
  return (
    <div className={styles.figure}>
      <p className={styles.figureValue}>{value}</p>
      {meta ? <div className={styles.figureMeta}>{meta}</div> : null}
    </div>
  );
}
