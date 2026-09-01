import { useEffect, useRef } from 'react';

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Shared behaviour of every modal surface: the rest of the application becomes inert, the document
 * stops scrolling behind the overlay, focus is trapped inside it and Escape closes it. The close
 * callback is read through a ref so a re-render of the caller never steals focus back.
 */
export function useDialogOverlay(close: () => void) {
  const container = useRef<HTMLElement>(null);
  const closeRef = useRef(close);

  useEffect(() => {
    closeRef.current = close;
  });

  useEffect(() => {
    const appShell = document.querySelector<HTMLElement>('[data-app-shell]');
    const previousOverflow = document.body.style.overflow;
    appShell?.setAttribute('inert', '');
    document.body.style.overflow = 'hidden';

    const focusFrame = window.requestAnimationFrame(() => {
      const preferred = container.current?.querySelector<HTMLElement>('[data-autofocus]');
      (preferred ?? container.current?.querySelector<HTMLElement>(FOCUSABLE_SELECTOR))?.focus();
    });

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') closeRef.current();
      if (event.key !== 'Tab') return;

      const focusableElements =
        container.current?.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR);
      if (!focusableElements?.length) return;

      const first = focusableElements[0];
      const last = focusableElements[focusableElements.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      window.cancelAnimationFrame(focusFrame);
      document.removeEventListener('keydown', handleKeyDown);
      appShell?.removeAttribute('inert');
      document.body.style.overflow = previousOverflow;
    };
  }, []);

  return container;
}
