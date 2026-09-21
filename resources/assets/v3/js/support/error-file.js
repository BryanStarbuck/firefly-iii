/*
 * error-file.js
 * Copyright (c) 2026 the Firefly III fork authors
 *
 * This file is part of a fork of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

// Fork: pm/error_err.mdx §6 — the browser core of the error file. It has NO imports: the sink,
// batching, the budget, the beacon, the window nets and the attach helpers. The browser never
// touches the disk (R8): records are delivered to POST error-report, which PHP guards, re-redacts
// and writes as [web] (§4.10). Nothing here runs until something faults (R3), and every path is
// total (R11): the one fallback is a single console.warn per page when delivery fails.

export const BATCH_SIZE = 20;
export const BATCH_DELAY_MS = 2000;
export const PAGE_BUDGET = 30;
export const BUDGET_WINDOW_MS = 60000;
export const BATCH_BYTES = 61440;
export const WATCHDOG_MS = 15000;

const TEXT_CAP = 2000;
const PHRASE_CAP = 200;
const FRAME_CAP = 400;
const FRAMES = 12;
const CAUSES = 5;
const EXPRESSION_CAP = 120;
const SKIPPED_STATUS = [401, 404, 419, 422, 429];
// eslint-disable-next-line no-control-regex -- matching control characters is the point
const CONTROL = /[\u0000-\u001f\u007f\u2028\u2029]/g;
const ORIGIN = /^[a-z][a-z0-9+.-]*:\/\/[^/]*/i;

// The sink's state. Plain module variables (not one object) so the minifier can shorten them (§6.5
// budget: at most 3 KB gzip). `scope` is null until installBrowserErrorFile() runs.
let scope = null;
let endpoint;
let sid;
let queue;
let pending;
let timer;
let inFlight;
let windowStart;
let used;
let dropped;
let busy;
let warned;
let gone;
let reported = new WeakSet();
let attached = new WeakSet();

function prop(value, key) {
    if (value === null || (typeof value !== "object" && typeof value !== "function")) {
        return undefined;
    }
    try {
        return value[key];
    } catch {
        return undefined;
    }
}

function str(value) {
    try {
        return typeof value === "string" ? value : String(value);
    } catch {
        return "[unprintable]";
    }
}

function cap(value, max) {
    value = str(value).replace(CONTROL, " ");
    if (value.length <= max) {
        return value;
    }
    const keep = max - 3;
    const head = Math.ceil(keep / 2);
    return value.slice(0, head) + " … " + value.slice(value.length - (keep - head));
}

/**
 * A URL path as a template (§6.4): at most two leading static words are kept (one after an
 * `api/v1/` prefix, which is always kept) and every other segment becomes {x}, so ledger text in
 * a path (a tag name, an account name) never leaves the page.
 */
export function templatePath(path) {
    const text = str(path).split(/[?#]/)[0];
    const segments = text.split("/").filter((part) => part !== "");
    const out = [];
    let words = 2;
    let i = 0;
    if (segments[0] === "api" && segments[1] === "v1") {
        out.push("api", "v1");
        words = 1;
        i = 2;
    }
    for (; i < segments.length; i++) {
        if (words > 0 && /^[a-z][a-z0-9-]*$/.test(segments[i])) {
            out.push(segments[i]);
            words--;
        } else {
            words = 0;
            out.push("{x}");
        }
    }
    return (text.startsWith("/") ? "/" : "") + out.join("/");
}

function requestPath(url) {
    const path = str(url ?? "")
        .replace(ORIGIN, "")
        .replace(/^\/+/, "");
    return templatePath(path);
}

function pagePath() {
    return templatePath(prop(prop(scope, "location"), "pathname") ?? "/");
}

function headline(err) {
    const message = prop(err, "message");
    if (typeof message !== "string" && (typeof err !== "object" || err === null)) {
        return cap("Thrown " + typeof err + ": " + str(err), TEXT_CAP);
    }
    const name = prop(err, "name");
    const text = (typeof name === "string" && name ? name : "Error") + (message ? ": " + str(message) : "");
    const code = prop(err, "code");
    return cap(text, TEXT_CAP) + (["string", "number"].includes(typeof code) ? cap(" (code=" + code + ")", 100) : "");
}

function causes(err) {
    const seen = new Set([err]);
    let out = "";
    let current = prop(err, "cause");
    for (let depth = 0; depth < CAUSES && current !== undefined && current !== null && !seen.has(current); depth++) {
        seen.add(current);
        out += " | cause: " + headline(current);
        current = prop(current, "cause");
    }
    return cap(out, TEXT_CAP);
}

// One frame's location: a bundle URL is cut to build/assets/<chunk>.js:L:C, any other script keeps
// its path, and a location that is not a script (an inline script's page URL) is templated.
function location(loc) {
    const match = /^(.*?)(:\d+(?::\d+)?)?$/.exec(loc);
    let path = match[1].replace(ORIGIN, "").replace(/^\//, "").split(/[?#]/)[0];
    if (path.includes("build/assets/")) {
        path = "build/assets/" + path.split("build/assets/").pop();
    } else if (!/\.m?js$/.test(path)) {
        path = templatePath("/" + path);
    }
    return path + (match[2] ?? "");
}

function stackOf(err) {
    const stack = prop(err, "stack");
    if (typeof stack !== "string") {
        return "";
    }
    const frames = [];
    for (const raw of stack.split("\n")) {
        const line = raw.trim();
        const chrome = /^at (?:(.+?) \()?(.+?)\)?$/.exec(line);
        const gecko = chrome ? null : /^([^@\s]*)@(.+:\d+:\d+)$/.exec(line);
        const hit = chrome ?? gecko;
        if (frames.length >= FRAMES || !hit || /^(file|node):|\/@vite\/client|support\/error-file/.test(hit[2])) {
            continue;
        }
        const where = location(hit[2]);
        frames.push(cap(hit[1] ? "at " + hit[1] + " (" + where + ")" : "at " + where, FRAME_CAP));
    }
    return frames.join("\n");
}

function scalars(data) {
    const out = {};
    for (const key of Object.keys(data ?? {})) {
        const value = data[key];
        if (value === null || ["string", "number", "boolean"].includes(typeof value)) {
            out[key] = value;
        }
    }
    return out;
}

function hidden() {
    return gone || prop(prop(scope, "document"), "visibilityState") === "hidden";
}

function iso(ms) {
    return new scope.Date(ms).toISOString();
}

function report(level, where, doing, err, data, hasErr = true) {
    if (!scope || busy) {
        return;
    }
    busy = true;
    try {
        if (hasErr && err !== null && (typeof err === "object" || typeof err === "function")) {
            if (reported.has(err)) {
                return;
            }
            reported.add(err);
        }
        if (level === null || hidden()) {
            return;
        }
        const t = scope.Date.now();
        if (t - windowStart >= BUDGET_WINDOW_MS) {
            windowStart = t;
            used = 0;
        }
        if (used >= PAGE_BUDGET) {
            dropped++;
            return;
        }
        used++;
        queue.push({
            ts: iso(t),
            level,
            where: cap(where, PHRASE_CAP),
            doing: cap(doing, PHRASE_CAP),
            error: hasErr ? headline(err) : "",
            cause: hasErr ? causes(err) : "",
            stack: hasErr ? stackOf(err) : "",
            data: scalars(data),
        });
        if (queue.length >= BATCH_SIZE) {
            send(false);
        } else if (timer === null) {
            timer = scope.setTimeout(() => send(false), BATCH_DELAY_MS);
        }
    } catch (e) {
        warnOnce(e);
    } finally {
        busy = false;
    }
}

function warnOnce(err) {
    if (!scope || warned) {
        return;
    }
    warned = true;
    try {
        console.warn("[error-file] could not deliver error reports", err);
    } catch {
        // silence is the last fallback
    }
}

function bytes(text) {
    return new scope.TextEncoder().encode(text).byteLength;
}

// Split the queue into bodies under BATCH_BYTES, measured in UTF-8 bytes, never in UTF-16 units.
function bodies(records) {
    sid = sid ?? randomSid(); // random per page load, drawn on the first send (R3)
    const head = '{"sid":"' + sid + '","events":[';
    const out = [];
    let parts = [];
    let size = bytes(head) + 2;
    for (const record of records) {
        const json = JSON.stringify(record);
        const extra = bytes(json) + 1;
        if (parts.length > 0 && size + extra > BATCH_BYTES) {
            out.push(head + parts.join(",") + "]}");
            parts = [];
            size = bytes(head) + 2;
        }
        parts.push(json);
        size += extra;
    }
    if (parts.length > 0) {
        out.push(head + parts.join(",") + "]}");
    }
    return out;
}

// One keepalive fetch in flight at a time: the 64 KiB keepalive quota is shared by the document.
function pump() {
    if (inFlight || pending.length === 0) {
        return;
    }
    const body = pending.shift();
    try {
        inFlight = true;
        Promise.resolve(
            scope.fetch(endpoint, {
                method: "POST",
                keepalive: true,
                headers: { "content-type": "text/plain;charset=UTF-8" },
                body,
            }),
        )
            .then(undefined, warnOnce)
            .then(() => {
                inFlight = false;
                pump();
            });
    } catch (e) {
        inFlight = false;
        warnOnce(e);
    }
}

function beacon(body) {
    try {
        const nav = scope.navigator;
        if (typeof nav?.sendBeacon === "function") {
            return nav.sendBeacon(endpoint, new scope.Blob([body], { type: "text/plain" }));
        }
    } catch (e) {
        warnOnce(e);
        return true;
    }
    return false;
}

function send(useBeacon) {
    try {
        if (timer !== null) {
            scope.clearTimeout(timer);
            timer = null;
        }
        if (dropped > 0) {
            queue.push({
                ts: iso(scope.Date.now()),
                level: "WARN",
                where: pagePath(),
                doing: "sending error reports",
                error: "dropped " + dropped + " browser reports over the page budget of " + PAGE_BUDGET + " per 60s",
                cause: "",
                stack: "",
                data: {},
            });
            dropped = 0;
        }
        pending.push(...bodies(queue));
        queue = [];
        if (useBeacon) {
            pending = pending.filter((body) => !beacon(body));
        }
        pump();
    } catch (e) {
        warnOnce(e);
    }
}

/** Send everything queued now; with the beacon when the page is going away. */
export function flushBrowserErrorFile(useBeacon = true) {
    if (scope) {
        send(useBeacon);
    }
}

/** A reporter for one fork-owned module (§7.3). `where` is its repo-relative path. */
export function errorFileFor(where) {
    return {
        where,
        caught(doing, err, data) {
            report("ERROR", where, doing, err, data);
        },
        warn(doing, err, data) {
            report("WARN", where, doing, err, data, err !== undefined);
        },
        expected(doing, err) {
            report(null, where, doing, err);
        },
    };
}

function net(level, doing, err, data, hasErr = true) {
    report(level, pagePath(), doing, err, data, hasErr);
}

/** §6.3: conditions a page produces on its own that are never written. */
export function isBenignBrowserError(event) {
    const error = prop(event, "error");
    const message = str(prop(event, "message") ?? "");
    const filename = str(prop(event, "filename") ?? "");
    if ((error === null || error === undefined) && /^(ResizeObserver loop|Script error\.)/.test(message)) {
        return true;
    }
    return (filename + str(prop(error, "stack") ?? "")).includes("/@vite/client");
}

/** §6.3 / R7: a cancelled request and HTTP 401, 404, 419, 422 and 429 are answers, not faults. */
export function isExpectedHttpFailure(error) {
    const code = prop(error, "code");
    return (
        code === "ERR_CANCELED" ||
        (code === "ECONNABORTED" && /aborted/i.test(prop(error, "message"))) ||
        SKIPPED_STATUS.includes(prop(prop(error, "response"), "status"))
    );
}

function httpLevel(status) {
    if (SKIPPED_STATUS.includes(status)) {
        return null;
    }
    return typeof status !== "number" || status === 0 || status >= 500 ? "ERROR" : "WARN";
}

function onAxiosError(error) {
    const status = prop(prop(error, "response"), "status");
    const config = prop(error, "config");
    const method = str(prop(config, "method") ?? "get").toUpperCase();
    const level = isExpectedHttpFailure(error) ? null : httpLevel(status);
    const data = typeof status === "number" ? { status, net: "axios" } : { net: "axios" };
    net(level, "calling " + method + " " + requestPath(prop(config, "url")), error, data);
}

/** Report a failed request through one axios instance, then reject with the SAME error. Idempotent. */
export function attachAxios(instance) {
    const interceptors = prop(prop(instance, "interceptors"), "response");
    if (!interceptors || attached.has(instance)) {
        return;
    }
    attached.add(instance);
    interceptors.use(undefined, (error) => {
        onAxiosError(error);
        return Promise.reject(error);
    });
}

/** axios.create() does not inherit interceptors: attach every instance created from now on. */
export function patchAxiosCreate(axios) {
    const create = prop(axios, "create");
    if (typeof create !== "function" || attached.has(create)) {
        return;
    }
    const patched = function (...args) {
        const instance = create.apply(this, args);
        attachAxios(instance);
        return instance;
    };
    attached.add(patched);
    axios.create = patched;
}

/** Alpine's error handler: report, then do exactly what Alpine's default handler does. */
export function attachAlpine(Alpine) {
    Alpine.setErrorHandler((error, el, expression) => {
        net("ERROR", "evaluating an Alpine expression", error, {
            expression: expression ? cap(expression, EXPRESSION_CAP) : undefined,
            element_id: prop(el, "id") || undefined,
            net: "alpine",
        });
        error = Object.assign(error ?? { message: "No error message given." }, { el, expression });
        reported.add(error); // the re-throw below reaches the window net as the same, already written, fault
        console.warn(
            `Alpine Expression Error: ${error.message}\n\n${expression ? 'Expression: "' + expression + '"\n\n' : ""}`,
            el,
        );
        (scope ?? globalThis).setTimeout(() => {
            throw error;
        }, 0);
    });
}

/** One document-level handler for every legacy jQuery .fail() site. */
export function attachJQuery($, document) {
    $(document).ajaxError((event, xhr, settings) => {
        const status = prop(xhr, "status");
        if (prop(xhr, "statusText") === "abort") {
            return;
        }
        const message = status ? "Request failed with status code " + status : "Network Error";
        net(
            httpLevel(status),
            "calling " + str(prop(settings, "type") ?? "GET").toUpperCase() + " " + requestPath(prop(settings, "url")),
            { name: "AjaxError", message },
            status ? { status, net: "ajax" } : { net: "ajax" },
        );
    });
}

/** WARN when the page has not finished booting 15 s after DOMContentLoaded while it is visible. */
export function startBootWatchdog(win) {
    win.setTimeout(() => {
        if (win.bootstrapped === false) {
            net("WARN", "bootstrapping the page", { name: "Error", message: "the page did not finish booting" }, {
                net: "watchdog",
            });
        }
    }, WATCHDOG_MS);
}

function randomSid() {
    return Array.from(scope.crypto.getRandomValues(new Uint8Array(8)), (b) => (b % 36).toString(36)).join("");
}

/** Install the sink and the window nets on `scope` (window in production). Idempotent, total. */
export function installBrowserErrorFile(options = {}) {
    if (scope || !options.scope) {
        return;
    }
    scope = options.scope;
    endpoint = options.endpoint ?? "error-report";
    sid = timer = null;
    queue = [];
    pending = [];
    windowStart = -Infinity;
    used = dropped = 0;
    inFlight = busy = warned = gone = false;
    try {
        scope.addEventListener("error", (event) => {
            if (isBenignBrowserError(event)) {
                return;
            }
            const error = prop(event, "error");
            const file = prop(event, "filename");
            const stack = file ? "at " + [file, prop(event, "lineno"), prop(event, "colno")].join(":") : "";
            net(
                "ERROR",
                "running page script",
                error ?? { name: "ErrorEvent", message: prop(event, "message"), stack },
                { net: "window" },
            );
        });
        scope.addEventListener("unhandledrejection", (event) => {
            const reason = prop(event, "reason");
            net(isExpectedHttpFailure(reason) ? null : "ERROR", "settling a promise", reason, { net: "rejection" });
        });
        scope.addEventListener("pagehide", () => {
            send(true);
            gone = true;
        });
        scope.addEventListener("pageshow", (event) => {
            if (prop(event, "persisted")) {
                gone = false;
            }
        });
        scope.addEventListener("visibilitychange", () => {
            if (hidden()) {
                send(true);
            }
        });
    } catch (e) {
        warnOnce(e);
    }
}

/** Test seam: forget the sink and every mark, so a test can install again. */
export function resetBrowserErrorFileForTests() {
    if (scope && timer !== null) {
        scope.clearTimeout(timer);
    }
    scope = null;
    reported = new WeakSet();
    attached = new WeakSet();
}

if (typeof window !== "undefined") {
    installBrowserErrorFile({ endpoint: "error-report", scope: window });
}
