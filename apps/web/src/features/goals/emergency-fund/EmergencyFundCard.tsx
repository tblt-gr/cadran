import type { EmergencyFundCoverage } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { compareDecimals, negateDecimal } from '@/lib/decimal';
import { formatFigure } from './formatFigure';
import { formatMonths } from './formatMonths';
import { goalErrorKind } from '@/features/goals/goalError';
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

/** Covered months against the target, as a meter with a text label; hidden when not calculable. */
function CoverageMeter({ coverage }: { coverage: EmergencyFundCoverage }) {
  const { t, i18n } = useTranslation();
  const display = coverage.coveredMonthsDisplay;
  if (display === null || compareDecimals(coverage.targetMonths, '0') <= 0) return null;
  const capped =
    compareDecimals(display, coverage.targetMonths) > 0 ? coverage.targetMonths : display;
  return (
    <div className={styles.meter}>
      <label htmlFor="emergency-fund-meter">
        {t('goals.emergencyFund.meterLabel', {
          value: formatMonths(display, i18n.language),
          target: formatMonths(coverage.targetMonths, i18n.language),
        })}
      </label>
      {/* Geometry only: the exact figures are displayed as text from the API strings. */}
      <meter
        id="emergency-fund-meter"
        max={Number(coverage.targetMonths)}
        min={0}
        value={Number(capped)}
      />
    </div>
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

  return (
    <article aria-labelledby="emergency-fund-title" className={`card ${styles.card}`}>
      <div className={styles.heading}>
        <div>
          <p className={styles.eyebrow}>{t('goals.emergencyFund.eyebrow')}</p>
          <h3 id="emergency-fund-title">{t('goals.emergencyFund.title')}</h3>
        </div>
        <div className={styles.actions}>
          {empty ? null : (
            <button className="secondary-action" onClick={() => setDialog('explain')} type="button">
              {t('goals.emergencyFund.explain')}
            </button>
          )}
          <button className="secondary-action" onClick={() => setDialog('policy')} type="button">
            {t('goals.emergencyFund.editPolicy')}
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
          <p className={styles.covered}>
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
              {t('goals.emergencyFund.target', {
                value: formatMonths(coverage.targetMonths, i18n.language),
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
          <CoverageMeter coverage={coverage} />
          <dl className={styles.facts}>
            <div>
              <dt>{t('goals.emergencyFund.liquid')}</dt>
              <dd className="money">
                {coverage.liquidAssets.value === null || assetCode === null ? (
                  <EmptyValue label={t('goals.noData')} />
                ) : (
                  formatFigure(coverage.liquidAssets.value, assetCode, i18n.language)
                )}
              </dd>
            </div>
            <div>
              <dt>{t('goals.emergencyFund.required')}</dt>
              <dd className="money">
                {coverage.requiredAmount === null ? (
                  <EmptyValue label={t('goals.noData')} />
                ) : (
                  formatFigure(coverage.requiredAmount, coverage.reference.assetCode, i18n.language)
                )}
              </dd>
            </div>
            <div>
              <dt>{t('goals.emergencyFund.gap')}</dt>
              <dd>
                <Gap coverage={coverage} />
              </dd>
            </div>
          </dl>
        </>
      )}
      {dialogs}
    </article>
  );
}
