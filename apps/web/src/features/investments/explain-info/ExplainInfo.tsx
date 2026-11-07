import { useRef, useState } from 'react';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { Modal } from '@/components/ui/modal/Modal';
import styles from './ExplainInfo.module.css';

/** The "i" of a figure: it opens the shared modal with the explanation, never an inline popover. */
export function ExplainInfo({
  buttonLabel,
  title,
  text,
  className,
}: {
  /** Accessible name of the button, specific to the figure it explains. */
  buttonLabel: string;
  /** Modal title: the figure label, with the account label in a row. */
  title: string;
  text: string;
  className?: string;
}) {
  const [open, setOpen] = useState(false);
  const button = useRef<HTMLButtonElement>(null);

  return (
    <>
      <InfoButton
        className={className}
        label={buttonLabel}
        onClick={() => setOpen(true)}
        ref={button}
      />
      {open ? (
        <Modal close={() => setOpen(false)} intent="detail" returnFocus={button} title={title}>
          <p className={styles.text}>{text}</p>
        </Modal>
      ) : null}
    </>
  );
}
