import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './FoundationStates.module.css';

interface FoundationErrorStateProps {
  /** Overrides the default API unavailable copy. */
  copy?: { description: string; retryLabel: string; title: string };
  retry: () => void;
}

export function FoundationErrorState({ copy, retry }: FoundationErrorStateProps) {
  const { t } = useTranslation();

  return (
    <section className={`card ${styles.state}`} aria-labelledby="error-title">
      <div className={`${styles.mark} ${styles.errorMark}`}>
        <Icon name="alert" size={24} />
      </div>
      <div role="alert">
        <h2 id="error-title">{copy?.title ?? t('states.error.title')}</h2>
        <p>{copy?.description ?? t('states.error.description')}</p>
      </div>
      <button className="secondary-action" onClick={retry} type="button">
        {copy?.retryLabel ?? t('foundation.retry')}
      </button>
    </section>
  );
}
