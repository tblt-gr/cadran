/**
 * Deterministic generator of canonical decimal strings for property tests.
 * It only assembles digit strings: no Number ever carries a financial value.
 */
export const DEFAULT_PROPERTY_SEED = 539;
export const DEFAULT_PROPERTY_CASES = 500;

function environmentInteger(name: string, fallback: number): number {
  const env = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process
    ?.env;
  const raw = env?.[name];
  return raw !== undefined && /^-?\d+$/.test(raw) ? Number.parseInt(raw, 10) : fallback;
}

export function propertySeed(): number {
  return environmentInteger('PROPERTY_SEED', DEFAULT_PROPERTY_SEED);
}

export function propertyCases(): number {
  return Math.max(1, environmentInteger('PROPERTY_CASES', DEFAULT_PROPERTY_CASES));
}

export class DecimalGenerator {
  private state: number;

  constructor(seed: number) {
    this.state = seed >>> 0;
  }

  /** mulberry32: a 32-bit generator; its output only picks digits and lengths. */
  private next(): number {
    this.state = (this.state + 0x6d2b79f5) >>> 0;
    let t = this.state;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return (t ^ (t >>> 14)) >>> 0;
  }

  integer(min: number, max: number): number {
    return min + (this.next() % (max - min + 1));
  }

  decimal(maxIntegerDigits = 26, maxScale = 24, signed = true): string {
    const integer = this.digits(this.integer(1, maxIntegerDigits), true);
    const scale = this.integer(0, maxScale);
    const fraction = scale > 0 ? this.digits(scale, false).replace(/0+$/, '') : '';
    const literal = fraction === '' ? integer : `${integer}.${fraction}`;
    const isZero = !/[1-9]/.test(literal);

    return signed && !isZero && this.integer(0, 1) === 0 ? `-${literal}` : literal;
  }

  private digits(count: number, noLeadingZero: boolean): string {
    let digits = '';
    for (let index = 0; index < count; index += 1) {
      const low = noLeadingZero && count > 1 && index === 0 ? 1 : 0;
      digits += String(this.integer(low, 9));
    }
    return digits;
  }
}
