import { fireEvent, render, screen, cleanup } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { InstrumentLink } from './InstrumentLink';
const navigate = vi.hoisted(() => vi.fn());
vi.mock('@/hooks/use-client-navigation', () => ({ handleClientNavigation: navigate }));
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});
describe('InstrumentLink', () => {
  it('names a real sheet anchor and delegates modified clicks to native navigation', () => {
    render(<InstrumentLink instrument={{ id: 'abc', name: 'Monde' }} />);
    const link = screen.getByRole('link', { name: "Voir l'instrument Monde" });
    expect(link.getAttribute('href')).toBe('/investments/instruments/abc');
    expect(link.textContent).toBe('Monde');
    fireEvent.click(link, { ctrlKey: true });
    expect(navigate).toHaveBeenCalledWith(
      expect.objectContaining({ ctrlKey: true }),
      '/investments/instruments/abc',
    );
  });
});
