import { useCallback, type MouseEvent } from 'react';

function preservesBrowserNavigation(event: MouseEvent<HTMLAnchorElement>) {
  return (
    event.button !== 0 ||
    event.altKey ||
    event.ctrlKey ||
    event.metaKey ||
    event.shiftKey ||
    event.currentTarget.target === '_blank'
  );
}

export function handleClientNavigation(
  event: MouseEvent<HTMLAnchorElement>,
  href: string,
  navigate = navigateFromPage,
): boolean {
  if (preservesBrowserNavigation(event)) return false;

  event.preventDefault();
  navigate(href);
  return true;
}

function navigateFromPage(href: string) {
  window.history.pushState({}, '', href);
  window.dispatchEvent(new PopStateEvent('popstate'));
}

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
      handleClientNavigation(event, href, navigate);
    },
    [navigate],
  );

  return { handleNavigate, navigate } as const;
}
