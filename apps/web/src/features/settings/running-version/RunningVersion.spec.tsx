import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { RunningVersion } from './RunningVersion';

describe('RunningVersion', () => {
  afterEach(cleanup);

  it('shows the version the server reports', () => {
    render(<RunningVersion version="1.0.0" />);

    expect(screen.getByText('Version 1.0.0')).toBeTruthy();
  });

  it('shows an unknown version as is, never as a guess', () => {
    render(<RunningVersion version="unknown" />);

    expect(screen.getByText('Version unknown')).toBeTruthy();
  });

  it('keeps the real label and holds the value back while the status is pending', () => {
    const { container } = render(<RunningVersion version={undefined} />);

    expect(screen.getByText('Version')).toBeTruthy();
    expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull();
    expect(screen.queryByText(/\d+\.\d+\.\d+/)).toBeNull();
  });

  it('renders nothing when the status could not be read', () => {
    const { container } = render(<RunningVersion version={null} />);

    expect(container.textContent).toBe('');
  });
});
