#!/usr/bin/env node
// scripts/web-bundle-fresh.mjs — pm/error_err.mdx §6.6.
//
// Exits 0 when public/build/manifest.json is newer than every file under resources/assets/v3/js and
// resources/assets/v3/sass, so `just build-web` can skip the Vite build and `just build` stays fast.
// Exits 1 when the bundle is missing or stale (the recipe then runs `npm run build`). Read-only.

import { readdirSync, statSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const manifest = join(root, "public", "build", "manifest.json");
const sources = ["js", "sass"].map((dir) => join(root, "resources", "assets", "v3", dir));

function newest(dir) {
    let latest = 0;
    let entries;
    try {
        entries = readdirSync(dir, { withFileTypes: true });
    } catch {
        return latest;
    }
    for (const entry of entries) {
        const path = join(dir, entry.name);
        const mtime = entry.isDirectory() ? newest(path) : statSync(path).mtimeMs;
        latest = Math.max(latest, mtime);
    }
    return latest;
}

let built;
try {
    built = statSync(manifest).mtimeMs;
} catch {
    console.log("  web          public/build/manifest.json missing — building the v3 bundle");
    process.exit(1);
}
const latest = Math.max(...sources.map(newest));
if (latest > built) {
    console.log("  web          the v3 bundle is older than resources/assets/v3 — rebuilding");
    process.exit(1);
}
console.log("  web          public/build is fresh");
