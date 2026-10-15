import { Icon, type IconName } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import styles from './MetricCard.module.css';

type MetricTone = 'negative' | 'positive' | 'warning';
type MetricValue =
  { kind: 'loading' } | { kind: 'money'; text: string } | { kind: 'unavailable'; text: string };

interface MetricCardProps {
  description: string;
  icon: IconName;
  id: string;
  title: string;
  tone: MetricTone;
  value: MetricValue;
}

export function MetricCard({ description, icon, id, title, tone, value }: MetricCardProps) {
  return (
    <section className={`card ${styles.card}`} aria-labelledby={id}>
      <h2 className={styles.label} id={id}>
        <span className={`${styles.mark} ${styles[tone]}`}>
          <Icon name={icon} size={16} />
        </span>
        {title}
      </h2>
      {value.kind === 'loading' ? (
        <SkeletonBlock className={styles.valueSkeleton} data-testid="metric-skeleton" />
      ) : (
        <p
          className={value.kind === 'money' ? `money ${styles.value}` : styles.unavailable}
          data-visual-mask
        >
          {value.text}
        </p>
      )}
      <p className={styles.description} data-visual-mask>
        {description}
      </p>
    </section>
  );
}
