/**
 * Money, for DISPLAY only — pm/cli.mdx §13.3, apis.mdx §14.1.
 *
 * An amount is a decimal STRING with a currency code. This module never turns
 * one into a JavaScript number — not to format it, not to compare it, not to
 * sum it. Formatting is character work on the string: the digits the server
 * sent are the digits the operator sees, with separators inserted.
 *
 * A canary test greps the built output for the numeric-conversion functions
 * this file promises not to use.
 */

const DECIMAL = /^-?\d+(\.\d+)?$/;
/** What the CLI accepts as a transaction amount: positive, no sign, no separators, no exponent. */
const INPUT_AMOUNT = /^\d+(\.\d+)?$/;

export function isDecimalString(value: unknown): value is string {
  return typeof value === 'string' && DECIMAL.test(value);
}

/** Validate an operator-typed amount. Returns an error sentence, or undefined when fine. */
export function amountInputProblem(raw: string): string | undefined {
  if (INPUT_AMOUNT.test(raw)) return undefined;
  if (/^-/.test(raw)) {
    return `amounts are positive; the transaction type says which direction money moves (got "${raw}")`;
  }
  if (/,/.test(raw)) return `no thousands separators in amounts (got "${raw}", try "${raw.replace(/,/g, '')}")`;
  if (/^\$/.test(raw)) return `no currency symbol in amounts (got "${raw}", try "${raw.slice(1)}")`;
  if (/e/i.test(raw)) return `no exponent notation in amounts (got "${raw}")`;
  return `"${raw}" is not a decimal amount like "12.50"`;
}

/**
 * "1234567.891" → "1,234,567.891"; "-1234.5" → "-1,234.5". Anything that is
 * not a decimal string is returned untouched, so a server that sends something
 * unexpected is shown, never "corrected".
 */
export function formatAmount(value: string): string {
  if (!isDecimalString(value)) return value;
  const negative = value.startsWith('-');
  const body = negative ? value.slice(1) : value;
  const dot = body.indexOf('.');
  const intPart = dot === -1 ? body : body.slice(0, dot);
  const frac = dot === -1 ? '' : body.slice(dot);
  let grouped = '';
  for (let i = 0; i < intPart.length; i++) {
    const fromRight = intPart.length - i;
    grouped += intPart[i];
    if (fromRight > 1 && fromRight % 3 === 1) grouped += ',';
  }
  return `${negative ? '-' : ''}${grouped}${frac}`;
}

/**
 * Trim trailing zeros beyond a currency's decimal places for display, when the
 * server sent Firefly's 12-place storage form ("12.500000000000" → "12.50").
 * Pure string work; it never rounds — digits beyond `places` that are not
 * zero are kept, because hiding a real digit is worse than showing a long one.
 */
export function trimToPlaces(value: string, places = 2): string {
  if (!isDecimalString(value)) return value;
  const dot = value.indexOf('.');
  if (dot === -1) return places > 0 ? `${value}.${'0'.repeat(places)}` : value;
  let frac = value.slice(dot + 1);
  while (frac.length > places && frac.endsWith('0')) frac = frac.slice(0, -1);
  if (frac.length < places) frac = frac.padEnd(places, '0');
  return frac.length === 0 ? value.slice(0, dot) : `${value.slice(0, dot)}.${frac}`;
}

/** The display form used in tables: trimmed, grouped, with the currency beside it. */
export function displayAmount(value: unknown, currency?: unknown, places?: number): string {
  if (value === null || value === undefined) return '—';
  if (typeof value !== 'string') return String(value);
  const shown = formatAmount(trimToPlaces(value, places ?? 2));
  return typeof currency === 'string' && currency ? `${shown} ${currency}` : shown;
}

/**
 * Compare two decimal strings without converting them to numbers. Used only to
 * pick sparkline glyph heights — never to compute or display a figure.
 */
export function compareDecimal(a: string, b: string): number {
  // "-0.00" is zero, not a negative number below "0".
  const isZero = (x: string): boolean => /^-?0*(\.0*)?$/.test(x);
  const na = a.startsWith('-') && !isZero(a);
  const nb = b.startsWith('-') && !isZero(b);
  if (isZero(a) && a.startsWith('-')) a = a.slice(1);
  if (isZero(b) && b.startsWith('-')) b = b.slice(1);
  if (na !== nb) return na ? -1 : 1;
  const sign = na ? -1 : 1;
  const [ai = '', af = ''] = (na ? a.slice(1) : a).split('.');
  const [bi = '', bf = ''] = (nb ? b.slice(1) : b).split('.');
  const aInt = ai.replace(/^0+(?=\d)/, '');
  const bInt = bi.replace(/^0+(?=\d)/, '');
  if (aInt.length !== bInt.length) return sign * (aInt.length < bInt.length ? -1 : 1);
  if (aInt !== bInt) return sign * (aInt < bInt ? -1 : 1);
  const len = Math.max(af.length, bf.length);
  const aF = af.padEnd(len, '0');
  const bF = bf.padEnd(len, '0');
  if (aF === bF) return 0;
  return sign * (aF < bF ? -1 : 1);
}
