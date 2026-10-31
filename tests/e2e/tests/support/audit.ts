import type { Page } from '@playwright/test';

export interface AuditFinding {
  /** The rule that failed: document-scroll, amount-overflow, touch-target or primary-cta. */
  rule: 'document-scroll' | 'amount-overflow' | 'touch-target' | 'primary-cta' | 'table-alignment';
  /** A short description of the offending element (tag, accessible name or text, size). */
  element: string;
}

const MIN_TOUCH_TARGET = 44;

/**
 * Runs the review grid of the responsive audit inside the page and returns what it found.
 * `coarse` turns the touch-target rule on: desktop mouse density stays at 36 px by design.
 * `rootSelector` limits the element rules to one subtree (an open dialog).
 */
export async function auditLayout(
  page: Page,
  coarse: boolean,
  rootSelector?: string,
): Promise<AuditFinding[]> {
  return page.evaluate(
    ({ coarse, min, rootSelector }) => {
      const root: ParentNode = rootSelector ? document.querySelector(rootSelector)! : document;
      const found: { rule: string; element: string }[] = [];
      const describe = (el: Element): string => {
        const rect = el.getBoundingClientRect();
        const name =
          el.getAttribute('aria-label') ??
          (el.textContent ?? '').replace(/\s+/g, ' ').trim().slice(0, 40);
        const cls = typeof el.className === 'string' ? el.className.split(' ')[0] : '';
        return `${el.tagName.toLowerCase()}${cls ? `.${cls}` : ''} "${name}" ${Math.round(rect.width)}x${Math.round(rect.height)}`;
      };
      const visible = (el: Element): boolean => {
        const rect = el.getBoundingClientRect();
        return (
          rect.width > 0 &&
          rect.height > 0 &&
          el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true }) &&
          el.closest('[aria-hidden="true"], [inert]') === null
        );
      };
      for (const cell of root.querySelectorAll('table th, table td')) {
        if (visible(cell) && !['left', 'start'].includes(getComputedStyle(cell).textAlign)) {
          found.push({
            rule: 'table-alignment',
            element: `${cell.tagName.toLowerCase()}.${String(cell.className).split(' ')[0]}`,
          });
        }
      }
      const viewport = document.documentElement.clientWidth;

      // 1. No horizontal scroll of the document.
      const scrollWidth = document.documentElement.scrollWidth;
      if (scrollWidth > viewport + 1) {
        found.push({
          rule: 'document-scroll',
          element: `document scrollWidth ${scrollWidth} > viewport ${viewport}`,
        });
      }

      // 2. Amounts are never cut off or pushed out of their box. A table that scrolls inside its own
      // container (overflow auto or scroll) is the accepted way to keep a wide amount readable.
      const scroller = (el: Element): HTMLElement | null => {
        for (let node = el.parentElement; node; node = node.parentElement) {
          const overflow = getComputedStyle(node).overflowX;
          if (overflow !== 'visible') return node;
        }
        return null;
      };
      const amounts = [...root.querySelectorAll('.money, [data-visual-mask]')].filter(
        (el) =>
          visible(el) &&
          /\d/.test(el.textContent ?? '') &&
          el.querySelector('svg, canvas') === null &&
          el.closest('svg') === null,
      );
      for (const el of amounts) {
        const rect = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        const holder = scroller(el);
        const holderOverflow = holder ? getComputedStyle(holder).overflowX : 'visible';
        const ellipsed =
          (style.textOverflow === 'ellipsis' ||
            getComputedStyle(el.parentElement!).textOverflow === 'ellipsis') &&
          el.scrollWidth > (el as HTMLElement).clientWidth + 1 &&
          (el as HTMLElement).clientWidth > 0;
        let out = false;
        if (holder && (holderOverflow === 'hidden' || holderOverflow === 'clip')) {
          const box = holder.getBoundingClientRect();
          out = rect.right > box.right + 1 || rect.left < box.left - 1;
        } else if (!holder || holderOverflow === 'visible') {
          out = rect.right > viewport + 1 || rect.left < -1;
        }
        if (out || ellipsed) {
          found.push({ rule: 'amount-overflow', element: describe(el) });
        }
      }

      // 3. Touch targets, on a coarse pointer only. A link running inside a sentence is exempt.
      if (coarse) {
        const targets = root.querySelectorAll(
          'a[href], button, input:not([type="hidden"]), select, textarea, [role="button"], [role="tab"], [role="menuitem"], [role="checkbox"], [role="radio"], [role="switch"]',
        );
        for (const el of targets) {
          if (!visible(el) || (el as HTMLButtonElement).disabled) continue;
          // Chart point hits sit closer than 44 px, so a larger target would overlap its neighbour.
          // The same values are in the chart's equivalent table, which satisfies WCAG 2.5.8
          // (equivalent control) and is audited like any other control.
          if (el.hasAttribute('data-chart-point')) continue;
          const rect = el.getBoundingClientRect();
          if (rect.right <= 0 || rect.bottom <= 0 || rect.left >= viewport) continue;
          const inline =
            el.tagName === 'A' &&
            getComputedStyle(el).display === 'inline' &&
            [...(el.parentElement?.childNodes ?? [])].some(
              (n) => n.nodeType === Node.TEXT_NODE && (n.textContent ?? '').trim() !== '',
            );
          if (inline) continue;
          // A native control wrapped in its own label is measured through the label.
          const label = el.closest('label');
          const box = (label ?? el).getBoundingClientRect();
          if (box.width < min - 0.5 || box.height < min - 0.5) {
            found.push({ rule: 'touch-target', element: describe(label ?? el) });
          }
        }
      }

      // 4. At most one primary copper call to action visible per screen.
      const primaries = [...root.querySelectorAll('.primary-action')].filter(
        (el) => visible(el) && el.closest('[role="dialog"]') === null,
      );
      if (primaries.length > 1) {
        found.push({
          rule: 'primary-cta',
          element: `${primaries.length} primary actions: ${primaries.map(describe).join(' | ')}`,
        });
      }

      return found as { rule: AuditFinding['rule']; element: string }[];
    },
    { coarse, min: MIN_TOUCH_TARGET, rootSelector: rootSelector ?? null },
  );
}
