/*
 * error-file-app.js
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

// Fork: pm/error_err.mdx §6.1, §6.2 — the glue for session pages: the axios, Alpine and jQuery nets
// and the boot watchdog. `api` exists before this body runs (ESM evaluates boot/axios.js first), so
// it is covered only by the explicit attachAxios(api); patchAxiosCreate reaches later instances.
import axios from "axios";
import Alpine from "alpinejs";
import { api } from "../boot/axios.js";
import {
    attachAlpine,
    attachAxios,
    attachJQuery,
    errorFileFor,
    patchAxiosCreate,
    startBootWatchdog,
} from "./error-file.js";

const errors = errorFileFor("resources/assets/v3/js/support/error-file-app.js");

patchAxiosCreate(axios);
attachAxios(axios);
attachAxios(api);
attachAlpine(Alpine);

document.addEventListener("DOMContentLoaded", () => {
    try {
        if (window.jQuery) {
            attachJQuery(window.jQuery, document);
        }
    } catch (err) {
        errors.caught("attaching the jQuery net", err);
    }
    startBootWatchdog(window);
});
