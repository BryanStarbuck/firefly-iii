#!/usr/bin/env node
// The TypeScript/JavaScript half of the error-file coverage check — pm/error_err.mdx §13.2.
// `just check-errors` runs it after scripts/error-file-coverage.php.
//
// It parses with the TypeScript compiler API loaded from cli/node_modules/typescript. That install is
// TypeScript 7 (the native compiler), whose JavaScript API has no ts.createSourceFile(): the only way
// to get a syntax tree is a Program over a project. So the script opens ONE virtual project (a
// tsconfig that exists only in memory: allowJs, noResolve, noLib, no types) holding exactly the files
// in scope, and walks each file's tree. No type is ever computed and nothing is emitted — it still
// runs in well under a second. (Deviation from §13.2's "createSourceFile only, no Program", recorded
// in the build's deviations log.)
//
// SCOPE (R1, V9)
//   enforced     cli/code/src (minus vendor/), mcp/src (minus vendor/ and canary/), and the browser glue
//                resources/assets/v3/js/support/error-file-app.js
//   upstream v3  every other file under resources/assets/v3/js — minus the browser core
//                support/error-file.js, which is the library (excluded, like errorfile/src and every
//                vendor/error-file/ copy)
//
// ERROR SITES: a `catch` clause, `.catch(fn)`, `.then(_, fn)`, `addEventListener('error' |
// 'unhandledrejection', fn)` and `onerror =`. An enforced site is compliant when its handler body (not
// counting nested functions) holds: a call on `errors` or a name ending in `Errors`; one of the
// wrappers tryOr, tryOrAsync, reportRejection, guard; a `throw`; `Promise.reject(…)`; or a call to a
// name ending in `OrThrow`. On top of that, over every call in enforced files:
//   wrongWhere    errorFileFor('<x>') is not the file's own repo-relative path (R14)
//   dynamicDoing  `doing` is not a string literal
//   badDoing      the literal fails /^[a-z][A-Za-z0-9 ,'-]*[A-Za-z0-9]$/ (R2)
//   ledgerKey     a literal data key matches LEDGER_KEY (read from app/Machine/ErrorFile/Redactor.php)
//   unreported    the site has none of the compliant forms
//
// UPSTREAM v3 gets a count ceiling (V6): scripts/error-file-coverage.upstream-v3.json maps
// { "<file>": <catch-site count> }. At or below its entry a file is `upstream-net-covered` (N18/N19
// cover it, Pattern 9); a higher count, or a new upstream file with any site, is `violating`.
//
// SESSION VIEWS (informational, E11): every Blade view that extends layout.v3.session and has no
// @vite('…js/pages…') entry, with the change since the previous run.
//
// WIRED RUNTIMES: ffx and mcp (index.ts imports ./error-file-install.js, which calls
// installNodeErrorFile(), N11/N15) and web (both boot files carry `// fork: pm/error_err.mdx`, N18/N19).
// An unwired runtime is a hard failure.
//
// Output: error_file_coverage.json next to the error file (FIREFLY_ERROR_FILE's directory, else
// ~/T/firefly/), keys `js` and `runtimes` merged into what the PHP half wrote. Never error.err, never
// anything inside the repo — except `--update-ceiling`, which rewrites the committed ceiling file to
// today's counts and is run by hand, after confirming the nets cover the new sites.
//
//   node scripts/error-file-coverage.mjs                   the report + exit code
//   node scripts/error-file-coverage.mjs --quiet           totals and violations only
//   node scripts/error-file-coverage.mjs --update-ceiling  rewrite scripts/error-file-coverage.upstream-v3.json

import { existsSync, mkdirSync, readdirSync, readFileSync, renameSync, unlinkSync, writeFileSync } from 'node:fs';
import { homedir, tmpdir, userInfo } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const quiet = process.argv.includes('--quiet');
const updateCeiling = process.argv.includes('--update-ceiling');

const CEILING_FILE = 'scripts/error-file-coverage.upstream-v3.json';
const GLUE = 'resources/assets/v3/js/support/error-file-app.js';
const BROWSER_CORE = 'resources/assets/v3/js/support/error-file.js';
const V3_ROOT = 'resources/assets/v3/js';
const DOING_RE = /^[a-z][A-Za-z0-9 ,'-]*[A-Za-z0-9]$/;
const SITE_METHODS = new Set(['caught', 'warn', 'expected', 'rethrow', 'fatal']);
const DATA_ARG = { caught: 2, warn: 2, rethrow: 2, fatal: 2 };
const WRAPPERS = new Set(['tryOr', 'tryOrAsync', 'reportRejection', 'guard']);
const MARKER = '// fork: pm/error_err.mdx';

// ---------------------------------------------------------------------------------------------
// The compiler
// ---------------------------------------------------------------------------------------------

const tsRoot = join(root, 'cli', 'node_modules', 'typescript');
if (!existsSync(join(tsRoot, 'dist', 'api', 'sync', 'api.js'))) {
  process.stderr.write(
    'error-file coverage: the TypeScript compiler API is not installed at cli/node_modules/typescript\n' +
      '  fix: cd cli && npm install   (then: just check-errors)\n',
  );
  process.exit(2);
}
const { API } = await import(pathToFileURL(join(tsRoot, 'dist', 'api', 'sync', 'api.js')).href);
const { SyntaxKind } = await import(pathToFileURL(join(tsRoot, 'dist', 'ast', 'index.js')).href);
const { computeLineStarts } = await import(pathToFileURL(join(tsRoot, 'dist', 'ast', 'scanner.js')).href);

// ---------------------------------------------------------------------------------------------
// Scope
// ---------------------------------------------------------------------------------------------

function walk(relDir, keep, out = []) {
  const abs = join(root, relDir);
  if (!existsSync(abs)) return out;
  for (const entry of readdirSync(abs, { withFileTypes: true })) {
    const rel = `${relDir}/${entry.name}`;
    if (entry.isDirectory()) {
      if (entry.name !== 'node_modules') walk(rel, keep, out);
    } else if (entry.isFile() && keep(rel)) {
      out.push(rel);
    }
  }
  return out;
}

const isTs = (rel) => rel.endsWith('.ts') && !rel.endsWith('.d.ts');
const enforced = [
  ...walk('cli/code/src', (rel) => isTs(rel) && !rel.startsWith('cli/code/src/vendor/')),
  ...walk('mcp/src', (rel) => isTs(rel) && !rel.startsWith('mcp/src/vendor/') && !rel.startsWith('mcp/src/canary/')),
  ...(existsSync(join(root, GLUE)) ? [GLUE] : []),
].sort();
const upstreamV3 = walk(V3_ROOT, (rel) => rel.endsWith('.js') && rel !== BROWSER_CORE && rel !== GLUE).sort();

function ledgerKeyRegex() {
  const php = readFileSync(join(root, 'app/Machine/ErrorFile/Redactor.php'), 'utf8');
  const m = php.match(/const string LEDGER_KEY = '\/(.+)\/i';/);
  if (!m) throw new Error('could not read LEDGER_KEY from app/Machine/ErrorFile/Redactor.php');
  return new RegExp(m[1], 'i');
}
const LEDGER_KEY = ledgerKeyRegex();

// ---------------------------------------------------------------------------------------------
// Parse — one in-memory project, no type work
// ---------------------------------------------------------------------------------------------

const all = [...enforced, ...upstreamV3];
const absOf = (rel) => join(root, rel);
const virtualConfig = join(root, '.error-file-coverage.tsconfig.json');   // never on disk
const api = new API({
  cwd: root,
  fs: {
    readFile: (f) =>
      f === virtualConfig
        ? JSON.stringify({
            compilerOptions: { allowJs: true, checkJs: false, noResolve: true, noLib: true, types: [], noEmit: true },
            files: all.map(absOf),
          })
        : undefined,
    fileExists: (f) => (f === virtualConfig ? true : undefined),
  },
});

let program;
try {
  const snapshot = api.updateSnapshot({ openProjects: [virtualConfig] });
  program = snapshot.getProject(virtualConfig)?.program;
  if (!program) throw new Error('the TypeScript API returned no project');
} catch (err) {
  api.close();
  process.stderr.write(`error-file coverage: the TypeScript compiler API failed: ${err?.message ?? err}\n`);
  process.exit(2);
}

// ---------------------------------------------------------------------------------------------
// AST helpers
// ---------------------------------------------------------------------------------------------

const K = SyntaxKind;
const FUNCTIONS = new Set([K.ArrowFunction, K.FunctionExpression, K.FunctionDeclaration, K.MethodDeclaration, K.ClassDeclaration, K.ClassExpression]);

function children(node) {
  const out = [];
  node.forEachChild((c) => {
    out.push(c);
  });
  return out;
}

/** Visit node and descendants, not descending into nested functions or classes. */
function walkBody(node, fn) {
  fn(node);
  for (const c of children(node)) {
    if (!FUNCTIONS.has(c.kind)) walkBody(c, fn);
    else fn(c); // let the visitor see the function node itself, but not inside it
  }
}

function walkAll(node, fn) {
  fn(node);
  for (const c of children(node)) walkAll(c, fn);
}

const isString = (n) => n && (n.kind === K.StringLiteral || n.kind === K.NoSubstitutionTemplateLiteral);
const identName = (n) => (n && n.kind === K.Identifier ? n.text : null);
const propName = (n) => (n && n.kind === K.PropertyAccessExpression ? identName(n.name) : null);
const isErrorsName = (name) => name === 'errors' || (typeof name === 'string' && name.endsWith('Errors'));

/** The name a call calls: `f(…)` → f, `a.b.f(…)` → f. */
function calleeName(call) {
  const e = call.expression;
  return identName(e) ?? propName(e);
}

/** A call on `errors` / `*Errors`: returns the method name, else null. */
function errorsMethod(call) {
  const e = call.expression;
  if (e?.kind !== K.PropertyAccessExpression) return null;
  return isErrorsName(identName(e.expression)) ? identName(e.name) : null;
}

function isAccepted(node) {
  if (node.kind === K.ThrowStatement) return true;
  if (node.kind !== K.CallExpression) return false;
  if (errorsMethod(node) !== null) return true;
  const name = calleeName(node);
  if (name && WRAPPERS.has(name) && identName(node.expression) === name) return true;
  if (name && name.endsWith('OrThrow')) return true;
  const e = node.expression;
  if (e?.kind === K.PropertyAccessExpression && identName(e.expression) === 'Promise' && identName(e.name) === 'reject') return true;
  return false;
}

/** Does the handler (a catch block, or a function passed as a handler) report? */
function handlerReports(handler) {
  if (!handler) return false;
  let body = handler;
  if (handler.kind === K.ArrowFunction || handler.kind === K.FunctionExpression) body = handler.body;
  else if (handler.kind !== K.Block) return false; // an identifier or other value: cannot be proven
  let ok = false;
  walkBody(body, (n) => {
    if (!ok && !FUNCTIONS.has(n.kind) && isAccepted(n)) ok = true;
  });
  return ok;
}

/** The error sites of a file: { line, form, handler }. */
function sitesOf(sf, lineOf) {
  const sites = [];
  walkAll(sf, (n) => {
    if (n.kind === K.CatchClause) {
      sites.push({ line: lineOf(n), form: 'catch', handler: n.block });
    } else if (n.kind === K.CallExpression) {
      const e = n.expression;
      const name = propName(e);
      const args = n.arguments ?? [];
      if (name === 'catch' && args.length >= 1) sites.push({ line: lineOf(n), form: '.catch()', handler: args[0] });
      else if (name === 'then' && args.length >= 2) sites.push({ line: lineOf(n), form: '.then(_, fn)', handler: args[1] });
      else if (
        name === 'addEventListener' &&
        args.length >= 2 &&
        isString(args[0]) &&
        (args[0].text === 'error' || args[0].text === 'unhandledrejection')
      ) {
        sites.push({ line: lineOf(n), form: `addEventListener('${args[0].text}')`, handler: args[1] });
      }
    } else if (n.kind === K.BinaryExpression && n.operatorToken?.kind === K.EqualsToken && propName(n.left) === 'onerror') {
      sites.push({ line: lineOf(n), form: 'onerror =', handler: n.right });
    }
  });
  return sites;
}

function lineMapper(sf) {
  const starts = computeLineStarts(sf.text);
  return (node) => {
    const pos = node.getStart(sf);
    let lo = 0;
    let hi = starts.length - 1;
    while (lo < hi) {
      const mid = (lo + hi + 1) >> 1;
      if (starts[mid] <= pos) lo = mid;
      else hi = mid - 1;
    }
    return lo + 1;
  };
}

// ---------------------------------------------------------------------------------------------
// Scan
// ---------------------------------------------------------------------------------------------

const violations = [];
const files = [];
const parseErrors = [];
let enforcedSites = 0;
let compliantSites = 0;

for (const rel of enforced) {
  const sf = program.getSourceFile(absOf(rel));
  if (!sf) {
    parseErrors.push(rel);
    continue;
  }
  const lineOf = lineMapper(sf);
  const before = violations.length;
  const sites = sitesOf(sf, lineOf);
  enforcedSites += sites.length;
  for (const site of sites) {
    if (handlerReports(site.handler)) compliantSites++;
    else violations.push({ file: rel, line: site.line, kind: 'unreported', message: `${site.form} neither reports (errors.*, a wrapper), rethrows nor rejects (R1)` });
  }
  // the call-site checks, on every call in the file
  walkAll(sf, (n) => {
    if (n.kind !== K.CallExpression) return;
    const args = n.arguments ?? [];
    if (identName(n.expression) === 'errorFileFor') {
      if (!isString(args[0])) violations.push({ file: rel, line: lineOf(n), kind: 'wrongWhere', message: 'errorFileFor() is not given a string literal' });
      else if (args[0].text !== rel)
        violations.push({ file: rel, line: lineOf(n), kind: 'wrongWhere', message: `errorFileFor('${args[0].text}') is not this file's repo-relative path '${rel}' (R14)` });
      return;
    }
    const method = errorsMethod(n);
    const wrapper = WRAPPERS.has(identName(n.expression) ?? '') ? identName(n.expression) : null;
    if (!(method && SITE_METHODS.has(method)) && !wrapper) return;
    const label = method ? `errors.${method}()` : `${wrapper}()`;
    const doing = method ? args[0] : args[1];
    if (!isString(doing)) violations.push({ file: rel, line: lineOf(n), kind: 'dynamicDoing', message: `${label}: doing is not a string literal` });
    else if (!DOING_RE.test(doing.text))
      violations.push({ file: rel, line: lineOf(n), kind: 'badDoing', message: `${label}: '${doing.text}' is not a gerund phrase with no trailing period (R2)` });
    const data = method && DATA_ARG[method] !== undefined ? args[DATA_ARG[method]] : undefined;
    if (data?.kind === K.ObjectLiteralExpression) {
      for (const p of data.properties ?? []) {
        const key = identName(p.name) ?? (isString(p.name) ? p.name.text : null);
        if (key && LEDGER_KEY.test(key))
          violations.push({ file: rel, line: lineOf(p), kind: 'ledgerKey', message: `${label}: the data key '${key}' is a ledger field (§12)` });
      }
    }
  });
  const mine = violations.length - before;
  files.push({ file: rel, scope: 'enforced', sites: sites.length, violations: mine, class: mine > 0 ? 'violating' : sites.length > 0 ? 'compliant' : 'net-covered' });
}

// the upstream v3 ceiling
let ceiling = {};
try {
  ceiling = JSON.parse(readFileSync(join(root, CEILING_FILE), 'utf8'));
} catch {
  ceiling = {};
}
const counts = {};
const v3Violations = [];
const v3Notes = [];
for (const rel of upstreamV3) {
  const sf = program.getSourceFile(absOf(rel));
  if (!sf) {
    parseErrors.push(rel);
    continue;
  }
  const n = sitesOf(sf, lineMapper(sf)).length;
  if (n > 0) counts[rel] = n;
  const limit = ceiling[rel];
  let cls = n > 0 ? 'upstream-net-covered' : 'net-covered';
  if (n > 0 && limit === undefined) {
    cls = 'violating';
    v3Violations.push({ file: rel, count: n, ceiling: null, message: `a new upstream v3 file with ${n} catch site(s): confirm N18/N19 cover them (Pattern 9), then add "${rel}": ${n} to ${CEILING_FILE}` });
  } else if (n > (limit ?? 0)) {
    cls = 'violating';
    v3Violations.push({ file: rel, count: n, ceiling: limit, message: `${n} catch sites, over its ceiling of ${limit}: confirm N18/N19 cover the new site(s) (Pattern 9), then raise the entry to ${n}` });
  } else if (limit !== undefined && n < limit) {
    v3Notes.push(`${rel}: ${n} catch site(s), below its ceiling of ${limit} — lower the entry to keep the ceiling exact`);
  }
  files.push({ file: rel, scope: 'upstream-v3', sites: n, ceiling: limit ?? null, class: cls });
}
for (const rel of Object.keys(ceiling)) {
  if (!upstreamV3.includes(rel)) v3Notes.push(`${rel}: in the ceiling but no longer an upstream v3 file — drop the entry`);
}
api.close();

if (updateCeiling) {
  const sorted = Object.fromEntries(Object.keys(counts).sort().map((k) => [k, counts[k]]));
  writeFileSync(join(root, CEILING_FILE), JSON.stringify(sorted, null, 4) + '\n');
  process.stdout.write(`wrote ${CEILING_FILE}: ${Object.values(counts).reduce((a, b) => a + b, 0)} sites in ${Object.keys(counts).length} files\n`);
  process.exit(0);
}

// the session views with no bundle (E11), informational
function sessionViews() {
  const out = [];
  for (const rel of walk('resources/views', (r) => r.endsWith('.blade.php'))) {
    const text = readFileSync(join(root, rel), 'utf8');
    if (!/@extends\(\s*['"]layout\.v3\.session['"]/.test(text)) continue;
    if (!/@vite\(\s*\[?[^)]*js\/pages/s.test(text)) out.push(rel);
  }
  return out.sort();
}
const views = sessionViews();

// ---------------------------------------------------------------------------------------------
// Wired runtimes (§13.2)
// ---------------------------------------------------------------------------------------------

const read = (rel) => {
  try {
    return readFileSync(join(root, rel), 'utf8');
  } catch {
    return '';
  }
};
const nodeWired = (dir) =>
  /import\s+['"]\.\/error-file-install\.js['"]/.test(read(`${dir}/index.ts`)) && read(`${dir}/error-file-install.ts`).includes('installNodeErrorFile(');
const runtimes = {
  ffx: { file: 'cli/code/src/index.ts', marker: "import './error-file-install.js' → installNodeErrorFile(", wired: nodeWired('cli/code/src') },
  mcp: { file: 'mcp/src/index.ts', marker: "import './error-file-install.js' → installNodeErrorFile(", wired: nodeWired('mcp/src') },
  web: {
    file: 'resources/assets/v3/js/boot/bootstrap.js, resources/assets/v3/js/boot/blank-bootstrap.js',
    marker: MARKER,
    wired: read('resources/assets/v3/js/boot/bootstrap.js').includes(MARKER) && read('resources/assets/v3/js/boot/blank-bootstrap.js').includes(MARKER),
  },
};
const unwired = Object.entries(runtimes).filter(([, r]) => !r.wired).map(([name]) => name);

// ---------------------------------------------------------------------------------------------
// The report file (§13)
// ---------------------------------------------------------------------------------------------

function coverageFile() {
  const env = process.env;
  let home = env.HOME;
  if (!home) {
    try {
      home = homedir();
    } catch {
      home = '';
    }
  }
  const override = (env.FIREFLY_ERROR_FILE ?? '').trim();
  if (override) {
    const path = override.startsWith('~/') && home ? join(home, override.slice(2)) : override;
    return join(dirname(path), 'error_file_coverage.json');
  }
  if (home) return join(home, 'T', 'firefly', 'error_file_coverage.json');
  let uid = 'user';
  try {
    uid = String(userInfo().uid);
  } catch {
    // keep 'user'
  }
  return join(tmpdir(), `firefly_${uid}`, 'error_file_coverage.json');
}

const outFile = coverageFile();
let existing = {};
try {
  existing = JSON.parse(readFileSync(outFile, 'utf8'));
  if (!existing || typeof existing !== 'object') existing = {};
} catch {
  existing = {};
}
const previousViews = Array.isArray(existing.js?.sessionViews) ? existing.js.sessionViews : null;

violations.sort((a, b) => (a.file === b.file ? a.line - b.line : a.file < b.file ? -1 : 1));
const byKind = {};
for (const v of violations) byKind[v.kind] = (byKind[v.kind] ?? 0) + 1;
const v3Sites = Object.values(counts).reduce((a, b) => a + b, 0);

const report = {
  ...existing,
  js: {
    generatedAt: new Date().toISOString(),
    parser: 'the TypeScript compiler API (cli/node_modules/typescript), one in-memory project, syntax only',
    enforced: {
      scope: 'cli/code/src (minus vendor/), mcp/src (minus vendor/, canary/), ' + GLUE,
      files: enforced.length,
      errorSites: enforcedSites,
      compliant: compliantSites,
      violations: violations.length,
      byKind,
      sites: violations,
    },
    upstreamV3: {
      ceilingFile: CEILING_FILE,
      files: Object.keys(counts).length,
      sites: v3Sites,
      violations: v3Violations,
      notes: v3Notes,
    },
    files,
    sessionViews: views,
    previous: { sessionViews: previousViews, generatedAt: existing.js?.generatedAt ?? null },
    parseErrors,
  },
  runtimes: { ...(existing.runtimes && typeof existing.runtimes === 'object' ? existing.runtimes : {}), ...runtimes },
};

try {
  mkdirSync(dirname(outFile), { recursive: true, mode: 0o700 });
  const tmp = `${outFile}.${process.pid}.tmp`;
  writeFileSync(tmp, JSON.stringify(report, null, 2) + '\n', { mode: 0o600 });
  try {
    renameSync(tmp, outFile);
  } catch (err) {
    try {
      unlinkSync(tmp);
    } catch {
      // nothing to clean
    }
    throw err;
  }
} catch (err) {
  process.stderr.write(`error-file coverage: could not write ${outFile}: ${err?.message ?? err}\n`);
}

// ---------------------------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------------------------

const out = (line) => process.stdout.write(line + '\n');
const kinds = Object.entries(byKind).map(([k, n]) => `${k} ${n}`).join(', ');
out('error-file coverage, TypeScript and JavaScript (pm/error_err.mdx §13.2)');
out(`  enforced       ${enforced.length} files, ${enforcedSites} error sites, ${compliantSites} compliant, ${violations.length} violations${kinds ? ` (${kinds})` : ''}`);
for (const v of violations) out(`    VIOLATING  ${v.file}:${v.line}  ${v.kind} — ${v.message}`);
out(`  upstream v3    ${v3Sites} catch sites in ${Object.keys(counts).length} files against ${CEILING_FILE} — ${v3Violations.length} over the ceiling`);
for (const v of v3Violations) out(`    VIOLATING  ${v.file}  ${v.message}`);
for (const note of v3Notes) out(`    note  ${note}`);
if (!quiet) {
  for (const rel of Object.keys(counts)) out(`    upstream-net-covered  ${rel}  ${counts[rel]}/${ceiling[rel] ?? '—'}`);
}
out(`  session views with no v3 bundle (informational, E11): ${views.length}`);
if (previousViews === null) out('    delta: no previous run recorded');
else {
  const added = views.filter((v) => !previousViews.includes(v));
  const gone = previousViews.filter((v) => !views.includes(v));
  out(`    delta since the previous run: ${added.length} added, ${gone.length} gone`);
  for (const v of added) out(`      + ${v}`);
  for (const v of gone) out(`      - ${v}`);
}
if (!quiet) for (const v of views) out(`    ${v}`);
for (const [name, r] of Object.entries(runtimes)) out(`  runtime ${name.padEnd(11)} ${r.wired ? 'wired' : 'UNWIRED'}  (${r.file})`);
for (const p of parseErrors) out(`  NOT PARSED  ${p}`);
out(`  report: ${outFile}`);

if (violations.length > 0 || v3Violations.length > 0 || unwired.length > 0 || parseErrors.length > 0) process.exitCode = 1;
