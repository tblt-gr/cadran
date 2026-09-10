import { useEffect, useRef, type RefObject } from 'react';

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

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

  const focusableElements =
    layer.container.current?.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR);
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
      (preferred ?? container.current?.querySelector<HTMLElement>(FOCUSABLE_SELECTOR))?.focus();
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
