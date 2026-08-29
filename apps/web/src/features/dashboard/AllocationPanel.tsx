import { useTranslation } from 'react-i18next';
import { dashboardDemoData } from './dashboardDemoData';

export function AllocationPanel() {
  const { t } = useTranslation();

  return (
    <section className="card allocation-card" aria-labelledby="allocation-title">
      <div className="card__heading">
        <div>
          <h2 id="allocation-title">{t('dashboard.allocation.title')}</h2>
          <p>{t('dashboard.allocation.description')}</p>
        </div>
        <a className="text-link" href="/accounts">
          {t('dashboard.allocation.viewAccounts')}
        </a>
      </div>

      <div className="allocation-visual" aria-hidden="true">
        <div className="allocation-donut">
          <svg viewBox="0 0 42 42">
            <circle
              className="allocation-donut__track"
              cx="21"
              cy="21"
              pathLength="100"
              r="15.9155"
            />
            {dashboardDemoData.allocations.map((entry) => (
              <circle
                className={`allocation-donut__segment ${entry.className}`}
                cx="21"
                cy="21"
                key={entry.labelKey}
                pathLength="100"
                r="15.9155"
                strokeDasharray={entry.dashArray}
                strokeDashoffset={entry.dashOffset}
              />
            ))}
          </svg>
          <span>{dashboardDemoData.allocations.length}</span>
          <small>{t('dashboard.allocation.classLabel')}</small>
        </div>
        <div className="allocation-stack">
          {dashboardDemoData.allocations.map((entry) => (
            <span className={entry.className} key={entry.labelKey} />
          ))}
        </div>
      </div>

      <ul className="allocation-list">
        {dashboardDemoData.allocations.map((entry) => (
          <li key={entry.labelKey}>
            <span className={`allocation-dot ${entry.className}`} />
            <span className="allocation-list__label">{t(entry.labelKey)}</span>
            <strong>{entry.percentage}</strong>
            <span className="money">{entry.value}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}
