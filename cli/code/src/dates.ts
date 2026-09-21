/**
 * Relative dates — pm/cli.mdx §7.6.
 *
 * The wire never carries a relative date (apis.mdx §14.3). The CLI resolves a
 * small closed vocabulary to YYYY-MM-DD before the call and echoes the result
 * on stderr, so a call made at 23:59 on the 31st is auditable.
 */

export interface DateRange {
  start: string;
  end: string;
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const ISO_MONTH = /^\d{4}-\d{2}$/;

export const RELATIVE_WORDS = ['today', 'yesterday', 'this-month', 'last-month', 'this-year', 'last-year', 'ytd'] as const;

function pad(n: number): string {
  return String(n).padStart(2, '0');
}

export function iso(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function lastDayOfMonth(year: number, month0: number): Date {
  return new Date(year, month0 + 1, 0);
}

export function isValidIsoDate(value: string): boolean {
  if (!ISO_DATE.test(value)) return false;
  const [y, m, d] = value.split('-').map((p) => parseInt(p, 10)) as [number, number, number];
  const dt = new Date(y, m - 1, d);
  return dt.getFullYear() === y && dt.getMonth() === m - 1 && dt.getDate() === d;
}

/** Expand YYYY-MM to its first and last day. */
export function monthRange(month: string): DateRange | undefined {
  if (!ISO_MONTH.test(month)) return undefined;
  const [y, m] = month.split('-').map((p) => parseInt(p, 10)) as [number, number];
  if (m < 1 || m > 12) return undefined;
  return { start: `${month}-01`, end: iso(lastDayOfMonth(y, m - 1)) };
}

/** Resolve a relative word to a range (for --start/--end the caller picks the relevant end). */
export function relativeRange(word: string, now: Date = new Date()): DateRange | undefined {
  const y = now.getFullYear();
  const m = now.getMonth();
  switch (word) {
    case 'today':
      return { start: iso(now), end: iso(now) };
    case 'yesterday': {
      const d = new Date(y, m, now.getDate() - 1);
      return { start: iso(d), end: iso(d) };
    }
    case 'this-month':
      return { start: iso(new Date(y, m, 1)), end: iso(lastDayOfMonth(y, m)) };
    case 'last-month':
      return { start: iso(new Date(y, m - 1, 1)), end: iso(lastDayOfMonth(y, m - 1)) };
    case 'this-year':
      return { start: `${y}-01-01`, end: `${y}-12-31` };
    case 'last-year':
      return { start: `${y - 1}-01-01`, end: `${y - 1}-12-31` };
    case 'ytd':
      return { start: `${y}-01-01`, end: iso(now) };
    default:
      return undefined;
  }
}

/**
 * Resolve one --start / --end / --as-of value. A relative word resolves to the
 * start or the end of its range depending on which side it is used for; a
 * month (YYYY-MM) likewise.
 */
export function resolveDate(value: string, side: 'start' | 'end', now: Date = new Date()): string | undefined {
  if (isValidIsoDate(value)) return value;
  const month = monthRange(value);
  if (month) return month[side];
  const rel = relativeRange(value, now);
  if (rel) return rel[side];
  return undefined;
}

/** The last full calendar month — what "last month" means when no period is given. */
export function lastFullMonth(now: Date = new Date()): string {
  const d = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
}

export function currentMonth(now: Date = new Date()): string {
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
}
