/**
 * Rendering — pm/cli.mdx §13. THE ONLY MODULE THAT WRITES TO STDOUT.
 *
 * stdout carries exactly one payload: the JSON, the table, the CSV, or a list
 * of lines. Everything else — progress, hints, provenance, the resolved
 * target — goes to stderr through note()/warn().
 *
 *   json   the plane's envelope, pretty-printed, unmodified
 *   table  box-drawn; amounts get separators and their currency (strings only)
 *   csv    RFC 4180; amounts exactly as the server sent them
 */
import type { Envelope } from './client.js';
import { displayAmount } from './money.js';

export type Format = 'json' | 'table' | 'csv';

export type CellKind = 'text' | 'amount' | 'id' | 'date' | 'bool' | 'list';

export interface Column {
  key: string;
  header: string;
  kind?: CellKind;
  /** For amounts: the row key holding the currency code (default "currency_code"). */
  currencyKey?: string;
  /** Right-align (amounts are right-aligned by default). */
  align?: 'left' | 'right';
}

export type Row = Record<string, unknown>;

export interface View {
  rows: Row[];
  columns: Column[];
  /** Lines printed to stderr after a table (provenance, truncation, totals the server returned). */
  notes?: string[];
  /** Printed above the table on stderr. */
  title?: string;
}

/** What a verb hands back to main for rendering. */
export interface Outcome {
  /** The server envelope, for --format json. */
  envelope?: Envelope<unknown>;
  /** How to show it as a table/csv. Absent → a generic rendering of envelope.data. */
  view?: View;
  /** Plain lines for stdout, used by verbs whose answer is a list of lines in every format but json. */
  lines?: string[];
  /** A free-form JSON value for --format json when there is no envelope (local-only verbs). */
  json?: unknown;
  /** Exit code override (e.g. doctor failing). */
  exit?: number;
  /** The lines are plain text in EVERY format (help, a log tail, a CSV export) — never wrapped in JSON. */
  plain?: boolean;
}

// ---------------------------------------------------------------- output ---

export function out(text: string): void {
  process.stdout.write(text.endsWith('\n') ? text : `${text}\n`);
}

export function note(text: string, quiet = false): void {
  if (!quiet) process.stderr.write(`${text}\n`);
}

export function warn(text: string): void {
  process.stderr.write(`${text}\n`);
}

// ----------------------------------------------------------------- cells ---

function asText(value: unknown): string {
  if (value === null || value === undefined) return '';
  if (typeof value === 'string') return value;
  if (typeof value === 'boolean') return value ? 'yes' : 'no';
  if (Array.isArray(value)) return value.map(asText).join(', ');
  if (typeof value === 'object') {
    const named = (value as { name?: unknown; title?: unknown; tag?: unknown }).name ?? (value as { title?: unknown }).title ?? (value as { tag?: unknown }).tag;
    if (typeof named === 'string') return named;
    return JSON.stringify(value);
  }
  return String(value);
}

export function cellText(row: Row, col: Column, format: Format): string {
  const value = getPath(row, col.key);
  if (col.kind === 'amount') {
    if (format === 'csv') return value === null || value === undefined ? '' : asText(value);
    return displayAmount(value, getPath(row, col.currencyKey ?? 'currency_code'));
  }
  if (col.kind === 'bool') return value === undefined ? '' : value ? 'yes' : 'no';
  if (format === 'table' && (value === null || value === undefined) && col.kind !== 'text') return '—';
  return asText(value);
}

/** Read a dotted path ("meta.name", "limits.0.amount") from a row. */
export function getPath(row: unknown, key: string): unknown {
  let cur: unknown = row;
  for (const part of key.split('.')) {
    if (cur === null || cur === undefined || typeof cur !== 'object') return undefined;
    cur = (cur as Record<string, unknown>)[part];
  }
  return cur;
}

// ----------------------------------------------------------------- table ---

/** Display width, counting a few wide glyphs as one (good enough for our content). */
function width(s: string): number {
  return [...s].length;
}

function padCell(s: string, w: number, align: 'left' | 'right'): string {
  const gap = w - width(s);
  if (gap <= 0) return s;
  return align === 'right' ? ' '.repeat(gap) + s : s + ' '.repeat(gap);
}

export function renderTable(view: View): string {
  const cols = view.columns;
  if (view.rows.length === 0) return '(no rows)';
  const cells = view.rows.map((row) => cols.map((c) => cellText(row, c, 'table').replace(/\s*\n\s*/g, ' ')));
  const maxCell = 60;
  const widths = cols.map((c, i) =>
    Math.min(maxCell, Math.max(width(c.header), ...cells.map((r) => width(r[i] ?? '')))),
  );
  const clip = (s: string, w: number): string => (width(s) > w ? [...s].slice(0, w - 1).join('') + '…' : s);
  const aligns = cols.map((c) => c.align ?? (c.kind === 'amount' ? 'right' : 'left'));
  const line = (l: string, m: string, r: string): string => l + widths.map((w) => '─'.repeat(w + 2)).join(m) + r;
  const rowLine = (vals: string[]): string =>
    '│' + vals.map((v, i) => ` ${padCell(clip(v, widths[i] ?? 0), widths[i] ?? 0, aligns[i] ?? 'left')} `).join('│') + '│';
  const parts = [line('┌', '┬', '┐'), rowLine(cols.map((c) => c.header)), line('├', '┼', '┤')];
  for (const r of cells) parts.push(rowLine(r));
  parts.push(line('└', '┴', '┘'));
  return parts.join('\n');
}

// ------------------------------------------------------------------- csv ---

function csvField(s: string): string {
  return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

export function renderCsv(view: View): string {
  const head = view.columns.map((c) => csvField(c.key)).join(',');
  const body = view.rows.map((row) => view.columns.map((c) => csvField(cellText(row, c, 'csv'))).join(','));
  return [head, ...body].join('\n');
}

// --------------------------------------------------------------- generic ---

/** The first array in an object's values — list routes return { accounts: [...] }. */
export function firstArray(data: unknown): { key: string; rows: Row[] } | undefined {
  if (Array.isArray(data)) return { key: '', rows: data as Row[] };
  if (data && typeof data === 'object') {
    for (const [k, v] of Object.entries(data)) {
      if (Array.isArray(v) && v.every((x) => x !== null && typeof x === 'object')) return { key: k, rows: v as Row[] };
    }
  }
  return undefined;
}

const AMOUNT_KEY = /(^|_)(amount|balance|spent|earned|left|limit|available|total|budgeted|income|expense|net|sum|difference|opening|closing|in|out|target|saved|annualised|liquid|assets|liabilities)$/;

/** Columns inferred from the scalar keys of the rows — the fallback when a verb gives no view. */
export function inferColumns(rows: Row[]): Column[] {
  const keys: string[] = [];
  for (const row of rows.slice(0, 50)) {
    for (const [k, v] of Object.entries(row)) {
      if (keys.includes(k)) continue;
      if (v !== null && typeof v === 'object' && !Array.isArray(v)) continue;
      keys.push(k);
    }
  }
  return keys.slice(0, 10).map((k) => ({
    key: k,
    header: k,
    kind: AMOUNT_KEY.test(k) && rows.some((r) => typeof r[k] === 'string' && /^-?\d+(\.\d+)?$/.test(r[k] as string)) ? 'amount' : 'text',
  }));
}

/** A key/value view of a single object. */
export function objectView(obj: Record<string, unknown>): View {
  const rows: Row[] = Object.entries(obj)
    .filter(([, v]) => v === null || typeof v !== 'object' || Array.isArray(v))
    .map(([field, value]) => ({ field, value: Array.isArray(value) ? value.map(asText).join(', ') : value }));
  return { rows, columns: [{ key: 'field', header: 'field' }, { key: 'value', header: 'value' }] };
}

export function genericView(data: unknown): View {
  const arr = firstArray(data);
  if (arr) return { rows: arr.rows, columns: inferColumns(arr.rows) };
  if (data && typeof data === 'object') return objectView(data as Record<string, unknown>);
  return { rows: [{ value: data }], columns: [{ key: 'value', header: 'value' }] };
}

/** Standard stderr notes every plane answer earns: which books, as of when, truncation. */
export function metaNotes(envelope: Envelope<unknown> | undefined): string[] {
  const m = envelope?.meta;
  if (!m) return [];
  const notes: string[] = [];
  const books = m.administrationName ? `"${m.administrationName}"${m.administrationId !== undefined ? ` (#${m.administrationId})` : ''}` : undefined;
  const bits = [books && `administration ${books}`, m.asOf && `as of ${m.asOf}`].filter(Boolean);
  if (bits.length) notes.push(bits.join(' · '));
  if (m.truncated) {
    notes.push(`TRUNCATED: a cap bound this result${m.limit_applied !== undefined ? ` (limit ${m.limit_applied})` : ''} — there are more rows. Narrow the range or page with --offset.`);
  }
  if (m.replayed) notes.push('replayed: this is the original response to an earlier call with the same idempotency key');
  return notes;
}

// ---------------------------------------------------------------- render ---

export interface RenderOptions {
  format: Format;
  quiet: boolean;
}

export function render(outcome: Outcome, opts: RenderOptions): void {
  if (outcome.plain && outcome.lines) {
    if (outcome.lines.length) out(outcome.lines.join('\n'));
    return;
  }
  if (opts.format === 'json') {
    const payload = outcome.envelope ?? outcome.json ?? (outcome.lines ? { ok: true, data: { lines: outcome.lines } } : { ok: true });
    out(JSON.stringify(payload, null, 2));
    return;
  }
  if (outcome.lines) {
    if (outcome.lines.length) out(outcome.lines.join('\n'));
    for (const n of metaNotes(outcome.envelope)) note(n, opts.quiet);
    return;
  }
  const view = outcome.view ?? (outcome.envelope ? genericView(outcome.envelope.data) : outcome.json !== undefined ? genericView(outcome.json) : undefined);
  if (!view) return;
  if (opts.format === 'csv') {
    out(renderCsv(view));
    return;
  }
  if (view.title) note(view.title, opts.quiet);
  out(renderTable(view));
  for (const n of [...(view.notes ?? []), ...metaNotes(outcome.envelope)]) note(n, opts.quiet);
}
