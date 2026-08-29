import { Icon } from '../../design-system/Icon';
import { StatusBadge } from '../../design-system/StatusBadge';
import { useTranslation } from 'react-i18next';
import { AllocationPanel } from './AllocationPanel';
import { dashboardDemoData } from './dashboardDemoData';
import { formatDemoFullDate, formatDemoMonth } from './formatDemoDate';
import { WealthChart } from './WealthChart';

function WrappableMoney({ value }: { value: string }) {
  const groups = value.split('\u202f');

  return groups.map((group, index) =>
    index === groups.length - 1 ? (
      <span className="wealth-card__amount-tail" key={group}>
        {group}
      </span>
    ) : (
      <span key={`${group}-${index}`}>
        {group}
        {'\u202f'}
        <wbr />
      </span>
    ),
  );
}

export function DashboardPage({ apiVersion }: { apiVersion: string }) {
  const { i18n, t } = useTranslation();

  return (
    <>
      <div className="dashboard-grid">
        <section className="card wealth-card" aria-labelledby="net-worth-title">
          <div className="wealth-card__header">
            <div>
              <h2 id="net-worth-title">{t('dashboard.netWorth.title')}</h2>
              <p className="money wealth-card__amount">
                <WrappableMoney value={dashboardDemoData.netWorth} />
              </p>
              <p className="wealth-card__delta">
                <span
                  aria-label={t('dashboard.netWorth.increaseAccessible', {
                    amount: dashboardDemoData.delta,
                  })}
                  className="delta-positive"
                >
                  <Icon name="arrow-up" size={16} />
                  <span aria-hidden="true">+ {dashboardDemoData.delta}</span>
                </span>
                <span>
                  {t('dashboard.netWorth.deltaPeriod', {
                    period: formatDemoMonth(dashboardDemoData.deltaSincePeriod, i18n.language),
                  })}
                </span>
              </p>
            </div>
            <div className="period-control" aria-label={t('dashboard.period.label')}>
              <button
                aria-label={t('dashboard.period.previous')}
                className="icon-button"
                disabled
                type="button"
              >
                <Icon name="chevron-left" />
              </button>
              <span>{formatDemoMonth(dashboardDemoData.currentPeriod, i18n.language)}</span>
              <button
                aria-label={t('dashboard.period.next')}
                className="icon-button"
                disabled
                type="button"
              >
                <Icon name="chevron-right" />
              </button>
            </div>
          </div>
          <WealthChart />
        </section>

        <AllocationPanel />

        <section className="card kpi-card" aria-labelledby="income-title">
          <div className="kpi-card__icon kpi-card__icon--positive">
            <Icon name="arrow-down" />
          </div>
          <div>
            <h2 id="income-title">{t('dashboard.income.title')}</h2>
            <p className="money kpi-card__value">{dashboardDemoData.incomeValue}</p>
            <p className="supporting-text">
              {t('dashboard.income.description', {
                count: dashboardDemoData.incomeOperationCount,
                period: formatDemoMonth(dashboardDemoData.currentPeriod, i18n.language),
              })}
            </p>
          </div>
        </section>

        <section className="card kpi-card" aria-labelledby="spending-title">
          <div className="kpi-card__icon kpi-card__icon--negative">
            <Icon name="arrow-up" />
          </div>
          <div>
            <h2 id="spending-title">{t('dashboard.spending.title')}</h2>
            <p className="money kpi-card__value">{dashboardDemoData.spendingValue}</p>
            <p className="supporting-text">
              {t('dashboard.spending.description', {
                count: dashboardDemoData.spendingOperationCount,
                period: formatDemoMonth(dashboardDemoData.currentPeriod, i18n.language),
              })}
            </p>
          </div>
        </section>

        <section className="card kpi-card" aria-labelledby="saving-title">
          <div className="kpi-card__icon kpi-card__icon--warning">
            <Icon name="alert" />
          </div>
          <div>
            <h2 id="saving-title">{t('dashboard.savingRate.title')}</h2>
            <p className="kpi-card__unavailable">{t('states.notCalculable.label')}</p>
            <p className="supporting-text">{t('states.notCalculable.reason')}</p>
          </div>
        </section>
      </div>

      <p className="api-status sr-only" role="status">
        {t('foundation.readyWithVersion', { version: apiVersion })}
      </p>
    </>
  );
}

export function DashboardContextPanel() {
  const { i18n, t } = useTranslation();

  return (
    <aside className="context-panel" aria-labelledby="quality-title">
      <div className="context-panel__heading">
        <div>
          <h2 id="quality-title">{t('dashboard.quality.title')}</h2>
          <p>{t('dashboard.quality.description')}</p>
        </div>
        <StatusBadge tone="warning">{t('states.stale.label')}</StatusBadge>
      </div>

      <section className="quality-item">
        <div className="quality-item__icon quality-item__icon--warning">
          <Icon name="alert" />
        </div>
        <div>
          <h3>{t('states.stale.title')}</h3>
          <p>
            {t('states.stale.description', {
              date: formatDemoFullDate(dashboardDemoData.lastBalanceDate, i18n.language),
            })}
          </p>
        </div>
      </section>

      <section className="quality-item">
        <div className="quality-item__icon quality-item__icon--info">
          <Icon name="transactions" />
        </div>
        <div>
          <h3>{t('states.empty.title')}</h3>
          <p>{t('states.empty.description')}</p>
        </div>
      </section>

      <section className="quality-item">
        <div className="quality-item__icon quality-item__icon--negative">
          <Icon name="alert" />
        </div>
        <div>
          <h3>{t('states.notCalculable.title')}</h3>
          <p>{t('states.notCalculable.explanation')}</p>
        </div>
      </section>
    </aside>
  );
}
