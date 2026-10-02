import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';

/**
 * A client that never fetches: every query stays pending with no data, so a
 * real screen mounted under it renders exactly its own loading state.
 */
class FrozenQueryClient extends QueryClient {
  constructor() {
    super();
    const original = this.defaultQueryOptions.bind(this);
    // Forced after the hook's own options, so a query that sets `enabled` itself stays frozen.
    this.defaultQueryOptions = ((options: never) => ({
      ...original(options),
      enabled: false,
    })) as QueryClient['defaultQueryOptions'];
  }
}

/**
 * Lets a screen be drawn before any data or session exists, with its static
 * chrome (titles, labels, buttons) rendered for real and only its data areas in
 * skeleton. Nothing mounted below may mutate or navigate: it is a preview of
 * the loading state, not a working page.
 */
export function FrozenQueries({ children }: { children: ReactNode }) {
  const [client] = useState(() => new FrozenQueryClient());

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
