import { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Icon, type IconName } from '@/components/ui/icon/Icon';
import styles from './ActionMenu.module.css';

export interface ActionMenuItem {
  disabled?: boolean;
  icon: IconName;
  id: string;
  label: string;
  onSelect: () => void;
  text: string;
}

interface ActionMenuProps {
  items: ActionMenuItem[];
  label: string;
}

/**
 * Compact row actions. The trigger is a kebab; each item keeps the same
 * accessible name the former text buttons used, so a screen reader still hears
 * which row is about to change.
 */
export function ActionMenu({ items, label }: ActionMenuProps) {
  const menuId = useId();
  const trigger = useRef<HTMLButtonElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  const [coords, setCoords] = useState({ top: 0, right: 0 });

  function place() {
    const rect = trigger.current?.getBoundingClientRect();
    if (rect === undefined) {
      return;
    }

    setCoords({
      right: window.innerWidth - rect.right,
      top: rect.bottom,
    });
  }

  useEffect(() => {
    if (!open) {
      return;
    }

    place();

    function onPointerDown(event: PointerEvent) {
      const target = event.target;
      if (!(target instanceof Node)) {
        return;
      }

      if (trigger.current?.contains(target) || menu.current?.contains(target)) {
        return;
      }

      setOpen(false);
    }

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false);
        trigger.current?.focus();
      }
    }

    function onReposition() {
      place();
    }

    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    window.addEventListener('resize', onReposition);
    window.addEventListener('scroll', onReposition, true);

    return () => {
      document.removeEventListener('pointerdown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
      window.removeEventListener('resize', onReposition);
      window.removeEventListener('scroll', onReposition, true);
    };
  }, [open]);

  return (
    <div className={styles.wrap}>
      <button
        aria-controls={open ? menuId : undefined}
        aria-expanded={open}
        aria-haspopup="true"
        aria-label={label}
        className={`icon-button ${styles.trigger}`}
        onClick={() => setOpen((current) => !current)}
        ref={trigger}
        type="button"
      >
        <Icon name="more" size={16} />
      </button>
      {open
        ? createPortal(
            <div
              className={styles.menu}
              id={menuId}
              ref={menu}
              role="presentation"
              style={{
                top: `calc(${coords.top}px + var(--spacing-1))`,
                right: `${coords.right}px`,
              }}
            >
              {items.map((item) => (
                <button
                  aria-label={item.label}
                  className={styles.item}
                  disabled={item.disabled}
                  key={item.id}
                  onClick={() => {
                    item.onSelect();
                    setOpen(false);
                  }}
                  type="button"
                >
                  <Icon name={item.icon} size={16} />
                  <span>{item.text}</span>
                </button>
              ))}
            </div>,
            document.body,
          )
        : null}
    </div>
  );
}
