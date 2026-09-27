import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';

interface HeaderTitleState {
  path: string;
  title: string;
}

interface HeaderTitleContextValue {
  state: HeaderTitleState | null;
  setHeaderTitle: (path: string, title: string | null) => void;
}

const HeaderTitleContext = createContext<HeaderTitleContextValue>({
  state: null,
  setHeaderTitle: () => {},
});

export function HeaderTitleProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<HeaderTitleState | null>(null);
  const setHeaderTitle = useCallback((path: string, title: string | null) => {
    setState((current) => {
      if (title === null) return current !== null && current.path === path ? null : current;
      return current !== null && current.path === path && current.title === title
        ? current
        : { path, title };
    });
  }, []);
  const value = useMemo(() => ({ state, setHeaderTitle }), [state, setHeaderTitle]);
  return <HeaderTitleContext.Provider value={value}>{children}</HeaderTitleContext.Provider>;
}

/** Published by a page so the shell header shows its own title for its own path. */
export function useHeaderTitle(path: string, title: string | null) {
  const { setHeaderTitle } = useContext(HeaderTitleContext);
  useEffect(() => {
    setHeaderTitle(path, title);
    return () => setHeaderTitle(path, null);
  }, [path, title, setHeaderTitle]);
}

/** Reads the title published for `path`, if any. */
export function useHeaderTitleValue(path: string): string | null {
  const { state } = useContext(HeaderTitleContext);
  return state !== null && state.path === path ? state.title : null;
}
