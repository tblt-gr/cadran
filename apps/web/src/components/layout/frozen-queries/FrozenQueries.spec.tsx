import { useQuery } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { FrozenQueries } from './FrozenQueries';

function Probe({ enabled }: { enabled: boolean }) {
  const query = useQuery({ queryKey: ['probe'], queryFn: queryFn, enabled });

  return <p>{query.isPending ? 'pending' : 'settled'}</p>;
}

const queryFn = vi.fn(async () => 'value');

describe('FrozenQueries', () => {
  afterEach(() => {
    cleanup();
    queryFn.mockClear();
  });

  it.each([true, false])('keeps every query pending and unfetched (enabled=%s)', (enabled) => {
    render(
      <FrozenQueries>
        <Probe enabled={enabled} />
      </FrozenQueries>,
    );

    expect(screen.getByText('pending')).toBeTruthy();
    expect(queryFn).not.toHaveBeenCalled();
  });
});
