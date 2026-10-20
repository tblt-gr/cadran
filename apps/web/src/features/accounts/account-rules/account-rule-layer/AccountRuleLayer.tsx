import type { AccountRuleClaim, AccountRuleLayer as LayerName } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { RuleClaim } from '@/features/accounts/account-rules/rule-claim/RuleClaim';
import { RuleLayerBadge } from '@/features/accounts/account-rules/rule-layer-badge/RuleLayerBadge';
import type { RuleLayerView } from '@/features/accounts/account-rules/ruleLayers';
import {
  RulePeriod,
  RuleSourceReading,
  RuleVerificationBadge,
} from '@/features/catalog-rules/rule-provenance/RuleProvenance';
import styles from './AccountRuleLayer.module.css';

interface AccountRuleLayerProps {
  effectiveLayer: LayerName;
  layer: RuleLayerView;
  onWithdraw: (claim: AccountRuleClaim) => void;
}

/**
 * What one authority states for a rule: origin, value and validity up front,
 * then a labelled disclosure for the measure, the verification and the source.
 *
 * The layer in force keeps its disclosure open, because its measure carries the
 * constraint and the warning that decide how the figure is read; a layer shown
 * only for comparison starts closed. A local claim keeps its reason and its
 * withdrawal beside the value instead of behind the disclosure.
 */
export function AccountRuleLayer({ effectiveLayer, layer, onWithdraw }: AccountRuleLayerProps) {
  const { t } = useTranslation();
  const inForce = layer.layer === effectiveLayer;

  return (
    <li className={inForce ? styles.inForce : styles.layer}>
      <dl className={styles.reading}>
        <div>
          <dt>{t('accounts.rules.fields.origin')}</dt>
          <dd>
            <RuleLayerBadge inForce={inForce} layer={layer.layer} />
          </dd>
        </div>
        <div>
          <dt>{t('accounts.rules.fields.value')}</dt>
          <dd className={styles.value}>{layer.value}</dd>
        </div>
        <div>
          <dt>{t('accounts.rules.fields.validity')}</dt>
          <dd>
            <RulePeriod validFrom={layer.validFrom} validTo={layer.validTo} />
          </dd>
        </div>
      </dl>
      {layer.claim ? <RuleClaim claim={layer.claim} onWithdraw={onWithdraw} /> : null}
      <details className={styles.details} open={inForce}>
        <summary>{t('accounts.rules.details')}</summary>
        <dl className={styles.reading}>
          <div>
            <dt>{t('accounts.rules.fields.measure')}</dt>
            <dd>{layer.measure}</dd>
          </div>
          <div>
            <dt>{t('accounts.rules.fields.verification')}</dt>
            <dd>
              <RuleVerificationBadge verification={layer.verification} />
            </dd>
          </div>
          <div>
            <dt>{t('accounts.rules.fields.source')}</dt>
            <dd>
              <RuleSourceReading source={layer.source} />
            </dd>
          </div>
        </dl>
      </details>
    </li>
  );
}
