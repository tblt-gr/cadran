import { useBoxSize } from '@/hooks/use-box-size';
import { DonutChart, type DonutSlice } from './DonutChart';
import styles from './DonutPlot.module.css';

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
    <div className={styles.plot} ref={ref}>
      {ready ? (
        <DonutChart
          height={size.height}
          label={label}
          slices={slices}
          totalText={totalText}
          width={size.width}
        />
      ) : null}
    </div>
  );
}
