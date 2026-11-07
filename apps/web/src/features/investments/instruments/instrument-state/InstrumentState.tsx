import type { ReactNode } from 'react';
import styles from './InstrumentState.module.css';

export function InstrumentState({
  title,
  error = false,
  onRetry,
  children,
}: {
  title: string;
  error?: boolean;
  onRetry?: () => void;
  children?: ReactNode;
}) {
  return (
    <section className={`card ${styles.state}`} role={error ? 'alert' : undefined}>
      <h3>{title}</h3>
      {error ? (
        <button className="secondary-action" onClick={onRetry} type="button">
          Réessayer
        </button>
      ) : (
        children
      )}
    </section>
  );
}
