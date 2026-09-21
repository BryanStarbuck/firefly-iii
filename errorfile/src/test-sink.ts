// Test helper, NOT vendored (pm/error_err.mdx §5.1): an in-memory sink for the library's own tests.
import type { ErrorRecord, ErrorSink } from './core.js';
import { formatRecord } from './format.js';

export type MemorySink = ErrorSink & {
  records: ErrorRecord[];
  lines: () => string[];
};

export function memorySink(app = 'ffx', verbose = false): MemorySink {
  const records: ErrorRecord[] = [];
  return {
    app,
    echo: false,
    verbose,
    records,
    write(record) {
      records.push(record);
    },
    flush() {
      // nothing buffered: the test sink is synchronous
    },
    lines() {
      return records.map((r) => formatRecord(r));
    },
  };
}
