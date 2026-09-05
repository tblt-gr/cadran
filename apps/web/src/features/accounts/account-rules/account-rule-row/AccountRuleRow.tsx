import type { AccountRuleClaim, AccountRuleLayer, ProductRuleKind } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { RuleClaim } from '@/features/accounts/account-rules/rule-claim/RuleClaim';
import { RuleLayerBadge } from '@/features/accounts/account-rules/rule-layer-badge/RuleLayerBadge';
import { RuleProvenance } from '@/features/catalog-rules/rule-provenance/RuleProvenance';
import type { RuleLayerView } from '@/features/accounts/account-rules/ruleLayers';
import styles from './AccountRuleRow.module.css';

interface AccountRuleRowProps {
  effectiveLayer: AccountRuleLayer;
  kind: ProductRuleKind;
  layers: RuleLayerView[];
  onOverride: (kind: ProductRuleKind) => void;
  onWithdraw: (claim: AccountRuleClaim) => void;
}

/**
 * One rule of the account, once per authority that states it.
 *
 * The layers share a single rule cell and are read down the column, which is
 * what makes the divergence legible: the published ceiling, the one the
 * workspace model carries, and the one recorded on this account alone, with
 * the row in force named in words rather than by position.
 */
export function AccountRuleRow({
  effectiveLayer,
  kind,
  layers,
  onOverride,
  onWithdraw,
}: AccountRuleRowProps) {
  const { t } = useTranslation();

  return (
    <tbody>
      {layers.map((layer, index) => (
        <tr
          className={layer.layer === effectiveLayer ? styles.inForce : undefined}
          key={layer.layer}
        >
          {index === 0 ? (
            <th rowSpan={layers.length} scope="rowgroup">
              {t(`catalog.rules.kinds.${kind}`)}
              <button className="secondary-action" onClick={() => onOverride(kind)} type="button">
                {t('accounts.rules.override')}
              </button>
            </th>
          ) : null}
          <td>
            <RuleLayerBadge inForce={layer.layer === effectiveLayer} layer={layer.layer} />
            {layer.claim ? <RuleClaim claim={layer.claim} onWithdraw={onWithdraw} /> : null}
          </td>
          <ValueCells measure={layer.measure} value={layer.value} />
          <RuleProvenance
            source={layer.source}
            validFrom={layer.validFrom}
            validTo={layer.validTo}
            verification={layer.verification}
          />
        </tr>
      ))}
    </tbody>
  );
}

interface ValueCellsProps {
  measure: ReactNode;
  value: ReactNode;
}

function ValueCells({ measure, value }: ValueCellsProps) {
  return (
    <>
      <td className={styles.value}>{value}</td>
      <td>{measure}</td>
    </>
  );
}
