import { describe, expect, it, vi } from 'vitest';
import { registerServiceWorker, shouldRegister } from './register';

describe('shouldRegister', () => {
  const secure = { isProduction: true, protocol: 'https:', hasServiceWorker: true };

  it('registers in production over HTTPS', () => {
    expect(shouldRegister(secure)).toBe(true);
  });

  it('does not register in development, over HTTP or without worker support', () => {
    expect(shouldRegister({ ...secure, isProduction: false })).toBe(false);
    expect(shouldRegister({ ...secure, protocol: 'http:' })).toBe(false);
    expect(shouldRegister({ ...secure, hasServiceWorker: false })).toBe(false);
  });
});

describe('registerServiceWorker', () => {
  it('registers /sw.js at the root scope once the page has loaded', async () => {
    const register = vi.fn(async () => ({}));
    const listeners: Record<string, () => void> = {};
    registerServiceWorker({
      environment: { isProduction: true, protocol: 'https:', hasServiceWorker: true },
      container: { register },
      onLoad: (listener) => {
        listeners.load = listener;
      },
    });

    expect(register).not.toHaveBeenCalled();
    listeners.load!();
    expect(register).toHaveBeenCalledWith('/sw.js', { scope: '/' });
  });

  it('does nothing outside production HTTPS', () => {
    const register = vi.fn();
    const onLoad = vi.fn();
    registerServiceWorker({
      environment: { isProduction: false, protocol: 'https:', hasServiceWorker: true },
      container: { register },
      onLoad,
    });
    expect(onLoad).not.toHaveBeenCalled();
    expect(register).not.toHaveBeenCalled();
  });

  it('swallows a registration failure: the app works without a worker', async () => {
    const register = vi.fn(async () => Promise.reject(new Error('blocked')));
    let load = () => {};
    registerServiceWorker({
      environment: { isProduction: true, protocol: 'https:', hasServiceWorker: true },
      container: { register },
      onLoad: (listener) => {
        load = listener;
      },
    });
    expect(() => load()).not.toThrow();
    await Promise.resolve();
  });
});
