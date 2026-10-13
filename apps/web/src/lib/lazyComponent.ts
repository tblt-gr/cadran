import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

// `any` props: the constraint must accept every component; the call site keeps the exact type.
type LazyComponent<C extends ComponentType<any>> = LazyExoticComponent<C> & {
  /** Requests the chunk ahead of the render that needs it. */
  preload: () => Promise<unknown>;
};

/**
 * A component loaded on demand from a named export: a route page, or a heavy
 * chart. `preload` shares the module cache with the render path, so warming a
 * chunk on navigation intent costs one request and the later render finds it
 * ready. A failed warm-up is swallowed here: the render path retries and
 * reports the failure itself.
 */
export function lazyComponent<K extends string, C extends ComponentType<any>>(
  load: () => Promise<Record<K, C>>,
  name: K,
): LazyComponent<C> {
  const component = lazy(async () => ({ default: (await load())[name] }));
  return Object.assign(component, { preload: () => load().catch(() => undefined) });
}
