interface Environment {
  isProduction: boolean;
  protocol: string;
  hasServiceWorker: boolean;
}

interface RegistrationOptions {
  environment: Environment;
  container: { register(url: string, options: { scope: string }): Promise<unknown> };
  onLoad: (listener: () => void) => void;
}

/** A worker is only useful, and only allowed by browsers, in a production build over HTTPS. */
export function shouldRegister({ isProduction, protocol, hasServiceWorker }: Environment): boolean {
  return isProduction && protocol === 'https:' && hasServiceWorker;
}

export function registerServiceWorker({ environment, container, onLoad }: RegistrationOptions) {
  if (!shouldRegister(environment)) return;
  // After load, so the worker's install downloads never compete with the first paint.
  onLoad(() => {
    container.register('/sw.js', { scope: '/' }).catch(() => {
      // Without a worker the application still works online; nothing to report.
    });
  });
}
