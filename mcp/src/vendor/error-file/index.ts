// GENERATED from errorfile/src — run node scripts/sync-error-file.mjs
// The error file's call-site API for ffx and mcp — pm/error_err.mdx §5, §7.2.
//
// The one source of truth is errorfile/src. cli/code/src/vendor/error-file/ and
// mcp/src/vendor/error-file/ are GENERATED copies (scripts/sync-error-file.mjs, §5.4); edit here.
// Ordinary modules import this entry; each runtime installs the sink once, from ./node.js (§5.6).

export {
  errorFileFor,
  flushErrorFile,
  guard,
  hasErrorSink,
  isReported,
  isTransientNetworkError,
  reportRejection,
  setErrorSink,
  tryOr,
  tryOrAsync,
} from './core.js';
export type { ErrorData, ErrorDataValue, ErrorFile, ErrorFileOptions, ErrorLevel, ErrorRecord, ErrorSink } from './core.js';
export { describeError } from './describe.js';
