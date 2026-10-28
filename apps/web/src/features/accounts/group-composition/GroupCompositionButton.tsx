import { Suspense, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { lazyComponent } from '@/lib/lazyComponent';
import styles from './GroupComposition.module.css';

// Keeps the donut and its modal out of the accounts route chunk until a button is pressed.
const GroupCompositionModal = lazyComponent(
  () => import('./GroupCompositionModal'),
  'GroupCompositionModal',
);

interface GroupCompositionButtonProps {
  asOf: string;
  groupId: string;
  label: string;
}

/** Circular pie button opening the composition of one parent group; focus returns to it. */
export function GroupCompositionButton({ asOf, groupId, label }: GroupCompositionButtonProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const button = useRef<HTMLButtonElement>(null);
  const name = t('accounts.composition.open', { label });

  return (
    <>
      <button
        aria-label={name}
        className={`icon-ghost ${styles.pie}`}
        onClick={() => setOpen(true)}
        ref={button}
        title={name}
        type="button"
      >
        <Icon name="pie" size={16} />
      </button>
      {open ? (
        <Suspense fallback={null}>
          <GroupCompositionModal
            asOf={asOf}
            close={() => setOpen(false)}
            groupId={groupId}
            label={label}
            returnFocus={button}
          />
        </Suspense>
      ) : null}
    </>
  );
}
