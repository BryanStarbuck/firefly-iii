<?php

/*
 * JsonTranslations.php
 * Copyright (c) 2026 The Firefly III machine-plane contributors
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
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

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Machine;

use Illuminate\Console\Command;

/**
 * php artisan firefly-machine:json-translations — writes public/v3/i18n/{locale}.json.
 *
 * The v3 front end loads its i18next strings from those files (resources/assets/v3/js/support/
 * load-translations.js). Upstream generates them only in its release pipeline, with an external
 * tool, and git-ignores them — so a source checkout has none, every i18next.t() returns its raw key,
 * and date-fns throws on keys like "config.month_and_day_fns" ("unescaped latin alphabet character
 * `n`"), which breaks every chart. This rebuilds them from config('translations.json.v3').
 */
final class JsonTranslations extends Command
{
    protected $description = 'Generate the v3 front end\'s i18next JSON files (public/v3/i18n/*.json) from resources/lang.';

    protected $signature   = 'firefly-machine:json-translations';

    public function handle(): int
    {
        $groups = (array) config('translations.json.v3');
        $dir    = public_path('v3/i18n');
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->error(sprintf('Cannot create %s', $dir));

            return self::FAILURE;
        }
        $locales = array_filter((array) scandir(lang_path()), static fn ($entry): bool => is_string($entry) && 1 === preg_match('/^[a-z]{2}_[A-Z]{2}$/', $entry));
        foreach ($locales as $locale) {
            $json = [];
            foreach ($groups as $group => $keys) {
                foreach ((array) $keys as $key) {
                    $full  = sprintf('%s.%s', $group, $key);
                    $value = trans($full, [], $locale);
                    if (is_string($value) && $value !== $full) {
                        $json[$group][$key] = $value;
                    }
                }
            }
            $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            // i18next asks for "en_US" and falls back to "en"; write both.
            foreach ([$locale, substr($locale, 0, 2)] as $name) {
                file_put_contents(sprintf('%s/%s.json', $dir, $name), $encoded."\n");
            }
            $this->line(sprintf('Wrote %s/%s.json', $dir, $locale));
        }

        return self::SUCCESS;
    }
}
