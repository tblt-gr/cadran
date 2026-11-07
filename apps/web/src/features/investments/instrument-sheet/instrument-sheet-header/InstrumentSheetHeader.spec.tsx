import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Header } from '@/components/layout/header/Header';
import { HeaderActionsProvider } from '@/components/layout/header/HeaderActionsContext';
import { instrumentSheetFixture } from '@/features/investments/instrument-sheet/instrumentSheetFixture';
import { InstrumentSheetHeader } from './InstrumentSheetHeader';

const instrument = instrumentSheetFixture().instrument;
afterEach(cleanup);
function mount(status: 'ACTIVE' | 'INACTIVE' | null = 'ACTIVE') {
  const onEdit = vi.fn();
  const onStatus = vi.fn();
  render(
    <HeaderActionsProvider>
      <div data-testid="shell-header">
        <Header title="Instrument" />
      </div>
      <div data-testid="sheet-header">
        <InstrumentSheetHeader
          instrument={status ? { ...instrument, status } : null}
          pending={!status}
          onEdit={onEdit}
          onStatus={onStatus}
        />
      </div>
    </HeaderActionsProvider>,
  );
  return { onEdit, onStatus };
}
describe('InstrumentSheetHeader', () => {
  it('portals the three-dot trigger into the shell header and removes the standalone list link', () => {
    const { onEdit } = mount();
    const trigger = within(screen.getByTestId('shell-header')).getByRole('button', {
      name: `Actions de l’instrument ${instrument.name}`,
    });
    expect(within(screen.getByTestId('sheet-header')).queryByRole('button')).toBeNull();
    expect(screen.queryByRole('link', { name: 'Voir tous les instruments' })).toBeNull();
    fireEvent.click(trigger);
    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
    expect(onEdit).toHaveBeenCalledTimes(1);
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
  });
  it.each([
    ['ACTIVE', 'Archiver'],
    ['INACTIVE', 'Réactiver'],
  ] as const)('offers %s status action through the shared menu', (status, label) => {
    const { onStatus } = mount(status);
    fireEvent.click(screen.getByRole('button', { name: /^Actions de l’instrument/ }));
    fireEvent.click(screen.getByRole('button', { name: label }));
    expect(onStatus).toHaveBeenCalledTimes(1);
  });
  it('keeps one disabled trigger in the real header slot while loading', () => {
    mount(null);
    const trigger = within(screen.getByTestId('shell-header')).getByRole('button', {
      name: 'Actions de l’instrument',
    });
    expect((trigger as HTMLButtonElement).disabled).toBe(true);
    expect(screen.queryByRole('button', { name: 'Modifier' })).toBeNull();
  });
  it('keeps the shared arrow navigation and Escape focus return', () => {
    mount();
    const trigger = screen.getByRole('button', { name: /^Actions de l’instrument/ });
    trigger.focus();
    fireEvent.click(trigger);
    fireEvent.keyDown(window, { key: 'ArrowDown' });
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Modifier' }));
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(document.activeElement).toBe(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
  });
});
