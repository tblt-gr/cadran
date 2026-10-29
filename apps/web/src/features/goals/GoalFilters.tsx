import { useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import styles from './GoalFilters.module.css';

interface Props {
  year: number;
  month: string;
  /** The current workspace-local month (`YYYY-MM`), used to cap the month picker. */
  currentMonth: string;
  onYearChange: (year: number) => void;
  onMonthChange: (month: string) => void;
  includeClosed: boolean;
  onIncludeClosedChange: (checked: boolean) => void;
}
const MONTH_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/;

/**
 * A month only reaches the query once it is a well-formed month of the displayed year that has
 * already started; an emptied, partial or out-of-range entry would make the server reject the
 * whole list, so it stays local and flagged while the last valid month keeps driving the query.
 */
function isSelectableMonth(value: string, year: number, currentMonth: string): boolean {
  return MONTH_PATTERN.test(value) && value.startsWith(`${year}-`) && value <= currentMonth;
}

/**
 * Only the goals of the current year and month show by default; another period and the closed
 * goals sit behind one header button that carries the number of filters changed from that default.
 * The year only reaches the query once it is a plausible four-digit year; until then it is flagged.
 */
export function GoalFilters({
  year,
  month,
  currentMonth,
  includeClosed,
  onYearChange,
  onMonthChange,
  onIncludeClosedChange,
}: Props) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const countId = useId();
  const trigger = useRef<HTMLButtonElement>(null);
  const [yearText, setYearText] = useState(String(year));
  // The rejected entry is tied to the month it was typed over, so a year change that resets the
  // month also discards it.
  const [monthDraft, setMonthDraft] = useState<{ text: string; over: string } | null>(null);
  const monthText = monthDraft?.over === month ? monthDraft.text : month;
  const currentYear = Number.parseInt(currentMonth.slice(0, 4), 10);
  const monthMin = `${year}-01`;
  const monthMax = year === currentYear ? currentMonth : `${year}-12`;
  // A future year has no elapsed month yet: the server picks its own reference month.
  const futureYear = year > currentYear;
  const activeCount =
    Number(includeClosed) + Number(year !== currentYear) + Number(month !== currentMonth);
  return (
    <>
      <HeaderActions>
        <button
          aria-describedby={activeCount > 0 ? countId : undefined}
          aria-expanded={open}
          aria-haspopup="dialog"
          aria-label={t('goals.filtersOpen')}
          className={`icon-button ${styles.trigger}`}
          onClick={() => setOpen(true)}
          ref={trigger}
          title={t('goals.filtersOpen')}
          type="button"
        >
          <Icon name="filter" />
          {activeCount > 0 ? (
            <>
              <span aria-hidden="true" className={styles.badge}>
                {activeCount}
              </span>
              <span className="sr-only" id={countId}>
                {t('goals.filtersActive', { count: activeCount })}
              </span>
            </>
          ) : null}
        </button>
      </HeaderActions>
      {open ? (
        <Modal close={() => setOpen(false)} returnFocus={trigger} title={t('goals.filtersTitle')}>
          <div className={styles.fields}>
            <label>
              <span>{t('goals.year')}</span>
              <input
                aria-invalid={yearText !== String(year) ? true : undefined}
                inputMode="numeric"
                maxLength={4}
                onChange={(event) => {
                  setYearText(event.target.value);
                  if (/^\d{4}$/.test(event.target.value)) {
                    const next = Number.parseInt(event.target.value, 10);
                    if (next >= 1900 && next <= 2999) onYearChange(next);
                  }
                }}
                value={yearText}
              />
            </label>
            <label>
              <span>{t('goals.month')}</span>
              <input
                aria-invalid={monthText !== month ? true : undefined}
                disabled={futureYear}
                max={monthMax}
                min={monthMin}
                onChange={(event) => {
                  const next = event.target.value;
                  if (isSelectableMonth(next, year, currentMonth)) {
                    setMonthDraft(null);
                    onMonthChange(next);
                  } else {
                    setMonthDraft({ text: next, over: month });
                  }
                }}
                type="month"
                value={monthText}
              />
            </label>
            <label className={styles.check}>
              <input
                checked={includeClosed}
                onChange={(event) => onIncludeClosedChange(event.target.checked)}
                type="checkbox"
              />
              <span>{t('goals.includeClosed')}</span>
            </label>
          </div>
        </Modal>
      ) : null}
    </>
  );
}
