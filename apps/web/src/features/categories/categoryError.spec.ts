import { describe, expect, it } from 'vitest';
import en from '@/locales/en/translation.json';
import fr from '@/locales/fr/translation.json';
import { categoryRequestError } from './categoryError';

function conflict(type: string) {
  return categoryRequestError({
    error: { type, title: 't', status: 409, detail: 'd' },
    response: new Response('{}', { status: 409 }),
  });
}

describe('categoryRequestError', () => {
  it('names a closed period instead of a label conflict', () => {
    const { kind } = conflict('/problems/period-closed');

    expect(kind).toBe('periodClosed');
    expect(en.categories.errors[kind]).toBe(
      'A closed month prevents this change. Reopen the month concerned, then try again.',
    );
    expect(fr.categories.errors[kind]).toBe(
      'Un mois clos empêche cette modification. Rouvrez le mois concerné, puis réessayez.',
    );
  });

  it('keeps any other 409 as a conflict', () => {
    expect(conflict('about:blank').kind).toBe('conflict');
  });
});
