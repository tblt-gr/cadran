import { groupColor } from '@/features/accounts/group-color';
import { formatSharePercent } from '@/lib/formatSharePercent';
import { isSlice } from '@/features/dashboard/allocation-charts/allocationSlice';
import styles from './TreemapTile.module.css';

/** One Treemap cell: only the first level is drawn, deeper nodes stay transparent. */
export function TreemapTile(node: {
  depth: number;
  height: number;
  index: number;
  name?: string;
  payload?: unknown;
  width: number;
  x: number;
  y: number;
}) {
  if (node.depth !== 1 || node.width <= 0 || node.height <= 0) {
    return (
      <rect
        fill="var(--color-transparent)"
        height={Math.max(0, node.height)}
        stroke="var(--color-transparent)"
        width={Math.max(0, node.width)}
        x={node.x}
        y={node.y}
      />
    );
  }

  // Room for a second line: the share then sits under the name, larger.
  const twoLines = node.height > 48;
  const slice = isSlice(node.payload) ? node.payload : isSlice(node) ? node : null;
  const percent =
    slice !== null && slice.percentDisplay !== null
      ? formatSharePercent(slice.percentDisplay, { fractionDigits: 2 })
      : null;

  return (
    <g>
      {slice !== null ? <title>{slice.path}</title> : null}
      {/* The group colour tints the tile and draws its top edge; the label stays in the text ramp,
          so a saturated group never outshouts the wealth curve and every label keeps AA. */}
      <rect
        className={styles.tile}
        fill={groupColor(slice?.fill)}
        height={node.height}
        width={node.width}
        x={node.x}
        y={node.y}
      />
      <line
        className={styles.tileEdge}
        stroke={groupColor(slice?.fill)}
        x1={node.x}
        x2={node.x + node.width}
        y1={node.y + 1}
        y2={node.y + 1}
      />
      {node.width > 48 && node.height > 24 ? (
        // A nested viewport clips the label to its own tile: tiles are translucent, so a long
        // label would otherwise show through its neighbour.
        <svg height={node.height} overflow="hidden" width={node.width} x={node.x} y={node.y}>
          <text fill="var(--text-primary)" className={styles.tileLabel} x={8} y={19}>
            {node.name}
            {slice?.parentLabel ? (
              <tspan className={styles.tileParent}>{` (${slice.parentLabel})`}</tspan>
            ) : null}
            {percent && !twoLines ? ` · ${percent}` : ''}
          </text>
          {percent && twoLines ? (
            <text fill="var(--text-primary)" className={styles.tilePercent} x={8} y={40}>
              {percent}
            </text>
          ) : null}
        </svg>
      ) : null}
    </g>
  );
}
