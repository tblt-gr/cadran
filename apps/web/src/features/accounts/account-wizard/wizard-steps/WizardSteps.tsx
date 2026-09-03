import { useTranslation } from 'react-i18next';
import styles from './WizardSteps.module.css';

export type WizardStep = 'product' | 'details' | 'review';

const STEPS: WizardStep[] = ['product', 'details', 'review'];

interface WizardStepsProps {
  current: WizardStep;
}

/**
 * Where the creation stands, announced rather than only drawn: the position is
 * carried by `aria-current` and by a text label, so it never depends on the
 * colour of a marker.
 */
export function WizardSteps({ current }: WizardStepsProps) {
  const { t } = useTranslation();
  const position = STEPS.indexOf(current) + 1;

  return (
    <nav aria-label={t('accounts.wizard.steps.label')}>
      <ol className={styles.steps}>
        {STEPS.map((step, index) => (
          <li
            aria-current={step === current ? 'step' : undefined}
            className={index < position - 1 ? styles.done : undefined}
            key={step}
          >
            <span className={styles.rank}>{index + 1}</span>
            {t(`accounts.wizard.steps.${step}`)}
          </li>
        ))}
      </ol>
      {/* Announced, not only drawn: a step change replaces the dialog body, and
          the marker treatment alone would tell a screen reader nothing. */}
      <p className={styles.position} role="status">
        {t('accounts.wizard.steps.position', { position, total: STEPS.length })}
      </p>
    </nav>
  );
}
