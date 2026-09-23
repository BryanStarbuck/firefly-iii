/**
 * Sign-in accounts — pm/cli.mdx §13, pm/accounts.mdx §4.3.
 *
 *   ffx admin users                                     who can sign in (admin tier)
 *   ffx admin create-first-user --email … --write       the bootstrap, on an install with no users
 *   ffx admin create-user       --email … --write       another sign-in account (admin tier)
 *   ffx admin set-password      --email … --write       the way back in (admin tier)
 *
 * These four are about the row in the `users` table that logs into the web UI. They are NOT about
 * ledger accounts (checking, savings, credit cards) — that is `ffx accounts …`.
 *
 * The password never appears in a flag by default: `--password-stdin` reads it from a pipe, and a
 * terminal is prompted twice with the echo off. `--password X` exists because it is what a script
 * wants, and it warns, because argv is visible to every process on the machine and lands in the
 * shell history.
 */
import fs from 'node:fs';
import readline from 'node:readline';

import type { Envelope } from '../client.js';
import { CliError, EXIT } from '../errors.js';
import type { Outcome } from '../render.js';
import { getPath, objectView, warn } from '../render.js';
import type { Ctx, FlagDef, VerbDef } from '../verbs.js';
import { C, LIST_FLAGS, listOutcome, listQuery, seg } from './shared.js';

const G = 'Sign-in accounts (dry run unless --write)';

/** Upstream's own registration rule (RequestInformation::validator): min 16, and bcrypt reads 72 bytes. */
const MIN_PASSWORD = 16;
const MAX_PASSWORD = 72;

// -------------------------------------------------------------- password ---

/** Ask twice on a terminal, with the echo off, and never accept a mismatch. */
function promptTwice(): Promise<string> {
  const rl = readline.createInterface({ input: process.stdin, output: process.stderr, terminal: true });
  const hidden = (question: string): Promise<string> =>
    new Promise((resolve) => {
      const onData = (char: Buffer | string): void => {
        // Repaint the prompt with nothing after it, so the typed characters never reach the screen.
        if (!/[\r\n\u0004]/.test(String(char))) readline.clearLine(process.stderr, 0), readline.cursorTo(process.stderr, 0), process.stderr.write(question);
      };
      process.stdin.on('data', onData);
      rl.question(question, (answer) => {
        process.stdin.off('data', onData);
        process.stderr.write('\n');
        resolve(answer);
      });
    });
  return (async () => {
    try {
      const first = await hidden('New password: ');
      const again = await hidden('Again:        ');
      if (first !== again) throw new CliError(EXIT.USAGE, 'the two passwords do not match — nothing was sent');
      return first;
    } finally {
      rl.close();
    }
  })();
}

/**
 * Where the password comes from, in the order a careful person would want it:
 * --password-stdin (a pipe or a file), a prompt on a terminal, then --password (which warns).
 */
async function readPassword(ctx: Ctx): Promise<string> {
  const flag = ctx.str('password');
  const viaStdin = ctx.bool('password-stdin');
  if (flag !== undefined && viaStdin) throw new CliError(EXIT.USAGE, 'give either --password or --password-stdin, not both');

  let password: string;
  if (viaStdin) {
    password = fs.readFileSync(0, 'utf8').replace(/\r?\n$/, '');
    if (password === '') throw new CliError(EXIT.USAGE, 'nothing arrived on stdin', { hint: "printf '%s' \"$PW\" | ffx admin set-password --email you@example.com --password-stdin --write" });
  } else if (flag !== undefined) {
    warn('ffx: --password puts the password in argv, where every process on this machine can read it, and in your shell history.\n     safer: --password-stdin, or leave it off and be prompted.');
    password = flag;
  } else if (ctx.stdinTTY && process.stderr.isTTY) {
    password = await promptTwice();
  } else {
    throw new CliError(EXIT.USAGE, 'no password given, and there is no terminal to ask at', {
      hint: 'pipe it in: printf \'%s\' "$PW" | ffx … --password-stdin --write',
    });
  }

  if (password.length < MIN_PASSWORD) {
    throw new CliError(EXIT.USAGE, `that password is ${password.length} characters; Firefly III's own sign-up requires at least ${MIN_PASSWORD}`);
  }
  if (Buffer.byteLength(password, 'utf8') > MAX_PASSWORD) {
    throw new CliError(EXIT.USAGE, `that password is longer than ${MAX_PASSWORD} bytes, and bcrypt would silently ignore the rest`);
  }
  return password;
}

function requiredEmail(ctx: Ctx): string {
  const email = ctx.str('email');
  if (email === undefined) throw new CliError(EXIT.USAGE, '--email is required', { hint: `ffx help ${ctx.verb.path.join(' ')}` });
  // Deliberately loose: the server's `email` rule is the authority (§5.4), and it accepts
  // addresses this side has no business rejecting (`ops@local`). The only mistake worth catching
  // here is the one people actually make — typing a username where an email goes.
  if (!/^[^\s@]+@[^\s@]+$/.test(email)) throw new CliError(EXIT.USAGE, `"${email}" is not an email address — Firefly III signs in by email, not by username`);
  return email;
}

/** The plan every one of these verbs prints instead of writing, when --write is absent. */
function plan(title: string, lines: string[], applyCommand: string): Outcome {
  return {
    lines: [title, ...lines, '', 'Nothing was changed. To do it:', `  ${applyCommand}`],
    json: { ok: true, data: { dry_run: true, would: title } },
    plain: true,
  };
}

function accountOutcome(env: Envelope, extra: string[] = []): Outcome {
  const user = getPath(env.data, 'user');
  const view = objectView((user && typeof user === 'object' ? user : env.data ?? {}) as Record<string, unknown>);
  const notes = [...extra];
  const signIn = getPath(env.data, 'sign_in');
  const serverNotes = getPath(env.data, 'notes');
  const note = getPath(env.data, 'note');
  const next = getPath(env.data, 'next');
  if (typeof note === 'string') notes.push(note);
  if (Array.isArray(serverNotes)) notes.push(...serverNotes.filter((n): n is string => typeof n === 'string'));
  if (typeof signIn === 'string') notes.push(`sign in at ${signIn}`);
  if (typeof next === 'string') notes.push(`NEXT: ${next}`);
  view.title = 'DONE';
  view.notes = notes;
  return { envelope: env, view };
}

// ----------------------------------------------------------------- verbs ---

const PASSWORD_FLAGS: Record<string, FlagDef> = {
  password: { value: 'string', help: 'the password (visible in argv and shell history — prefer --password-stdin)' },
  'password-stdin': { help: 'read the password from stdin, up to the first newline' },
};

const adminUsers: VerbDef = {
  path: ['admin', 'users'],
  group: G,
  summary: 'every sign-in account of this install, blocked ones included',
  route: 'GET /admin/users',
  flags: { ...LIST_FLAGS },
  examples: ['ffx admin users'],
  async run(ctx) {
    const client = await ctx.plane();
    const env = await client.call('GET', '/admin/users', { query: listQuery(ctx) });
    return listOutcome(
      env,
      [C.id('id'), C.text('email'), C.bool('is_owner', 'owner'), C.bool('is_operator', 'operator'), C.bool('blocked'), C.text('blocked_code', 'why blocked'), C.bool('has_mfa', '2fa'), C.id('administration_id', 'admin id'), C.date('created_at', 'created')],
      ['users'],
      ['"operator" is the account ffx and the MCP act as (FIREFLY_MACHINE_OPERATOR).'],
    );
  },
};

const createFirstUser: VerbDef = {
  path: ['admin', 'create-first-user'],
  group: G,
  summary: 'the bootstrap: the FIRST sign-in account of an install that has none; it becomes the owner',
  route: 'POST /admin/first-user',
  writes: true,
  writeNote: [
    'This is the ONE write that needs no confirm token: it only runs on an install with no sign-in',
    'accounts at all, so there is nothing to preview and nothing it could overwrite. Without --write',
    'ffx prints what it would do. The server\'s write tier must be on (FIREFLY_MACHINE_ALLOW_WRITE=1).',
  ],
  flags: { email: { value: 'string', help: 'the email address to sign in with' }, ...PASSWORD_FLAGS },
  examples: [
    'ffx admin create-first-user --email you@example.com --write',
    'printf \'%s\' "$PW" | ffx admin create-first-user --email you@example.com --password-stdin --write',
  ],
  async run(ctx) {
    const email = requiredEmail(ctx);
    if (!ctx.universal.write) {
      return plan(`Would create the first sign-in account of this install: ${email} (it becomes the owner).`, [], `ffx admin create-first-user --email ${email} --write`);
    }
    const password = await readPassword(ctx);
    const client = await ctx.plane();
    ctx.note(`writing to ${ctx.cfg.apiUrl} (key ${client.fingerprint})`);
    const env = await client.call('POST', '/admin/first-user', { body: { email, password } });
    return accountOutcome(env);
  },
};

const createUser: VerbDef = {
  path: ['admin', 'create-user'],
  group: G,
  summary: 'another sign-in account on an install that already has one (admin tier)',
  route: 'POST /admin/users',
  writes: true,
  writeNote: [
    'ADMIN TIER. Needs FIREFLY_MACHINE_ALLOW_ADMIN=1 in the app\'s .env, and the MCP server is never',
    'allowed to call it. There is no dry run and no confirm token — a password has no preview — so',
    '--write is the confirmation. Without it ffx prints what it would do and stops.',
  ],
  flags: {
    email: { value: 'string', help: 'the email address to sign in with' },
    owner: { help: 'also give it the owner (administrator) role' },
    ...PASSWORD_FLAGS,
  },
  examples: ['ffx admin create-user --email second@example.com --write'],
  async run(ctx) {
    const email = requiredEmail(ctx);
    const owner = ctx.bool('owner');
    if (!ctx.universal.write) {
      return plan(`Would create a sign-in account: ${email}${owner ? ' (owner)' : ''}.`, [
        'It can read and write whatever ledger it is given.',
      ], `ffx admin create-user --email ${email}${owner ? ' --owner' : ''} --write`);
    }
    const password = await readPassword(ctx);
    const client = await ctx.plane();
    ctx.note(`writing to ${ctx.cfg.apiUrl} (key ${client.fingerprint})`);
    const env = await client.call('POST', '/admin/users', { body: { email, password, ...(owner ? { owner: true } : {}) } });
    return accountOutcome(env);
  },
};

const setPassword: VerbDef = {
  path: ['admin', 'set-password'],
  group: G,
  summary: 'replace a sign-in account\'s password — the way back in when it is forgotten (admin tier)',
  route: 'POST /admin/users/{id}/password',
  writes: true,
  writeNote: [
    'ADMIN TIER. Needs FIREFLY_MACHINE_ALLOW_ADMIN=1 in the app\'s .env, and the MCP server is never',
    'allowed to call it. There is no dry run and no confirm token — a password has no preview — so',
    '--write is the confirmation. Without it ffx prints what it would do and stops.',
  ],
  flags: {
    email: { value: 'string', help: 'the account\'s email address' },
    id: { value: 'string', help: 'the account\'s numeric id, instead of --email' },
    'clear-mfa': { help: 'also switch two-factor authentication off (for a lost authenticator)' },
    unblock: { help: 'also unblock the account, if it is blocked' },
    ...PASSWORD_FLAGS,
  },
  examples: [
    'ffx admin set-password --email you@example.com --write',
    'ffx admin set-password --email you@example.com --clear-mfa --unblock --write',
  ],
  async run(ctx) {
    const email = ctx.str('email');
    const id = ctx.str('id');
    if (email !== undefined && id !== undefined) throw new CliError(EXIT.USAGE, 'give either --email or --id, not both');
    const who = id ?? (email !== undefined ? requiredEmail(ctx) : undefined);
    if (who === undefined) {
      throw new CliError(EXIT.USAGE, 'which account? give --email (or --id)', { hint: 'list them: ffx admin users' });
    }
    const clearMfa = ctx.bool('clear-mfa');
    const unblock = ctx.bool('unblock');
    if (!ctx.universal.write) {
      return plan(`Would replace the password of sign-in account ${who}.`, [
        'The old password stops working immediately, and this cannot be undone.',
        ...(clearMfa ? ['Two-factor authentication would be switched off.'] : []),
        ...(unblock ? ['The account would be unblocked.'] : []),
      ], `ffx admin set-password ${id ? `--id ${id}` : `--email ${who}`}${clearMfa ? ' --clear-mfa' : ''}${unblock ? ' --unblock' : ''} --write`);
    }
    const password = await readPassword(ctx);
    const client = await ctx.plane();
    ctx.note(`writing to ${ctx.cfg.apiUrl} (key ${client.fingerprint})`);
    const env = await client.call('POST', `/admin/users/${seg(who)}/password`, {
      body: { password, ...(clearMfa ? { clear_mfa: true } : {}), ...(unblock ? { unblock: true } : {}) },
    });
    return accountOutcome(env);
  },
};

export const signInVerbs: readonly VerbDef[] = Object.freeze([adminUsers, createFirstUser, createUser, setPassword]);
