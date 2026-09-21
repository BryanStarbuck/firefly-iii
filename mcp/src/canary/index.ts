/** Every canary, for the tests. Nothing on the server's import graph imports this. */
import { canary as audit } from './audit.canary.js';
import type { Canary } from './canary.js';
import { canary as envelope } from './envelope.canary.js';
import { canary as gates } from './gates.canary.js';
import { canary as noFsWrite } from './no-fs-write.canary.js';
import { canary as noNetwork } from './no-network.canary.js';
import { canary as noShell } from './no-shell.canary.js';
import { canary as originGate } from './origin-gate.canary.js';
import { canary as queryGuard } from './query-guard.canary.js';
import { canary as redaction } from './redaction.canary.js';
import { canary as routing } from './routing.canary.js';
import { canary as stdoutPurity } from './stdout-purity.canary.js';

export const CANARIES: readonly Canary[] = [gates, routing, queryGuard, redaction, noFsWrite, envelope, noNetwork, noShell, audit, stdoutPurity, originGate];
