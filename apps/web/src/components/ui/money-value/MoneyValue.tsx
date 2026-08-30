import styles from './MoneyValue.module.css';

interface MoneyValueProps {
  className?: string;
  value: string;
}

export function MoneyValue({ className, value }: MoneyValueProps) {
  const groups = value.split('\u202f');

  return (
    <span className={className}>
      {groups.map((group, index) =>
        index === groups.length - 1 ? (
          <span className={styles.tail} key={group}>
            {group}
          </span>
        ) : (
          <span key={`${group}-${index}`}>
            {group}
            {'\u202f'}
            <wbr />
          </span>
        ),
      )}
    </span>
  );
}
