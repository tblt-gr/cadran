import { useLayoutEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Icon, type IconName } from '@/components/ui/icon/Icon';
import styles from './ActionMenu.module.css';

interface ActionMenuItem {
  disabled?: boolean;
  icon: IconName;
  id: string;
  label: string;
  onSelect: () => void;
  text: string;
}

interface ActionMenuProps {
  /** Takes focus on mount: the caller's way to return focus here after a modal. */
  autoFocus?: boolean;
  items: ActionMenuItem[];
  label: string;
  disabled?: boolean;
}

/**
 * Compact row actions. The trigger is a kebab; each item keeps the same
 * accessible name the former text buttons used, so a screen reader still hears
 * which row is about to change.
 */
export function ActionMenu({ autoFocus = false, items, label, disabled = false }: ActionMenuProps) {
  const menuId = useId();
  const trigger = useRef<HTMLButtonElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  const [coords, setCoords] = useState({ top: 0, right: 0 });

  function place() {
    const rect = trigger.current?.getBoundingClientRect();
    const panel = menu.current;
    if (rect === undefined || panel === null) {
      return;
    }

    const { width, height } = panel.getBoundingClientRect();
    const gap = Number.parseFloat(window.getComputedStyle(panel).paddingTop) || 0;
    const viewportWidth = document.documentElement.clientWidth;
    const viewportHeight = window.innerHeight;
    // A fixed portal cannot be scrolled into view: keep every action inside the viewport.
    const below = rect.bottom + gap;
    const top = below + height <= viewportHeight - gap ? below : rect.top - height - gap;
    setCoords({
      right: Math.max(gap, Math.min(viewportWidth - rect.right, viewportWidth - width - gap)),
      top: Math.max(gap, Math.min(top, viewportHeight - height - gap)),
    });
  }

  useLayoutEffect(() => {
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

    // Captured on the window and stopped there: inside a modal, the same Escape
    // must close the menu and not the dialog behind it.
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.stopPropagation();
        setOpen(false);
        trigger.current?.focus();

        return;
      }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        const buttons = [
          ...(menu.current?.querySelectorAll<HTMLButtonElement>('button:not(:disabled)') ?? []),
        ];
        if (buttons.length === 0) {
          return;
        }
        event.preventDefault();
        const index = buttons.indexOf(document.activeElement as HTMLButtonElement);
        const step = event.key === 'ArrowDown' ? 1 : -1;
        buttons[(index + step + buttons.length) % buttons.length]?.focus();
      }
    }

    function onReposition() {
      place();
    }

    document.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('keydown', onKeyDown, true);
    window.addEventListener('resize', onReposition);
    window.addEventListener('scroll', onReposition, true);

    return () => {
      document.removeEventListener('pointerdown', onPointerDown);
      window.removeEventListener('keydown', onKeyDown, true);
      window.removeEventListener('resize', onReposition);
      window.removeEventListener('scroll', onReposition, true);
    };
  }, [open]);

  return (
    <div className={styles.wrap}>
      <button
        disabled={disabled}
        aria-controls={open ? menuId : undefined}
        aria-expanded={open}
        aria-haspopup="true"
        aria-label={label}
        className={`icon-ghost ${styles.trigger}`}
        data-autofocus={autoFocus || undefined}
        onClick={() => setOpen((current) => !current)}
        ref={trigger}
        type="button"
      >
        <Icon name="more" size={20} />
      </button>
      {open
        ? createPortal(
            <div
              className={styles.menu}
              id={menuId}
              ref={menu}
              role="presentation"
              style={{
                top: `${coords.top}px`,
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
                    trigger.current?.focus();
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
