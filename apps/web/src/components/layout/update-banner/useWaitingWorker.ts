import { useEffect, useState } from 'react';

/**
 * The service worker that waits to replace the one controlling the page, if any.
 * A waiting worker on a page nobody controls is the first installation: nothing to update.
 */
export function useWaitingWorker(container: ServiceWorkerContainer | undefined) {
  const [waiting, setWaiting] = useState<ServiceWorker | null>(null);

  useEffect(() => {
    if (!container) return;
    let isCurrent = true;
    const cleanups: Array<() => void> = [];

    function announce(worker: ServiceWorker | null) {
      if (isCurrent && worker && container?.controller) setWaiting(worker);
    }

    function watchInstalling(registration: ServiceWorkerRegistration) {
      const worker = registration.installing;
      if (!worker) return;
      const onStateChange = () => {
        if (worker.state === 'installed') announce(registration.waiting ?? worker);
      };
      worker.addEventListener('statechange', onStateChange);
      cleanups.push(() => worker.removeEventListener('statechange', onStateChange));
    }

    void container.getRegistration().then((registration) => {
      if (!isCurrent || !registration) return;
      announce(registration.waiting);
      const onUpdateFound = () => watchInstalling(registration);
      registration.addEventListener('updatefound', onUpdateFound);
      cleanups.push(() => registration.removeEventListener('updatefound', onUpdateFound));
    });

    return () => {
      isCurrent = false;
      cleanups.forEach((cleanup) => cleanup());
    };
  }, [container]);

  return waiting;
}
