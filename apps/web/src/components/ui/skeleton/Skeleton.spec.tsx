/// <reference types="node" />
import { cleanup, render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import {
  SkeletonBlock,
  SkeletonChartArea,
  SkeletonKpiCard,
  SkeletonStatus,
  SkeletonTableRows,
  SkeletonText,
} from './Skeleton';

describe('Skeleton primitives', () => {
  afterEach(cleanup);

  it('keeps decorative placeholders hidden and announces one supplied status', () => {
    render(
      <section aria-busy="true">
        <SkeletonStatus>Loading report</SkeletonStatus>
        <SkeletonBlock />
        <SkeletonText />
        <SkeletonKpiCard />
        <SkeletonChartArea />
        <table>
          <SkeletonTableRows columns={2} rows={2} />
        </table>
      </section>,
    );

    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(document.querySelectorAll('[aria-hidden="true"]')).toHaveLength(14);
  });

  it('tags every placeholder so the browser suite can wait for the data to replace it', () => {
    const { container } = render(<SkeletonBlock />);

    expect(container.querySelector('[data-skeleton]')).not.toBeNull();
  });

  it('uses the shared reduced-motion treatment', async () => {
    const css = readFileSync('src/components/ui/skeleton/Skeleton.module.css', 'utf8');
    expect(css).toContain('@media (prefers-reduced-motion: reduce)');
    expect(css).toContain('animation: none');
  });
});
