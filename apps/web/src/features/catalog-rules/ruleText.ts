import type { ParseKeys } from 'i18next';

/**
 * Rule text values are uppercase tokens, so the regulatory wording lives in the
 * translation catalogue rather than in the database or in the API payload.
 *
 * The map is shared by every screen that renders a catalogue token — the
 * product catalogue and the rules of an account alike. Two copies would drift
 * the day a token is added, and one screen would then show the reader a raw
 * `NO_REGULATORY_CONTRIBUTION_CEILING` while the other showed the sentence.
 */
const ruleTextKeys = {
  NO_REGULATORY_CONTRIBUTION_CEILING: 'catalog.ruleTexts.NO_REGULATORY_CONTRIBUTION_CEILING',
} as const satisfies Record<string, ParseKeys>;

/**
 * The key stays the literal union the map declares rather than the whole
 * `ParseKeys`: `t()` only resolves to a plain string when it is handed a key it
 * can narrow, and widening here would make every call site fall back to the
 * detailed-result type.
 */
type RuleTextKey = (typeof ruleTextKeys)[keyof typeof ruleTextKeys];

/**
 * The translation key for a token, or null when this locale has not learned it
 * yet — in which case the caller shows the token as itself rather than as a
 * missing key.
 */
export function ruleTextKey(token: string): RuleTextKey | null {
  return ruleTextKeys[token as keyof typeof ruleTextKeys] ?? null;
}
