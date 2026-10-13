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

export function useClientNavigation(setPath: (path: string) => void) {
  const navigate = useCallback(
    (nextPath: string) => {
      // The address bar, not the committed path: while a transition is pending the two differ,
      // and a click back on the page still on screen must still win over the pending one.
      if (nextPath === window.location.pathname) return;
      window.history.pushState({}, '', nextPath);
      setPath(nextPath);
    },
    [setPath],
  );

  const handleNavigate = useCallback(
    (event: MouseEvent<HTMLAnchorElement>, href: string) => {
      handleClientNavigation(event, href, navigate);
    },
    [navigate],
  );

  return { handleNavigate, navigate } as const;
}
