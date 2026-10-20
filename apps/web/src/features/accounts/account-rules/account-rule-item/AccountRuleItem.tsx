import type {
  AccountRuleClaim,
  AccountRuleLayer as LayerName,
  ProductRuleKind,
} from '@cadran/api-client';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { AccountRuleLayer } from '@/features/accounts/account-rules/account-rule-layer/AccountRuleLayer';
import { RuleOriginBadge } from '@/features/accounts/account-rules/rule-origin-badge/RuleOriginBadge';
import type { RuleLayerView } from '@/features/accounts/account-rules/ruleLayers';
import { RulePeriod } from '@/features/catalog-rules/rule-provenance/RuleProvenance';
import styles from './AccountRuleItem.module.css';

interface AccountRuleItemProps {
  effectiveLayer: LayerName;
  /** The rule whose details button regains focus when the reader comes back from a modal. */
  focusAction: boolean;
  kind: ProductRuleKind;
  layers: RuleLayerView[];
  onOverride: (kind: ProductRuleKind) => void;
  onWithdraw: (claim: AccountRuleClaim, kind: ProductRuleKind) => void;
}

/**
 * One rule of the account, reduced to what the reader came for: its name, the
 * value in force, since when, and whether that value is inherited or the
 * account's own. A warning that changes how the figure is read keeps one short
 * line beside it.
 *
 * Every other authority, the measure, the verification, the source and the
 * override action sit behind the details button, in the API order, so nothing
 * needed to understand which rule applies is dropped, only folded.
 */
export function AccountRuleItem({
  effectiveLayer,
  focusAction,
  kind,
  layers,
  onOverride,
  onWithdraw,
}: AccountRuleItemProps) {
  const { t } = useTranslation();
  const headingId = useId();
  const detailsId = useId();
  const [open, setOpen] = useState(false);
  const inForce = layers.find((layer) => layer.layer === effectiveLayer) ?? layers[0];
  const name = t(`catalog.rules.kinds.${kind}`);

  return (
    <li>
      <section aria-labelledby={headingId} className={styles.rule}>
        <header className={styles.heading}>
          <h4 id={headingId}>{name}</h4>
          {inForce ? <RuleOriginBadge layer={inForce.layer} /> : null}
          <InfoButton
            aria-controls={detailsId}
            aria-expanded={open}
            data-autofocus={focusAction || undefined}
            label={t('accounts.rules.detailsOf', { rule: name })}
            onClick={() => setOpen((current) => !current)}
          />
        </header>
        {inForce ? (
          <>
            <p className={styles.value}>{inForce.value}</p>
            <p className={styles.period}>
              <RulePeriod validFrom={inForce.validFrom} validTo={inForce.validTo} />
            </p>
            {inForce.alert}
          </>
        ) : null}
        {open ? (
          <div className={styles.details} id={detailsId}>
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
            <button className="secondary-action" onClick={() => onOverride(kind)} type="button">
              {t('accounts.rules.override')}
            </button>
          </div>
        ) : null}
      </section>
    </li>
  );
}
