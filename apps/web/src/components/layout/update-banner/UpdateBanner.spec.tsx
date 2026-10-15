import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { UpdateBanner } from './UpdateBanner';

type Listener = () => void;

class FakeWorker {
  state = 'installed';
  postMessage = vi.fn();
  private listeners = new Set<Listener>();
  addEventListener(_type: string, listener: Listener) {
    this.listeners.add(listener);
  }
  removeEventListener(_type: string, listener: Listener) {
    this.listeners.delete(listener);
  }
  emit() {
    this.listeners.forEach((listener) => listener());
  }
}

class FakeRegistration {
  waiting: FakeWorker | null = null;
  installing: FakeWorker | null = null;
  private listeners = new Set<Listener>();
  addEventListener(_type: string, listener: Listener) {
    this.listeners.add(listener);
  }
  removeEventListener(_type: string, listener: Listener) {
    this.listeners.delete(listener);
  }
  emit() {
    this.listeners.forEach((listener) => listener());
  }
}

class FakeContainer {
  controller: object | null = {};
  registration: FakeRegistration | undefined;
  private listeners = new Set<Listener>();
  getRegistration = vi.fn(async () => this.registration);
  addEventListener(_type: string, listener: Listener) {
    this.listeners.add(listener);
  }
  removeEventListener(_type: string, listener: Listener) {
    this.listeners.delete(listener);
  }
  emit() {
    this.listeners.forEach((listener) => listener());
  }
}

async function renderBanner(container: FakeContainer, reload = vi.fn()) {
  render(
    <UpdateBanner container={container as unknown as ServiceWorkerContainer} reload={reload} />,
  );
  await act(async () => {});
  return reload;
}

describe('UpdateBanner', () => {
  afterEach(cleanup);

  it('renders nothing without a registration', async () => {
    await renderBanner(new FakeContainer());
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('renders nothing when no worker is waiting', async () => {
    const container = new FakeContainer();
    container.registration = new FakeRegistration();
    await renderBanner(container);
    expect(screen.queryByText('Nouvelle version disponible')).toBeNull();
  });

  it('renders nothing when the browser has no service worker support', () => {
    render(<UpdateBanner container={undefined} reload={vi.fn()} />);
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('announces an update already waiting at load', async () => {
    const container = new FakeContainer();
    container.registration = new FakeRegistration();
    container.registration.waiting = new FakeWorker();
    await renderBanner(container);

    const status = screen.getByRole('status');
    expect(status.textContent).toContain('Nouvelle version disponible');
    expect(screen.getByRole('button', { name: 'Recharger' })).toBeTruthy();
  });

  it('announces an update that finishes installing while the page is open', async () => {
    const container = new FakeContainer();
    const registration = new FakeRegistration();
    container.registration = registration;
    await renderBanner(container);

    const worker = new FakeWorker();
    worker.state = 'installing';
    registration.installing = worker;
    act(() => registration.emit());
    expect(screen.queryByRole('status')).toBeNull();

    worker.state = 'installed';
    registration.waiting = worker;
    act(() => worker.emit());
    expect(screen.getByRole('status').textContent).toContain('Nouvelle version disponible');
  });

  it('ignores the first installation, when no worker controlled the page', async () => {
    const container = new FakeContainer();
    container.controller = null;
    container.registration = new FakeRegistration();
    container.registration.waiting = new FakeWorker();
    await renderBanner(container);
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('asks the waiting worker to take over and reloads once it has', async () => {
    const container = new FakeContainer();
    container.registration = new FakeRegistration();
    const worker = new FakeWorker();
    container.registration.waiting = worker;
    const reload = await renderBanner(container);

    fireEvent.click(screen.getByRole('button', { name: 'Recharger' }));
    expect(worker.postMessage).toHaveBeenCalledWith('SKIP_WAITING');
    expect(reload).not.toHaveBeenCalled();

    act(() => container.emit());
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('does not reload on a controller change the user did not ask for', async () => {
    const container = new FakeContainer();
    container.registration = new FakeRegistration();
    container.registration.waiting = new FakeWorker();
    const reload = await renderBanner(container);

    act(() => container.emit());
    expect(reload).not.toHaveBeenCalled();
  });
});
