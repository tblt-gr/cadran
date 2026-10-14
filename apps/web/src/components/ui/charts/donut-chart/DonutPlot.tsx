import { Suspense } from 'react';
import { useBoxSize } from '@/hooks/use-box-size';
import { SkeletonChartArea } from '@/components/ui/skeleton/Skeleton';
import { lazyComponent } from '@/lib/lazyComponent';
import type { DonutSlice } from './DonutChart';
import styles from './DonutPlot.module.css';

// recharts is the heaviest dependency: the donut loads it on demand so the pages that embed
// one (portfolio, annual report) stay within the route chunk budget.
const DonutChart = lazyComponent(() => import('./DonutChart'), 'DonutChart');

interface DonutPlotProps {
  label: string;
  slices: DonutSlice[];
  totalText: string | null;
}

/** A donut that fills its box: measured once mounted, then redrawn when the box is resized. */
export function DonutPlot({ label, slices, totalText }: DonutPlotProps) {
  const { ref, size } = useBoxSize();
  const ready = size.width > 0 && size.height > 0;

  return (
    <div className={styles.plot} data-visual-mask ref={ref}>
      {ready ? (
        <Suspense fallback={<SkeletonChartArea />}>
          <DonutChart
            height={size.height}
            label={label}
            slices={slices}
            totalText={totalText}
            width={size.width}
          />
        </Suspense>
      ) : null}
    </div>
  );
}
