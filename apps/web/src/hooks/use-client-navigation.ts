import { useCallback, type MouseEvent } from 'react';

export function useClientNavigation(path: string, setPath: (path: string) => void) {
  const navigate = useCallback(
    (nextPath: string) => {
      if (nextPath === path) return;
      window.history.pushState({}, '', nextPath);
      setPath(nextPath);
    },
    [path, setPath],
  );

  const handleNavigate = useCallback(
    (event: MouseEvent<HTMLAnchorElement>, href: string) => {
      if (
        event.button !== 0 ||
        event.altKey ||
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey ||
        event.currentTarget.target === '_blank'
      ) {
        return;
      }

      event.preventDefault();
      navigate(href);
    },
    [navigate],
  );

  return { handleNavigate, navigate } as const;
}
