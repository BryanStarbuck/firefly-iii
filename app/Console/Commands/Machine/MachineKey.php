<?php

/*
 * MachineKey.php
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

use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Machine\Credentials\CredentialsRefused;
use FireflyIII\Machine\ErrorFile\ErrorFile;
use Illuminate\Console\Command;

/**
 * php artisan firefly-machine:key {--init} {--rotate} {--show} — pm/apis.mdx §4.4, §4.8.
 *
 *   --init    mint the machine key into the credentials file if there is none (the same code path
 *             the web app runs on its first HTTP request); reuses an existing key
 *   --rotate  mint a new key unconditionally; outstanding confirm tokens die with the old one
 *   --show    (default) everything about the key except the key: file, mode, fingerprint, …
 *
 * The key itself is NEVER printed — only its fingerprint (§4.8).
 */
final class MachineKey extends Command
{
    protected $description = 'Initialise, rotate or describe the machine-plane key (~/.credentials/firefly_iii.json). Never prints the key.';

    protected $signature   = 'firefly-machine:key
                              {--init : Mint the key if the credentials file has none (reuses an existing key)}
                              {--rotate : Mint a new key unconditionally}
                              {--show : Describe the key (fingerprint only) — the default}';

    public function handle(): int
    {
        $picked = array_filter(['init' => (bool) $this->option('init'), 'rotate' => (bool) $this->option('rotate'), 'show' => (bool) $this->option('show')]);
        if (count($picked) > 1) {
            $this->error('Pick one of --init, --rotate, --show.');

            return self::INVALID;
        }
        $file = CredentialsFile::fromConfig();
        if (null === $file->path()) {
            $this->error('No credentials file is configured (set FIREFLY_MACHINE_CREDENTIALS_FILE).');

            return self::FAILURE;
        }

        try {
            if (isset($picked['init'])) {
                $result = $file->init(CredentialsFile::CREATED_BY);
                CredentialsFile::forget();
                $this->line(sprintf(
                    '%s %s (key %s)',
                    $result['created'] ? 'Minted a machine key in' : 'Machine key already present in',
                    CredentialsFile::tildify((string) $file->path()),
                    $result['fingerprint'],
                ));

                return self::SUCCESS;
            }
            if (isset($picked['rotate'])) {
                $result = $file->rotate(CredentialsFile::CREATED_BY);
                CredentialsFile::forget();
                $this->line(sprintf('Rotated the machine key in %s', CredentialsFile::tildify((string) $file->path())));
                $this->line(sprintf('  previous  %s', $result['previous'] ?? '(none)'));
                $this->line(sprintf('  new       %s', $result['fingerprint']));
                $this->line('Callers holding the old key get 401 from the next request; ffx and the MCP re-read the file.');

                return self::SUCCESS;
            }
        } catch (CredentialsRefused $e) {
            ErrorFile::for('app/Console/Commands/Machine/MachineKey.php')->expected('managing the machine key', $e);
            $this->error($e->getMessage());
            if ('' !== $e->fix) {
                $this->line('fix: '.$e->fix);
            }

            return self::FAILURE;
        }

        $info = $file->describe();
        foreach ($info as $field => $value) {
            $this->line(sprintf('  %-16s %s', $field, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
        }
        $active = CredentialsFile::resolve();
        $this->line(sprintf('  %-16s %s', 'plane key', null === $active ? 'none — the plane is unarmed ('.(CredentialsFile::problem() ?? '?').')' : $active->fingerprint().' from '.$active->source));

        return isset($info['problem']) ? self::FAILURE : self::SUCCESS;
    }
}
