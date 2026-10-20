import { useTranslation } from 'react-i18next';
import styles from './AccountSheet.module.css';

interface OverrideActionProps {
  /** Loading placeholder: the action keeps its place and is inert. */
  disabled?: boolean;
  /** Takes focus on mount, when the reader comes back from a modal opened from this line. */
  focusAction?: boolean;
  /** The rule's name, for the accessible name "Déroger : <règle>". */
  name?: string;
  onClick?: () => void;
}

/** The small text button that opens the override modal for one rule. */
export function OverrideAction({
  disabled = false,
  focusAction,
  name,
  onClick,
}: OverrideActionProps) {
  const { t } = useTranslation();

  return (
    <button
      aria-label={name ? t('accounts.rules.overrideRule', { rule: name }) : undefined}
      className={`secondary-action ${styles.action}`}
      data-autofocus={focusAction || undefined}
      disabled={disabled}
      onClick={onClick}
      type="button"
    >
      {t('accounts.rules.override')}
    </button>
  );
}
