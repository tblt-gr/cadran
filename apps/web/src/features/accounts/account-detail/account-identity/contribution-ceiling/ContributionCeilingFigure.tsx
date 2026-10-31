import type { AccountRules } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { ceilingFillPercent } from '@/lib/depositCeiling';
import { formatAmount } from '@/lib/decimal';
import styles from './ContributionCeilingFigure.module.css';

/**
 * The contributions paid in so far against the ceiling that caps them (a PEA). The server
 * measured the figure and decided the verdict; a plan worth more than its ceiling is not over it,
 * so the balance never appears here. Nothing is drawn when contributions are not tracked.
 */
export function ContributionCeilingFigure({ rules }: { rules: AccountRules }) {
  const { i18n, t } = useTranslation();
  const rule = rules.ceilings.find((item) => item.basis === 'CONTRIBUTIONS');
  const layer =
    rule?.[
      rule.effectiveLayer === 'CATALOG'
        ? 'catalog'
        : rule.effectiveLayer === 'INHERITED'
          ? 'inherited'
          : 'override'
    ];
  const measured = rule?.check.measured ?? null;
  if (!rule || !layer || measured === null) return null;

  const ceiling = layer.amount;
  const ratio = ceilingFillPercent(measured.value, ceiling.value);

  return (
    <div className={styles.figure}>
      <span className={styles.label}>{t('accounts.detail.identity.contributions')}</span>
      <MoneyValue
        className={styles.value}
        value={t('accounts.balances.againstCeiling', {
          ceiling: formatAmount(ceiling.value, ceiling.assetCode, i18n.language),
          current: formatAmount(measured.value, measured.assetCode, i18n.language),
        })}
      />
      {ratio === null ? null : (
        <span className={styles.track} aria-hidden="true">
          <span className={styles.fill} style={{ width: `${ratio}%` }} />
        </span>
      )}
      {rule.check.status === 'EXCEEDED' ? (
        <small className={styles.alert}>
          {t('accounts.detail.identity.contributionsExceeded')}
        </small>
      ) : null}
    </div>
  );
}
