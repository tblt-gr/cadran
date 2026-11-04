import { useId, type ReactNode } from 'react';
import styles from './FilterSection.module.css';

interface FilterSectionProps {
  children: ReactNode;
  title: string;
}

/** One titled group of related filters inside the panel, laid out on its own grid. */
export function FilterSection({ children, title }: FilterSectionProps) {
  const titleId = useId();

  return (
    <section aria-labelledby={titleId} className={styles.section}>
      <h3 className={styles.title} id={titleId}>
        {title}
      </h3>
      <div className={styles.grid}>{children}</div>
    </section>
  );
}
