import { useEffect, useRef, useSyncExternalStore } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { areAmountsHidden, setAmountsHidden, subscribeAmountPrivacy } from '@/lib/amountPrivacy';

// A toggle remounts the page, so the new button takes the focus back for keyboard users.
let restoreFocus = false;

/** Eye button that hides or shows every amount of the application. */
export function AmountVisibilityToggle({
  className,
  disabled,
}: {
  className?: string;
  disabled?: boolean;
}) {
  const { t } = useTranslation();
  const hidden = useSyncExternalStore(subscribeAmountPrivacy, areAmountsHidden);
  const ref = useRef<HTMLButtonElement>(null);
  const label = t(hidden ? 'privacy.show' : 'privacy.hide');

  useEffect(() => {
    if (restoreFocus) {
      restoreFocus = false;
      ref.current?.focus();
    }
  }, []);

  return (
    <button
      aria-label={label}
      aria-pressed={hidden}
      disabled={disabled}
      className={className ? `icon-button ${className}` : 'icon-button'}
      onClick={() => {
        restoreFocus = true;
        setAmountsHidden(!areAmountsHidden());
      }}
      ref={ref}
      title={label}
      type="button"
    >
      <Icon name={hidden ? 'eye-off' : 'eye'} size={16} />
    </button>
  );
}
