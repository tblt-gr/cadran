import type { ReactNode } from 'react';
import styles from './OperationsTab.module.css';

/** Empty, error and refusal states of the operations tab share this card. */
export function OperationsState({
  children,
  error = false,
  title,
}: {
  children?: ReactNode;
  error?: boolean;
  title: string;
}) {
  return (
    <section className={`card ${styles.state}`} role={error ? 'alert' : undefined}>
      <h3>{title}</h3>
      {children}
    </section>
  );
}
