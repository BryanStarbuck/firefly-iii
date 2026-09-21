/**
 * T14 / T15 — a web page reaching the plane. The plane refuses any request
 * carrying Origin or Sec-Fetch-*; this client must never send one, which is
 * why it is node:http and never fetch. It sends the key only as
 * X-Firefly-Machine-Key — never in a URL, never as Authorization.
 */
import type { Canary } from './canary.js';
import { builtFiles } from './canary.js';

export const canary: Canary = {
  name: 'origin-gate',
  threats: ['T14', 'T15'],
  summary: 'client.js sets no Origin, Referer, Sec-Fetch-*, Cookie, Authorization or X-Forwarded-For header and puts no key in a query string',
  check(ctx) {
    const client = builtFiles(ctx.distDir).find((f) => f.name === 'client.js');
    if (!client) return ['dist/client.js is missing'];
    const problems: string[] = [];
    for (const header of ['Origin', 'Referer', 'Sec-Fetch', 'Cookie', 'Authorization', 'X-Forwarded-For', 'X-Real-IP']) {
      if (new RegExp(`['"]${header}`, 'i').test(client.code)) problems.push(`client.js names the ${header} header`);
    }
    if (!/['"]X-Firefly-Machine-Key['"]/.test(client.code)) problems.push('client.js does not send X-Firefly-Machine-Key');
    if (!/['"]X-Firefly-Client['"]\s*:\s*['"]mcp['"]/.test(client.code)) problems.push('client.js does not identify itself as X-Firefly-Client: mcp');
    if (/[?&](key|api_key|machine_key)=/i.test(client.code)) problems.push('client.js builds a key into a query string');
    return problems;
  },
};
