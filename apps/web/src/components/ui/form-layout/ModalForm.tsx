import { Children, isValidElement, type ComponentPropsWithoutRef } from 'react';
import { ModalActions } from './ModalActions';
import styles from './FormLayout.module.css';

/** The fields scroll independently of the footer, while native form ownership remains intact. */
export function ModalForm({ children, className, ...props }: ComponentPropsWithoutRef<'form'>) {
  const parts = Children.toArray(children);
  const isActions = (part: (typeof parts)[number]) =>
    isValidElement(part) && part.type === ModalActions;

  return (
    <form
      {...props}
      className={className ? `${styles.form} ${className}` : styles.form}
      data-modal-form
    >
      <div className={styles.content} data-form-content>
        {parts.filter((part) => !isActions(part))}
      </div>
      {parts.filter(isActions)}
    </form>
  );
}
