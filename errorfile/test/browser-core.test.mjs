// browser-core.test.mjs — pm/error_err.mdx §6, §15: the browser core under node --test.
//
// The core (resources/assets/v3/js/support/error-file.js) has no imports and installs itself only
// when `window` exists, so it is imported here natively with no DOM. A fake scope pins the clock and
// the random source, so the batch it produces is deterministic. That batch is COMPARED with the
// committed errorfile/fixtures/browser-batch.json (which IngestRouteTest posts) and the test FAILS
// on any difference. The fixture is rewritten only with an explicit --update:
//   node errorfile/test/browser-core.test.mjs --update
// Every value below is synthetic (§12).

import assert from "node:assert/strict";
import { readFileSync, writeFileSync } from "node:fs";
import { afterEach, beforeEach, describe, it, mock } from "node:test";
import { fileURLToPath } from "node:url";

import {
    BATCH_BYTES,
    BATCH_SIZE,
    PAGE_BUDGET,
    attachAlpine,
    attachAxios,
    attachJQuery,
    errorFileFor,
    flushBrowserErrorFile,
    installBrowserErrorFile,
    isBenignBrowserError,
    isExpectedHttpFailure,
    patchAxiosCreate,
    resetBrowserErrorFileForTests,
    startBootWatchdog,
    templatePath,
} from "../../resources/assets/v3/js/support/error-file.js";

const FIXTURE = fileURLToPath(new URL("../fixtures/browser-batch.json", import.meta.url));
const UPDATE = process.argv.includes("--update") || process.env.FIREFLY_BROWSER_BATCH_UPDATE === "1";
const ORIGIN = "http://127.0.0.1:7373";
const T0 = Date.UTC(2026, 8, 21, 18, 46, 13, 270);
const GLUE = "resources/assets/v3/js/support/error-file-app.js";

function fakeScope({ fetchImpl, beaconImpl } = {}) {
    let clock = T0;
    let nextId = 1;
    const timers = new Map();
    const listeners = new Map();
    const posts = [];
    const beacons = [];
    class FakeDate extends Date {
        static now() {
            return clock;
        }
    }
    const scope = {
        Date: FakeDate,
        TextEncoder,
        Blob,
        crypto: {
            getRandomValues(array) {
                array.forEach((_, i) => (array[i] = (i * 37 + 11) % 256));
                return array;
            },
        },
        location: { pathname: "/transactions/create" },
        document: { visibilityState: "visible" },
        navigator: {
            sendBeacon(url, blob) {
                beacons.push({ url, blob });
                return beaconImpl ? beaconImpl() : true;
            },
        },
        fetch(url, init) {
            posts.push({ url, init });
            return fetchImpl ? fetchImpl() : Promise.resolve({ status: 204 });
        },
        setTimeout(fn, ms) {
            const id = nextId++;
            timers.set(id, { fn, at: clock + ms });
            return id;
        },
        clearTimeout(id) {
            timers.delete(id);
        },
        addEventListener(type, fn) {
            listeners.set(type, [...(listeners.get(type) ?? []), fn]);
        },
        // test helpers
        posts,
        beacons,
        timers,
        emit(type, event = {}) {
            for (const fn of listeners.get(type) ?? []) {
                fn(event);
            }
        },
        advance(ms) {
            const until = clock + ms;
            for (;;) {
                const due = [...timers.entries()].filter(([, t]) => t.at <= until).sort((a, b) => a[1].at - b[1].at);
                if (due.length === 0) {
                    break;
                }
                const [id, timer] = due[0];
                timers.delete(id);
                clock = Math.max(clock, timer.at);
                timer.fn();
            }
            clock = until;
        },
    };
    return scope;
}

function events(body) {
    return JSON.parse(body).events;
}

function fetchedEvents(scope) {
    return scope.posts.flatMap((p) => events(p.init.body));
}

async function beaconBodies(scope) {
    return Promise.all(scope.beacons.map((b) => b.blob.text()));
}

async function settle() {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
}

function withStack(err, ...frames) {
    err.stack = `${err.name}: ${err.message}\n` + frames.map((f) => `    at ${f}`).join("\n");
    return err;
}

class AxiosError extends Error {
    constructor(message, code, config, response) {
        super(message);
        this.name = "AxiosError";
        this.code = code;
        this.config = config;
        this.request = { responseText: "never read" };
        if (response) {
            this.response = response;
            this.status = response.status;
        }
    }
}

function axiosError(method, url, status, code) {
    const response = status ? { status, data: { message: "synthetic", amount: "4211.08" } } : undefined;
    const message = status ? `Request failed with status code ${status}` : "Network Error";
    const err = new AxiosError(message, code, { method, url, data: '{"amount":"4211.08"}' }, response);
    return withStack(err, `${ORIGIN}/build/assets/create-Bq3xT9aa.js:1:2044`);
}

function fakeAxios() {
    const instance = {
        handlers: [],
        interceptors: {
            response: {
                use(onFulfilled, onRejected) {
                    instance.handlers.push({ onFulfilled, onRejected });
                },
            },
        },
        create() {
            return fakeAxios();
        },
        reject(error) {
            return instance.handlers.reduce((p, h) => p.then(h.onFulfilled, h.onRejected), Promise.reject(error));
        },
    };
    return instance;
}

function fakeAlpine() {
    const alpine = {
        handler: null,
        setErrorHandler(fn) {
            alpine.handler = fn;
        },
    };
    return alpine;
}

function fakeJQuery() {
    const handlers = [];
    const $ = () => ({
        ajaxError(fn) {
            handlers.push(fn);
        },
    });
    $.fail = (xhr, settings) => handlers.forEach((fn) => fn({}, xhr, settings, "Internal Server Error"));
    return $;
}

let warn;
beforeEach(() => {
    resetBrowserErrorFileForTests();
    warn = mock.method(console, "warn", () => {});
});
afterEach(() => {
    warn.mock.restore();
    resetBrowserErrorFileForTests();
});

describe("the browser batch (the committed fixture)", () => {
    it("produces exactly errorfile/fixtures/browser-batch.json", async () => {
        const scope = fakeScope();
        installBrowserErrorFile({ endpoint: "error-report", scope });
        const axios = fakeAxios();
        const api = fakeAxios();
        const alpine = fakeAlpine();
        const $ = fakeJQuery();
        patchAxiosCreate(axios);
        attachAxios(axios);
        attachAxios(api);
        attachAlpine(alpine);
        attachJQuery($, {});
        startBootWatchdog(Object.assign(scope, { bootstrapped: false }));

        // 20 records fill the first batch, which goes out at once through the keepalive fetch.
        for (let i = 0; i < BATCH_SIZE; i++) {
            scope.emit("error", { error: withStack(new Error("Synthetic filler"), `${ORIGIN}/build/assets/dates-DabDFySV.js:2:${i}`) });
        }
        assert.equal(scope.posts.length, 1);
        assert.equal(scope.posts[0].url, "error-report");
        assert.deepEqual(
            { ...scope.posts[0].init, body: undefined },
            { method: "POST", keepalive: true, headers: { "content-type": "text/plain;charset=UTF-8" }, body: undefined },
        );
        assert.equal(events(scope.posts[0].init.body).length, BATCH_SIZE);
        await settle();

        // The boot watchdog, 15 s later: the page never booted.
        scope.advance(15000);

        // A 500, a network error on a page whose path holds ledger text, and a 403.
        await assert.rejects(api.reject(axiosError("post", "api/v1/transactions", 500, "ERR_BAD_RESPONSE")));
        scope.location.pathname = "/tags/show/Synthetic Tag";
        await assert.rejects(axios.reject(axiosError("get", "/api/v1/tags/groceries?page=2", undefined, "ERR_NETWORK")));
        scope.location.pathname = "/transactions/create";
        await assert.rejects(api.reject(axiosError("put", `${ORIGIN}/api/v1/preferences/darkMode`, 403, "ERR_BAD_REQUEST")));

        // An Alpine expression; its re-throw reaches the window net as the same object and is dropped.
        const alpineError = withStack(new TypeError("Cannot read properties of undefined (reading 'toUpperCase')"), `${ORIGIN}/build/assets/create-Bq3xT9aa.js:1:9001`);
        alpine.handler(alpineError, { id: "form-currency" }, "splits[index].currency_code.toUpperCase()");
        const [rethrowId, rethrow] = [...scope.timers.entries()].find(([, t]) => t.at === T0 + 15000);
        scope.timers.delete(rethrowId);
        assert.throws(() => rethrow.fn(), (e) => e === alpineError);
        scope.emit("error", { error: alpineError, message: "Uncaught TypeError" });

        // A legacy jQuery .fail() site.
        $.fail({ status: 500, statusText: "Internal Server Error" }, { type: "GET", url: `${ORIGIN}/chart/account/frontpage?start=2026-09-01` });

        // Two rejections: one with a cause chain, one a thrown string.
        const viewRange = new TypeError("Cannot read properties of undefined (reading 'viewRange')", {
            cause: new RangeError("Synthetic range failure", { cause: { name: "Error", message: "Synthetic root cause", code: "E_SYNTH" } }),
        });
        scope.emit("unhandledrejection", {
            reason: withStack(viewRange, `${ORIGIN}/build/assets/dates-DabDFySV.js:1:61`, `${ORIGIN}/tags/show/Synthetic%20Tag:12:5`),
        });
        scope.emit("unhandledrejection", { reason: "Synthetic rejection text" });

        // The glue's own fault point (Pattern 10).
        errorFileFor(GLUE).caught("attaching the jQuery net", withStack(new TypeError("$(...).ajaxError is not a function"), `${ORIGIN}/build/assets/dates-DabDFySV.js:1:77`), { phase: "ready" });

        // A page script on a page whose path holds an id.
        scope.location.pathname = "/accounts/show/7";
        scope.emit("error", { error: withStack(new RangeError("Synthetic invalid time value"), `formatDate (${ORIGIN}/build/assets/format-money-Bf2Difk_.js:1:310)`) });
        scope.location.pathname = "/transactions/create";

        // The budget is now spent: this one is counted and summarised.
        scope.emit("error", { error: new Error("Synthetic over budget") });

        // The page goes away: the queue leaves through the beacon, as text/plain.
        scope.emit("pagehide");
        assert.equal(scope.posts.length, 1, "pagehide sends by beacon, not fetch");
        assert.equal(scope.beacons.length, 1);
        assert.equal(scope.beacons[0].url, "error-report");
        assert.equal(scope.beacons[0].blob.type, "text/plain");
        const body = (await beaconBodies(scope))[0];
        const batch = JSON.parse(body);

        // Invariants that hold whatever the fixture says.
        assert.match(batch.sid, /^[a-z0-9]{8}$/);
        assert.equal(batch.events.length, PAGE_BUDGET - BATCH_SIZE + 1);
        for (const event of batch.events) {
            assert.deepEqual(Object.keys(event), ["ts", "level", "where", "doing", "error", "cause", "stack", "data"]);
            assert.equal("app" in event, false, "the server stamps app");
        }
        assert.equal(body.includes("4211.08"), false, "no request or response body");
        assert.equal(body.includes("Synthetic Tag"), false, "ledger text in a path is templated");
        assert.equal(body.includes("groceries"), false);
        assert.equal(body.includes(ORIGIN), false);

        // After pagehide nothing is reported.
        scope.emit("error", { error: new Error("Synthetic after pagehide") });
        flushBrowserErrorFile();
        assert.equal(scope.beacons.length, 1);

        const produced = JSON.stringify(batch, null, 4) + "\n";
        if (UPDATE) {
            writeFileSync(FIXTURE, produced);
        }
        const committed = readFileSync(FIXTURE, "utf8");
        assert.equal(produced, committed, "the batch differs from errorfile/fixtures/browser-batch.json (rerun with --update only if the change is intended; IngestRouteTest posts this file)");
    });
});

describe("templatePath (§6.4)", () => {
    it("keeps at most two static words, one after api/v1, and templates every other segment", () => {
        assert.equal(templatePath("/transactions/show/12"), "/transactions/show/{x}");
        assert.equal(templatePath("/tags/show/Synthetic Tag"), "/tags/show/{x}");
        assert.equal(templatePath("/tags/show/groceries"), "/tags/show/{x}");
        assert.equal(templatePath("api/v1/tags/groceries"), "api/v1/tags/{x}");
        assert.equal(templatePath("api/v1/transactions"), "api/v1/transactions");
        assert.equal(templatePath("/accounts/asset"), "/accounts/asset");
        assert.equal(templatePath("/accounts/show/7/all"), "/accounts/show/{x}/{x}");
        assert.equal(templatePath("/Synthetic/show"), "/{x}/{x}");
        assert.equal(templatePath("/transactions/create?x=1#y"), "/transactions/create");
        assert.equal(templatePath("/"), "/");
    });
});

describe("noise filters (§6.3)", () => {
    it("never reports 401, 404, 419, 422, 429 or a cancelled request; 403 is WARN; 5xx and network are ERROR", async () => {
        const scope = fakeScope();
        installBrowserErrorFile({ scope });
        const api = fakeAxios();
        attachAxios(api);
        for (const status of [401, 404, 419, 422, 429]) {
            assert.equal(isExpectedHttpFailure(axiosError("get", "api/v1/about", status)), true);
            await assert.rejects(api.reject(axiosError("get", "api/v1/about", status)));
        }
        const cancelled = new AxiosError("canceled", "ERR_CANCELED", { method: "get", url: "api/v1/about" });
        cancelled.name = "CanceledError";
        await assert.rejects(api.reject(cancelled));
        await assert.rejects(api.reject(new AxiosError("Request aborted", "ECONNABORTED", { method: "get", url: "x" })));
        await assert.rejects(api.reject(axiosError("get", "api/v1/about", 403)));
        await assert.rejects(api.reject(axiosError("get", "api/v1/about", 503)));
        await assert.rejects(api.reject(axiosError("get", "api/v1/about", undefined, "ERR_NETWORK")));
        flushBrowserErrorFile(false);
        assert.deepEqual(fetchedEvents(scope).map((e) => [e.level, e.data.status ?? null]), [
            ["WARN", 403],
            ["ERROR", 503],
            ["ERROR", null],
        ]);
    });

    it("an expected HTTP failure that nobody catches is not reported by the rejection net either", () => {
        const scope = fakeScope();
        installBrowserErrorFile({ scope });
        scope.emit("unhandledrejection", { reason: axiosError("get", "api/v1/about", 404) });
        flushBrowserErrorFile(false);
        assert.equal(scope.posts.length, 0);
    });

    it("drops ResizeObserver, Script error. and /@vite/client, and anything while hidden", () => {
        const scope = fakeScope();
        installBrowserErrorFile({ scope });
        const benign = [
            { error: null, message: "ResizeObserver loop completed with undelivered notifications." },
            { error: null, message: "Script error." },
            { error: new Error("hmr"), filename: `${ORIGIN}/@vite/client` },
            { error: withStack(new Error("hmr"), `${ORIGIN}/@vite/client:10:1`) },
        ];
        for (const event of benign) {
            assert.equal(isBenignBrowserError(event), true);
            scope.emit("error", event);
        }
        scope.document.visibilityState = "hidden";
        scope.emit("error", { error: new Error("Synthetic while hidden") });
        scope.document.visibilityState = "visible";
        flushBrowserErrorFile(false);
        assert.equal(scope.posts.length, 0);
    });
});

describe("the report path", () => {
    it("does nothing before install, and the import installs nothing without window", () => {
        assert.doesNotThrow(() => errorFileFor("x.js").caught("doing a thing", new Error("x")));
        assert.equal(typeof globalThis.window, "undefined");
    });

    it("writes one object once (WeakSet), whichever net sees it first", async () => {
        const scope = fakeScope();
        installBrowserErrorFile({ scope });
        const api = fakeAxios();
        attachAxios(api);
        const err = axiosError("post", "api/v1/transactions", 500);
        await assert.rejects(api.reject(err), (e) => e === err);
        scope.emit("unhandledrejection", { reason: err });
        scope.emit("error", { error: err });
        flushBrowserErrorFile(false);
        assert.equal(fetchedEvents(scope).length, 1);
    });

    it("expected() records nothing but marks the object, so a net does not write it later", () => {
        const scope = fakeScope();
        installBrowserErrorFile({ scope });
        const err = new Error("Synthetic expected");
        errorFileFor(GLUE).expected("attaching the jQuery net", err);
        scope.emit("error", { error: err });
        flushBrowserErrorFile(false);
        assert.equal(scope.posts.length, 0);
    });

    it("keeps a forged newline on one line and caps where, doing and the stack", () => {
        const scope = fakeScope();
        installBrowserErrorFile({ scope });
        const frames = Array.from({ length: 30 }, (_, i) => `f${i}@${ORIGIN}/build/assets/dates-DabDFySV.js:1:${i}`);
        const err = new Error("x\n[2026-09-21T00:00:00.000Z] [ERROR] [php-web] forged");
        err.stack = frames.join("\n");
        errorFileFor("w".repeat(500)).caught("d".repeat(500), err);
        flushBrowserErrorFile(false);
        const [event] = fetchedEvents(scope);
        assert.equal(event.error.includes("\n"), false);
        assert.equal(event.where.length, 200);
        assert.equal(event.doing.length, 200);
        const lines = event.stack.split("\n");
        assert.equal(lines.length, 12);
        assert.equal(lines[0], "at f0 (build/assets/dates-DabDFySV.js:1:0)");
    });

    it("gives an ErrorEvent with no error object a one-frame stack", () => {
        const scope = fakeScope();
        installBrowserErrorFile({ scope });
        scope.emit("error", { error: null, message: "Uncaught SyntaxError: Unexpected token", filename: `${ORIGIN}/v1/js/ff/list/groups.js?v=6.1.20`, lineno: 3, colno: 9 });
        flushBrowserErrorFile(false);
        const [event] = fetchedEvents(scope);
        assert.equal(event.error, "ErrorEvent: Uncaught SyntaxError: Unexpected token");
        assert.equal(event.stack, "at v1/js/ff/list/groups.js:3:9");
        assert.deepEqual(event.data, { net: "window" });
    });

    it("the boot watchdog stays quiet once booted, or while hidden", () => {
        const scope = fakeScope();
        installBrowserErrorFile({ scope });
        startBootWatchdog(Object.assign(scope, { bootstrapped: true }));
        scope.advance(15000);
        const hidden = fakeScope();
        resetBrowserErrorFileForTests();
        installBrowserErrorFile({ scope: hidden });
        startBootWatchdog(Object.assign(hidden, { bootstrapped: false }));
        hidden.document.visibilityState = "hidden";
        hidden.advance(15000);
        assert.equal(scope.posts.length + hidden.posts.length + hidden.beacons.length, 0);
    });

    it("sends a batch after 2 s with one timer", () => {
        const scope = fakeScope();
        installBrowserErrorFile({ scope });
        errorFileFor(GLUE).caught("attaching the jQuery net", new Error("a"));
        errorFileFor(GLUE).warn("attaching the jQuery net");
        assert.equal(scope.timers.size, 1);
        scope.advance(1999);
        assert.equal(scope.posts.length, 0);
        scope.advance(1);
        assert.deepEqual(fetchedEvents(scope).map((e) => e.level), ["ERROR", "WARN"]);
        assert.equal(fetchedEvents(scope)[1].error, "");
    });
});

describe("axios, Alpine and jQuery nets", () => {
    it("rejects with the same object, passes success through, and attaches once per instance", async () => {
        installBrowserErrorFile({ scope: fakeScope() });
        const axios = fakeAxios();
        attachAxios(axios);
        attachAxios(axios);
        assert.equal(axios.handlers.length, 1);
        assert.equal(axios.handlers[0].onFulfilled, undefined);
        patchAxiosCreate(axios);
        patchAxiosCreate(axios);
        const site = axios.create({ baseURL: "/" });
        assert.equal(site.handlers.length, 1);
        attachAxios(site);
        assert.equal(site.handlers.length, 1);
        const err = axiosError("get", "api/v1/about", 500);
        await assert.rejects(site.reject(err), (e) => e === err);
    });

    it("reports a thrown Alpine expression exactly once and keeps Alpine's default behaviour", () => {
        const scope = fakeScope();
        installBrowserErrorFile({ scope });
        const alpine = fakeAlpine();
        attachAlpine(alpine);
        const err = new Error("Synthetic Alpine failure");
        alpine.handler(err, { id: "" }, "x".repeat(300));
        assert.equal(warn.mock.callCount(), 1);
        assert.match(warn.mock.calls[0].arguments[0], /^Alpine Expression Error: Synthetic Alpine failure/);
        const [[, timer]] = [...scope.timers.entries()].filter(([, t]) => t.at === T0);
        assert.throws(() => timer.fn(), (e) => e === err);
        scope.emit("error", { error: err });
        flushBrowserErrorFile(false);
        const reported = fetchedEvents(scope);
        assert.equal(reported.length, 1);
        assert.equal(reported[0].doing, "evaluating an Alpine expression");
        assert.equal(reported[0].data.expression.length, 120);
        assert.equal("element_id" in reported[0].data, false);
    });

    it("jQuery: an abort is not reported, a 422 is not, a network failure is ERROR", () => {
        const scope = fakeScope();
        installBrowserErrorFile({ scope });
        const $ = fakeJQuery();
        attachJQuery($, {});
        $.fail({ status: 0, statusText: "abort" }, { type: "POST", url: "json/x" });
        $.fail({ status: 422, statusText: "Unprocessable" }, { type: "POST", url: "transactions/store" });
        $.fail({ status: 0, statusText: "error" }, { url: "json/categories" });
        flushBrowserErrorFile(false);
        const [event, ...rest] = fetchedEvents(scope);
        assert.equal(rest.length, 0);
        assert.deepEqual([event.level, event.doing, event.error], ["ERROR", "calling GET json/categories", "AjaxError: Network Error"]);
    });
});

describe("transport and budget (§6.5)", () => {
    it("counts records over 30 per 60 s and sends one over-budget WARN per flush", async () => {
        const scope = fakeScope();
        installBrowserErrorFile({ scope });
        for (let i = 0; i < 35; i++) {
            errorFileFor(GLUE).caught("attaching the jQuery net", new Error(`n${i}`));
        }
        flushBrowserErrorFile(false);
        await settle();
        const all = fetchedEvents(scope);
        assert.equal(all.length, 31);
        assert.deepEqual(
            [all[30].level, all[30].where, all[30].doing, all[30].error],
            ["WARN", "/transactions/create", "sending error reports", "dropped 5 browser reports over the page budget of 30 per 60s"],
        );
        scope.advance(60000);
        errorFileFor(GLUE).caught("attaching the jQuery net", new Error("a new window"));
        flushBrowserErrorFile(false);
        await settle();
        assert.equal(fetchedEvents(scope).length, 32);
    });

    it("splits a batch before 60 KiB of UTF-8, one keepalive fetch at a time, and one huge record never sinks the rest", async () => {
        const resolvers = [];
        const scope = fakeScope({ fetchImpl: () => new Promise((resolve) => resolvers.push(resolve)) });
        installBrowserErrorFile({ scope });
        const wide = "€".repeat(5000);
        const huge = new Error(wide, { cause: new Error(wide) });
        huge.stack = Array.from({ length: 12 }, (_, i) => `    at ${ORIGIN}/build/assets/${wide.slice(0, 380)}${i}.js:1:1`).join("\n");
        errorFileFor("€".repeat(300)).caught("€".repeat(300), huge);
        for (let i = 0; i < BATCH_SIZE - 1; i++) {
            errorFileFor(GLUE).caught("attaching the jQuery net", new Error(`${"€".repeat(1900)} ${i}`));
        }
        assert.equal(scope.posts.length, 1, "the next batch waits for the in-flight keepalive fetch");
        while (resolvers.length > 0) {
            resolvers.shift()({ status: 204 });
            await settle();
        }
        assert.ok(scope.posts.length >= 2);
        for (const post of scope.posts) {
            assert.ok(new TextEncoder().encode(post.init.body).byteLength <= BATCH_BYTES);
            assert.ok(post.init.body.length < new TextEncoder().encode(post.init.body).byteLength, "bytes, not UTF-16 units");
        }
        assert.equal(fetchedEvents(scope).length, BATCH_SIZE);
    });

    it("a delivery failure is one console.warn per page, never re-queued and never thrown", async () => {
        const scope = fakeScope({ fetchImpl: () => Promise.reject(new Error("Synthetic offline")) });
        installBrowserErrorFile({ scope });
        errorFileFor(GLUE).caught("attaching the jQuery net", new Error("a"));
        flushBrowserErrorFile(false);
        await settle();
        errorFileFor(GLUE).caught("attaching the jQuery net", new Error("b"));
        flushBrowserErrorFile(false);
        await settle();
        assert.equal(scope.posts.length, 2);
        assert.equal(warn.mock.callCount(), 1);

        resetBrowserErrorFileForTests();
        const throwing = fakeScope({
            fetchImpl: () => {
                throw new Error("Synthetic sync throw");
            },
        });
        installBrowserErrorFile({ scope: throwing });
        assert.doesNotThrow(() => {
            errorFileFor(GLUE).caught("attaching the jQuery net", new Error("c"));
            flushBrowserErrorFile(false);
        });
    });

    it("a refused beacon falls back to the keepalive fetch; visibilitychange to hidden flushes", async () => {
        const scope = fakeScope({ beaconImpl: () => false });
        installBrowserErrorFile({ scope });
        errorFileFor(GLUE).caught("attaching the jQuery net", new Error("a"));
        scope.document.visibilityState = "hidden";
        scope.emit("visibilitychange");
        assert.equal(scope.beacons.length, 1);
        assert.equal(scope.posts.length, 1);
    });
});
