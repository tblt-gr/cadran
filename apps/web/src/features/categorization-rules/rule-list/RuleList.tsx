import type { CategorizationRule } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { RuleRow } from './RuleRow';
import styles from './RuleList.module.css';

interface RuleListProps {
  onArchive: (rule: CategorizationRule) => void;
  onEdit: (rule: CategorizationRule) => void;
  onPreview: (rule: CategorizationRule) => void;
  onToggle: (rule: CategorizationRule) => void;
  rules: CategorizationRule[];
}

export function RuleList({ onArchive, onEdit, onPreview, onToggle, rules }: RuleListProps) {
  const { t } = useTranslation();
  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">{t('categorizationRules.list.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('categorizationRules.list.priority')}</th>
              <th scope="col">{t('categorizationRules.list.label')}</th>
              <th scope="col">{t('categorizationRules.list.period')}</th>
              <th scope="col">{t('categorizationRules.list.scope')}</th>
              <th scope="col">{t('categorizationRules.list.applied')}</th>
              <th scope="col">{t('categorizationRules.list.status')}</th>
              <th scope="col">
                <span className="sr-only">{t('categorizationRules.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {rules.map((rule) => (
              <RuleRow
                key={rule.id}
                onArchive={onArchive}
                onEdit={onEdit}
                onPreview={onPreview}
                onToggle={onToggle}
                rule={rule}
              />
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
