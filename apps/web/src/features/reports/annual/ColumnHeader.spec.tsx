import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { ColumnHeader } from './ColumnHeader';

afterEach(cleanup);

describe('ColumnHeader', () => {
  it('shows the parent group on a second line between parentheses', () => {
    const { container } = render(
      <ColumnHeader column={{ id: 'group:1', label: 'Bourse', parentLabel: 'Investissement' }} />,
    );

    expect(container.textContent).toBe('Bourse(Investissement)');
    expect(screen.getByText('(Investissement)').tagName).toBe('SMALL');
  });

  it('keeps the parent group on the title line when asked', () => {
    const { container } = render(
      <ColumnHeader
        column={{ id: 'group:1', label: 'Bourse', parentLabel: 'Investissement' }}
        inlineParent
      />,
    );

    expect(container.textContent).toBe('Bourse (Investissement)');
  });

  it('shows the name alone for a root group', () => {
    const { container } = render(
      <ColumnHeader column={{ id: 'group:2', label: 'Investissement', parentLabel: null }} />,
    );

    expect(container.textContent).toBe('Investissement');
  });
});
