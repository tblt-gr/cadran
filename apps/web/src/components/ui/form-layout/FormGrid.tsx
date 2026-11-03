import type { ReactNode } from 'react';
import styles from './FormLayout.module.css';

interface FormGridProps {
  children: ReactNode;
  className?: string;
}

/**
 * Two equal columns, one under 42rem. A direct child with `data-span="full"` spans both columns.
 */
export function FormGrid({ children, className }: FormGridProps) {
  return <div className={className ? `${styles.grid} ${className}` : styles.grid}>{children}</div>;
}
