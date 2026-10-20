import type {
  AccountRuleClaim,
  AccountRuleLayer as LayerName,
  ProductRuleKind,
} from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { AccountRuleLayer } from '@/features/accounts/account-rules/account-rule-layer/AccountRuleLayer';
import type { RuleLayerView } from '@/features/accounts/account-rules/ruleLayers';
import { SheetRow } from '@/features/accounts/account-rules/sheet-row/SheetRow';
import { RulePeriod } from '@/features/catalog-rules/rule-provenance/RuleProvenance';
import styles from './AccountSheet.module.css';

interface RuleRowProps {
  /** More to read behind the same button, such as the yield's two other readings. */
  extraDetail?: ReactNode;
  effectiveLayer: LayerName;
  focusAction: boolean;
  kind: ProductRuleKind;
  /** Replaces the rule's own name as the line label. */
  label?: string;
  layers: RuleLayerView[];
  onOverride: (kind: ProductRuleKind) => void;
  onWithdraw: (claim: AccountRuleClaim, kind: ProductRuleKind) => void;
  /** Words in front of the value, such as "Taux garanti". */
  valueLead?: string;
}

/**
 * One rule of the account as a single line: its name, the value in force, since
 * when, a warning when one changes how the figure is read, and a badge only
 * when the value is the account's own claim.
 *
 * Every authority that states the rule, the measure, the verification, the
 * source and the override action wait behind the "i" button, in the API order,
 * so nothing needed to understand which rule applies is dropped, only folded.
 */
export function RuleRow({
  effectiveLayer,
  extraDetail,
  focusAction,
  kind,
  label,
  layers,
  onOverride,
  onWithdraw,
  valueLead,
}: RuleRowProps) {
  const { t } = useTranslation();
  const name = label ?? t(`catalog.rules.kinds.${kind}`);
  const inForce = layers.find((layer) => layer.layer === effectiveLayer) ?? layers[0];

  return (
    <SheetRow
      alert={inForce?.alert}
      badge={
        inForce?.layer === 'OVERRIDE' ? (
          <StatusBadge tone="warning">{t('accounts.rules.origin.override')}</StatusBadge>
        ) : null
      }
      detail={
        <div className={styles.detail}>
          <ol className={styles.layers}>
            {layers.map((layer) => (
              <AccountRuleLayer
                effectiveLayer={effectiveLayer}
                key={layer.layer}
                layer={layer}
                onWithdraw={(claim) => onWithdraw(claim, kind)}
              />
            ))}
          </ol>
          {extraDetail}
          <button className="secondary-action" onClick={() => onOverride(kind)} type="button">
            {t('accounts.rules.override')}
          </button>
        </div>
      }
      detailLabel={t('accounts.rules.detailsOf', { rule: name })}
      focusAction={focusAction}
      label={name}
      since={
        inForce ? <RulePeriod validFrom={inForce.validFrom} validTo={inForce.validTo} /> : null
      }
      value={
        inForce ? (
          <>
            {valueLead ? <span>{valueLead} </span> : null}
            {inForce.value}
          </>
        ) : null
      }
    />
  );
}
