import { WealthChart } from './WealthChart';

/** Mirrors WealthChart element for element through its shared pending state. */
export function WealthChartSkeleton() {
  return <WealthChart points={null} />;
}
