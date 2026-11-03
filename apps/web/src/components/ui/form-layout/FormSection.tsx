import type { ReactNode } from 'react';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { FormGrid } from './FormGrid';
import styles from './FormLayout.module.css';

interface FormSectionProps {
  children: ReactNode;
  /** Short state shown beside the title while the section is folded. */
  meta?: ReactNode;
  onToggle: (open: boolean) => void;
  open: boolean;
  title: ReactNode;
}

/** Secondary fields folded under a disclosure, set apart at the end of a `FormGrid`. */
export function FormSection({ children, meta, onToggle, open, title }: FormSectionProps) {
  return (
    <div className={styles.section} data-span="full">
      <Disclosure meta={meta} onToggle={onToggle} open={open} title={title}>
        <FormGrid>{children}</FormGrid>
      </Disclosure>
    </div>
  );
}
