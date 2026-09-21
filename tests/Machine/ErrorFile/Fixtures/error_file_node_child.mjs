// The Node writer of AC 7 — pm/error_err.mdx §4.4, §5.5, the §15 ConcurrencyTest row.
//
//   node error_file_node_child.mjs <compiled rolling-file-writer.js> <error file> <count> <max_bytes>
//
// Writes <count> synthetic `[ffx]` records (header plus six stack lines) through the library's own
// RollingFileWriter, at max_bytes and 5 backups, to the sandbox path the PHP test hands it, while four
// PHP children append and rotate the same file. Every record is synthetic. A failed write goes to
// stderr (the writer's fallback), which the parent asserts is empty.
import fs from 'node:fs';
import { pathToFileURL } from 'node:url';

const [, , modulePath, filePath, countArg, maxBytesArg] = process.argv;
const { RollingFileWriter } = await import(pathToFileURL(modulePath).href);
const writer = new RollingFileWriter({ filePath, maxBytes: Number(maxBytesArg), maxBackups: 5 });
const count = Number(countArg);
const stack = [];
for (let i = 1; i <= 6; i++) stack.push(`    at synthetic${'Deep'.repeat(30)} (cli/code/src/synthetic${i}.ts:${10 * i}:1)`);

// Start once the PHP children (which boot the application first) have begun appending, so the
// writers really overlap; give up waiting after 30 s and write anyway.
const deadline = Date.now() + 30_000;
while (!fs.existsSync(filePath) && Date.now() < deadline) await new Promise((resolve) => setTimeout(resolve, 5));

for (let r = 0; r < count; r++) {
  const word = String.fromCharCode(103 + (r % 20)).repeat(1 + Math.floor(r / 20));
  const header = `[${new Date().toISOString()}] [ERROR] [ffx] [cli/code/src/synthetic.ts] running ffx synthetic — Error: Synthetic node fault nd-${word} ${'x'.repeat(200)} {tag=nd end=yes}`;
  writer.write(`${header}\n${stack.join('\n')}\n`);
  await new Promise((resolve) => setTimeout(resolve, 4));
}
writer.flush();
