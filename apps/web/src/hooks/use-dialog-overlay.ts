import { useEffect, useRef, type RefObject } from 'react';

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

function focusableElements(container: HTMLElement | null): HTMLElement[] {
  return Array.from(container?.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR) ?? []).filter(
    (element) => {
      if (
        element.matches(':disabled, [aria-disabled="true"], [tabindex="-1"], input[type="hidden"]')
      )
        return false;
      for (
        let ancestor: HTMLElement | null = element;
        ancestor !== null;
        ancestor = ancestor.parentElement
      ) {
        if (ancestor.hidden || ancestor.hasAttribute('inert')) return false;
        const style = window.getComputedStyle(ancestor);
        if (
          style.display === 'none' ||
          style.visibility === 'hidden' ||
          style.visibility === 'collapse'
        )
          return false;
        // A closed disclosure keeps only its own summary in the keyboard order, including when
        // another disclosure is nested in its hidden body.
        if (
          ancestor.tagName === 'DETAILS' &&
          !ancestor.hasAttribute('open') &&
          !ancestor.querySelector(':scope > summary')?.contains(element)
        )
          return false;
      }
      return true;
    },
  );
}

interface DialogLayer {
  close: RefObject<() => void>;
  container: RefObject<HTMLElement | null>;
  returnFocus: HTMLElement | null;
}

const layers: DialogLayer[] = [];
let rootState: {
  appShell: HTMLElement | null;
  appShellWasInert: boolean;
  bodyOverflow: string;
} | null = null;

function topLayer(): DialogLayer | undefined {
  return layers[layers.length - 1];
}

function syncLayers() {
  const top = topLayer();
  for (const layer of layers) {
    layer.container.current?.toggleAttribute('inert', layer !== top);
  }
}

function restoreLayerFocus(layer: DialogLayer) {
  const target = layer.returnFocus;
  const topContainer = topLayer()?.container.current;
  window.requestAnimationFrame(() => {
    if (target?.isConnected && (topContainer == null || topContainer.contains(target))) {
      target.focus();
    }
  });
}

function handleKeyDown(event: KeyboardEvent) {
  const layer = topLayer();
  if (!layer) return;

  if (event.key === 'Escape') {
    layer.close.current();
    return;
  }
  if (event.key !== 'Tab') return;

  const focusable = focusableElements(layer.container.current);
  if (!focusable.length) return;

  const first = focusable[0];
  const last = focusable[focusable.length - 1];

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

/**
 * Shared behaviour of every modal surface: the rest of the application becomes inert, the document
 * stops scrolling behind the overlay, focus is trapped inside it and Escape closes it. Nested
 * dialogs stack: only the top one is active, and closing it returns focus to `returnFocus`, or to
 * the element focused when it opened. Both arguments are read through refs so a re-render of the
 * caller never re-opens the layer or steals focus back.
 */
export function useDialogOverlay(close: () => void, returnFocus?: RefObject<HTMLElement | null>) {
  const container = useRef<HTMLElement>(null);
  const closeRef = useRef(close);
  const returnFocusRef = useRef(returnFocus);

  useEffect(() => {
    closeRef.current = close;
  });

  useEffect(() => {
    const layerContainer = container.current;
    const layer: DialogLayer = {
      close: closeRef,
      container,
      returnFocus:
        returnFocusRef.current?.current ??
        (document.activeElement instanceof HTMLElement ? document.activeElement : null),
    };

    if (layers.length === 0) {
      const appShell = document.querySelector<HTMLElement>('[data-app-shell]');
      rootState = {
        appShell,
        appShellWasInert: appShell?.hasAttribute('inert') ?? false,
        bodyOverflow: document.body.style.overflow,
      };
      appShell?.setAttribute('inert', '');
      document.body.style.overflow = 'hidden';
      document.addEventListener('keydown', handleKeyDown);
    }

    layers.push(layer);
    syncLayers();

    const focusFrame = window.requestAnimationFrame(() => {
      if (topLayer() !== layer) return;
      const preferred = container.current?.querySelector<HTMLElement>('[data-autofocus]');
      const focusable = focusableElements(container.current);
      (preferred && focusable.includes(preferred) ? preferred : focusable[0])?.focus();
    });
    return () => {
      window.cancelAnimationFrame(focusFrame);
      const index = layers.indexOf(layer);
      if (index !== -1) layers.splice(index, 1);
      layerContainer?.removeAttribute('inert');
      syncLayers();

      if (layers.length === 0) {
        document.removeEventListener('keydown', handleKeyDown);
        if (!rootState?.appShellWasInert) rootState?.appShell?.removeAttribute('inert');
        document.body.style.overflow = rootState?.bodyOverflow ?? '';
        rootState = null;
      }

      restoreLayerFocus(layer);
    };
  }, []);

  return container;
}
