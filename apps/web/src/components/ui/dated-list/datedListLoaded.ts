import { waitFor } from '@testing-library/react';
import { expect } from 'vitest';

/** The windowed list loads on demand: waits until its skeleton fallback has been replaced. */
export async function datedListLoaded() {
  await waitFor(() => expect(document.querySelector('[aria-busy="true"] > ol')).toBeNull());
}
