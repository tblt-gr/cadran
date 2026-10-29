import type { CreateCategoryRequest } from '@cadran/api-client';

export type AnalyticAxis = CreateCategoryRequest['defaultAnalyticAxes'][number];

/** Display order of the axes: need first, then regularity, then usage. */
const ORDERED_AXES: readonly AnalyticAxis[] = [
  'ESSENTIAL',
  'DISCRETIONARY',
  'FIXED',
  'VARIABLE',
  'PERSONAL',
  'PROFESSIONAL',
];

/** The given axes in display order, whatever order they were selected or stored in. */
export function sortAxes(axes: readonly AnalyticAxis[]): AnalyticAxis[] {
  return ORDERED_AXES.filter((axis) => axes.includes(axis));
}
