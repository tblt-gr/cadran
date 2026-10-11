import { createContext, useContext, useMemo, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

interface HeaderActionsContextValue {
  slot: HTMLElement | null;
  setSlot: (element: HTMLElement | null) => void;
}

const HeaderActionsContext = createContext<HeaderActionsContextValue | null>(null);

export function HeaderActionsProvider({ children }: { children: ReactNode }) {
  const [slot, setSlot] = useState<HTMLElement | null>(null);
  const value = useMemo(() => ({ slot, setSlot }), [slot]);
  return <HeaderActionsContext.Provider value={value}>{children}</HeaderActionsContext.Provider>;
}

/** Ref callback for the header element that hosts the page actions. */
export function useHeaderActionsSlot() {
  return useContext(HeaderActionsContext)?.setSlot;
}

/**
 * Renders a page's actions inside the shell header. Outside a shell (isolated
 * page tests) the actions render in place so they stay reachable.
 */
export function HeaderActions({ children }: { children: ReactNode }) {
  const context = useContext(HeaderActionsContext);
  if (context === null) return <>{children}</>;
  return context.slot ? createPortal(children, context.slot) : null;
}
