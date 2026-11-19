import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
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
  const { t } = useTranslation();
  return (
    <section className={`card ${styles.state}`} role={error ? 'alert' : undefined}>
      <h3>{title}</h3>
      {error ? (
        <button className="secondary-action" onClick={onRetry} type="button">
          {t('actions.retry')}
        </button>
      ) : (
        children
      )}
    </section>
  );
}
