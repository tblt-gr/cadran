import type { Locator, Page } from '@playwright/test';

/**
 * Everything that depends on the day or on the demo ledger is masked, so a comparison only
 * judges layout and chrome. Scope the masks to the element being captured, or the page behind a
 * modal would paint masks over it: the shared amount, date and chart primitives carry
 * `data-visual-mask`, and so do the few feature texts that follow the calendar. Exclude values
 * inside screen-reader-only content: masks ignore its clipping and would cover visible chrome.
 */
export function visualMasks(scope: Page | Locator): Locator[] {
  return [scope.locator('[data-visual-mask]:not(.sr-only [data-visual-mask])')];
}
