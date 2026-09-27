import type { ButtonHTMLAttributes } from 'react';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './InfoButton.module.css';

interface InfoButtonProps extends Omit<
  ButtonHTMLAttributes<HTMLButtonElement>,
  'aria-label' | 'children' | 'title' | 'type'
> {
  /** Accessible name and tooltip, for instance "Expliquer : Revenus". */
  label: string;
}

/** Circular "i" button placed to the right of the value or text it explains. */
export function InfoButton({ className, label, ...rest }: InfoButtonProps) {
  return (
    <button
      {...rest}
      aria-label={label}
      className={
        className ? `icon-button ${styles.button} ${className}` : `icon-button ${styles.button}`
      }
      title={label}
      type="button"
    >
      <Icon name="info" size={14} />
    </button>
  );
}
