import type { AccountRuleLayer } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import styles from './RuleLayerBadge.module.css';

const layerTone = {
  CATALOG: 'positive',
  INHERITED: 'info',
  OVERRIDE: 'warning',
} as const;

interface RuleLayerBadgeProps {
  inForce: boolean;
  layer: AccountRuleLayer;
}

/**
 * Which authority a value came from, and whether it is the one in force.
 *
 * Both are named in words rather than carried by colour or by row order: a
 * figure the holder typed and a figure the regulator published must not be
 * told apart only by a shade, and "in force" is the difference between a value
 * that applies and one that is shown for comparison.
 */
export function RuleLayerBadge({ inForce, layer }: RuleLayerBadgeProps) {
  const { t } = useTranslation();

  return (
    <span className={styles.layer}>
      <StatusBadge tone={layerTone[layer]}>{t(`accounts.rules.layers.${layer}`)}</StatusBadge>
      <small className={inForce ? styles.inForce : styles.superseded}>
        {t(inForce ? 'accounts.rules.inForce' : 'accounts.rules.superseded')}
      </small>
    </span>
  );
}
