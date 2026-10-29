import type { EmergencyFundCoverage } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Icon } from '@/components/ui/icon/Icon';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { compareDecimals, negateDecimal } from '@/lib/decimal';
import { formatCeiledFigure, formatFigure } from './formatFigure';
import { formatMonths } from './formatMonths';
import { goalErrorKind } from '@/features/goals/goalError';
import { CoverageGauge } from './CoverageGauge';
import { EmergencyFundExplainModal } from './EmergencyFundExplainModal';
import { EmergencyFundCardSkeleton } from './EmergencyFundCardSkeleton';
import { EmergencyFundPolicyModal } from './EmergencyFundPolicyModal';
import styles from './EmergencyFund.module.css';
import { useEmergencyFundCoverage } from './useEmergencyFund';

type Dialog = 'explain' | 'policy' | null;

function Gap({ coverage }: { coverage: EmergencyFundCoverage }) {
  const { t, i18n } = useTranslation();
  if (coverage.gap === null) return <EmptyValue label={t('goals.noData')} />;
  const order = compareDecimals(coverage.gap, '0');
  if (order === 0) return <>{t('goals.emergencyFund.gapReached')}</>;
  // The sign is spelled out as a word so the meaning never rests on a "-" or a colour alone.
  return order < 0 ? (
    <>
      {t('goals.emergencyFund.gapShortfall', {
        amount: formatFigure(
          negateDecimal(coverage.gap),
          coverage.reference.assetCode,
          i18n.language,
        ),
      })}
    </>
  ) : (
    <>
      {t('goals.emergencyFund.gapSurplus', {
        amount: formatFigure(coverage.gap, coverage.reference.assetCode, i18n.language),
      })}
    </>
  );
}

function missingValuationLabels(coverage: EmergencyFundCoverage): string {
  return coverage.liquidAssets.accounts
    .filter((account) => account.value === null)
    .map((account) => account.label)
    .join(', ');
}

/** The emergency-fund ratio at the top of the goals page: months covered, target and gap. */
export function EmergencyFundCard() {
  const { t, i18n } = useTranslation();
  const [dialog, setDialog] = useState<Dialog>(null);
  const query = useEmergencyFundCoverage();
  const coverage = query.data;
  const assetCode = coverage?.liquidAssets.assetCode ?? null;

  const dialogs = (
    <>
      {dialog === 'explain' && coverage ? (
        <EmergencyFundExplainModal close={() => setDialog(null)} coverage={coverage} />
      ) : null}
      {dialog === 'policy' ? <EmergencyFundPolicyModal close={() => setDialog(null)} /> : null}
    </>
  );

  if (query.isPending && coverage === undefined)
    return <EmergencyFundCardSkeleton status={t('goals.emergencyFund.loading')} />;
  if (!coverage) {
    const kind = goalErrorKind(query.error, query.isError);
    const unauthorized = kind === 'unauthorized';
    const tooLarge = kind === 'emergency_fund_scope_too_large';
    return (
      <section className={`card ${styles.card}`} role="alert">
        <h3>
          {t(
            unauthorized
              ? 'goals.unauthorized'
              : tooLarge
                ? 'goals.emergencyFund.errors.emergency_fund_scope_too_large'
                : 'goals.emergencyFund.error',
          )}
        </h3>
        {unauthorized || tooLarge ? null : (
          <button className="secondary-action" onClick={() => void query.refetch()} type="button">
            {t('foundation.retry')}
          </button>
        )}
      </section>
    );
  }

  const empty = coverage.liquidAssets.reason === 'NO_ELIGIBLE_ACCOUNT';
  const stale = coverage.liquidAssets.accounts.some((account) => account.stale);
  const missing = coverage.liquidAssets.reason === 'MISSING_VALUATION';
  const display = coverage.coveredMonthsDisplay;
  const notCalculable = coverage.coveredMonths === null || display === null;

  const requiredLabel =
    coverage.requiredAmount === null
      ? null
      : formatCeiledFigure(coverage.requiredAmount, coverage.reference.assetCode, i18n.language);
  const liquidLabel =
    coverage.liquidAssets.value === null || assetCode === null
      ? null
      : formatFigure(coverage.liquidAssets.value, assetCode, i18n.language);

  return (
    <article aria-labelledby="emergency-fund-title" className={`card ${styles.card}`}>
      <div className={styles.heading}>
        <h3 id="emergency-fund-title">{t('goals.emergencyFund.title')}</h3>
        <div className={styles.actions}>
          {empty ? null : (
            <button
              aria-label={t('goals.emergencyFund.explain')}
              className="icon-ghost"
              onClick={() => setDialog('explain')}
              title={t('goals.emergencyFund.explain')}
              type="button"
            >
              <Icon name="info" size={18} />
            </button>
          )}
          <button
            aria-label={t('goals.emergencyFund.editPolicy')}
            className="icon-ghost"
            onClick={() => setDialog('policy')}
            title={t('goals.emergencyFund.editPolicy')}
            type="button"
          >
            <Icon name="settings" size={18} />
          </button>
        </div>
      </div>
      {empty ? (
        <div className={styles.empty}>
          <p>{t('goals.emergencyFund.empty')}</p>
          <a
            className="secondary-action"
            href="/accounts"
            onClick={(event) => handleClientNavigation(event, '/accounts')}
          >
            {t('goals.emergencyFund.manageAccounts')}
          </a>
        </div>
      ) : (
        <>
          <div className={styles.position}>
            <p className={styles.amounts}>
              <span className="sr-only">{t('goals.emergencyFund.positionLabel')}</span>
              <strong className="money">
                {liquidLabel === null ? <EmptyValue label={t('goals.noData')} /> : liquidLabel}
              </strong>
              <span aria-hidden="true" className={styles.separator}>
                /
              </span>
              <span className={`money ${styles.required}`}>
                {requiredLabel === null ? <EmptyValue label={t('goals.noData')} /> : requiredLabel}
              </span>
            </p>
            <p className={styles.gap}>
              <Gap coverage={coverage} />
            </p>
          </div>
          <CoverageGauge coverage={coverage} />
          <p className={styles.coverage}>
            {display === null || notCalculable ? (
              <EmptyValue label={t('goals.emergencyFund.notCalculable')} />
            ) : (
              <strong className="money">
                {t('goals.emergencyFund.months', {
                  value: formatMonths(display, i18n.language),
                })}
              </strong>
            )}
            <span>
              {t('goals.emergencyFund.coverageOf', {
                target: formatMonths(coverage.targetMonths, i18n.language),
              })}
            </span>
          </p>
          {stale ? (
            <StatusBadge icon="alert" tone="warning">
              {t('goals.emergencyFund.stale')}
            </StatusBadge>
          ) : null}
          {notCalculable && coverage.reason ? (
            <p className={styles.reason} role="note">
              {t(`goals.emergencyFund.reasons.${coverage.reason}`, {
                defaultValue: t('goals.emergencyFund.reasons.UNKNOWN'),
              })}
              {missing ? ` ${missingValuationLabels(coverage)}` : ''}
            </p>
          ) : null}
        </>
      )}
      {dialogs}
    </article>
  );
}
