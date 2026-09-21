/**
 * The progress line — pm/cli.mdx §15.1. The CLI must never look hung.
 *
 * - One self-updating line on STDERR; never stdout.
 * - TTY-gated: piped stderr gets plain one-line status messages, no animation.
 * - --quiet suppresses both.
 * - The line is fully erased before any result, error or log tail prints.
 */

const FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

export interface ProgressOptions {
  quiet: boolean;
  isTTY: boolean;
}

function elapsed(ms: number): string {
  const s = Math.floor(ms / 1000);
  if (s < 60) return `${s}s`;
  return `${Math.floor(s / 60)}m${String(s % 60).padStart(2, '0')}s`;
}

export class Spinner {
  private timer: NodeJS.Timeout | undefined;
  private frame = 0;
  private phase = '';
  private count = '';
  private started = 0;
  private drawn = false;

  constructor(private readonly opts: ProgressOptions) {}

  start(phase: string): void {
    this.phase = phase;
    this.count = '';
    this.started = Date.now();
    if (this.opts.quiet) return;
    if (!this.opts.isTTY) {
      process.stderr.write(`${phase}\n`);
      return;
    }
    if (this.timer) return;
    this.timer = setInterval(() => this.draw(), 100);
    this.timer.unref();
    this.draw();
  }

  /** Update the phase text and, where a denominator is honest, a count. */
  tick(phase?: string, done?: number, total?: number): void {
    if (phase) this.phase = phase;
    this.count = done !== undefined ? (total !== undefined ? ` ${done}/${total}` : ` ${done}`) : this.count;
    if (!this.opts.quiet && !this.opts.isTTY && phase) process.stderr.write(`${phase}${this.count}\n`);
  }

  private draw(): void {
    const f = FRAMES[this.frame++ % FRAMES.length];
    const line = `${f} ${this.phase}${this.count}  ${elapsed(Date.now() - this.started)}`;
    process.stderr.write(`\r\x1b[2K${line}`);
    this.drawn = true;
  }

  /** Erase the line. Safe to call any number of times, including on failure paths. */
  stop(): void {
    if (this.timer) {
      clearInterval(this.timer);
      this.timer = undefined;
    }
    if (this.drawn) {
      process.stderr.write('\r\x1b[2K');
      this.drawn = false;
    }
  }
}
