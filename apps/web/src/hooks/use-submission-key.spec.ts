import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { useSubmissionKey } from './use-submission-key';

describe('useSubmissionKey', () => {
  it('reuses the key when the same body is retried', () => {
    const { result } = renderHook(() => useSubmissionKey());

    const first = result.current.keyFor({ label: 'Loyer' });

    expect(result.current.keyFor({ label: 'Loyer' })).toBe(first);
  });

  it('issues a new key when the body changes', () => {
    const { result } = renderHook(() => useSubmissionKey());

    const first = result.current.keyFor({ label: 'Loyer' });

    expect(result.current.keyFor({ label: 'Salaire' })).not.toBe(first);
  });

  it('issues a new key for the next submission once the previous one succeeded', () => {
    const { result } = renderHook(() => useSubmissionKey());

    const first = result.current.keyFor({ label: 'Loyer' });
    result.current.release();

    expect(result.current.keyFor({ label: 'Loyer' })).not.toBe(first);
  });

  it('gives back the first key when an edit is reverted to an earlier body', () => {
    const { result } = renderHook(() => useSubmissionKey());

    const first = result.current.keyFor({ label: 'Loyer' });
    result.current.keyFor({ label: 'Salaire' });

    expect(result.current.keyFor({ label: 'Loyer' })).toBe(first);
  });
});
