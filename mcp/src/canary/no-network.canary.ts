/**
 * T9 / T12 — exfiltration through the process. One module opens sockets
 * (client.js), no fetch at all (it would add Sec-Fetch-* headers the plane's
 * origin gate refuses), and no host literal but loopback.
 */
import type { Canary } from './canary.js';
import { builtFiles } from './canary.js';

const SOCKET_MODULES = /from\s+['"]node:(https?|net|tls|dgram|http2)['"]|require\(\s*['"](node:)?(https?|net|tls|dgram|http2)['"]\s*\)/;

export const canary: Canary = {
  name: 'no-network',
  threats: ['T9', 'T12'],
  summary: 'only client.js imports a socket module; nothing calls fetch or opens a WebSocket; the only host literal is loopback',
  check(ctx) {
    const problems: string[] = [];
    const socketUsers: string[] = [];
    for (const f of builtFiles(ctx.distDir)) {
      if (SOCKET_MODULES.test(f.code)) socketUsers.push(f.name);
      if (/\bfetch\s*\(/.test(f.code)) problems.push(`${f.name}: calls fetch`);
      if (/\bWebSocket\b|\bEventSource\b|XMLHttpRequest/.test(f.code)) problems.push(`${f.name}: opens a browser-style connection`);
      for (const host of f.code.match(/\b(?:https?|wss?):\/\/[a-z0-9.\-[\]:]+/gi) ?? []) {
        if (!/^https?:\/\/(127\.0\.0\.1|localhost)(:\d*)?$/i.test(host)) problems.push(`${f.name}: host literal ${host}`);
      }
    }
    if (socketUsers.length !== 1 || socketUsers[0] !== 'client.js') problems.push(`socket modules imported by [${socketUsers.join(', ')}], expected exactly [client.js]`);
    return problems;
  },
};
