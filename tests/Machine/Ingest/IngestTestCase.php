<?php

/*
 * IngestTestCase.php
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

namespace Tests\Machine\Ingest;

use FireflyIII\Models\TransactionCurrency;
use FireflyIII\User;
use Tests\Machine\MachineTestCase;

/**
 * The base of the ingest tests: an invented statements tree (tests/Machine/Ingest/Fixtures —
 * entities household / acme_llc, banks Northbank / Meridian) copied into the per-test sandbox,
 * configured as the statements root through the sandbox credentials file, USD as the primary
 * currency, and the write tier on.
 */
abstract class IngestTestCase extends MachineTestCase
{
    protected User $user;
    protected string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $usd        = TransactionCurrency::query()->where('code', 'USD')->firstOrFail();
        $usd->enabled = true;
        $usd->save();
        $this->user->userGroup->currencies()->syncWithoutDetaching([$usd->id => ['group_default' => true]]);
        $this->enableWrites();
    }

    /** Copy a fixture tree into the sandbox and make it THE statements root. */
    protected function useTree(string $name): string
    {
        $this->root = $this->sandbox.'/statements-'.$name;
        self::copyTree(__DIR__.'/Fixtures/'.$name, $this->root);
        $this->root = (string) realpath($this->root);
        $this->machineKey = $this->writeCredentials([
            'firefly_iii' => [
                'machine'    => ['api_key' => bin2hex(random_bytes(32)), 'created' => '2026-09-21T00:00:00.000Z', 'created_by' => 'test', 'label' => 'test'],
                'statements' => ['root' => $this->root],
            ],
        ]);

        return $this->root;
    }

    /** @return array<string, mixed> the envelope's data, asserting ok */
    protected function okData(string $method, string $path, array $body = []): array
    {
        $response = $this->machine($method, $path, $body);
        $env      = $this->envelope($response);
        $this->assertTrue($env['ok'], (string) $response->getContent());

        return $env['data'];
    }

    /** accounts/plan + accounts/apply for the whole manifest; returns the map key => account id. */
    protected function provisionAccounts(): array
    {
        $plan  = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $apply = $this->okData('POST', '/ingest/accounts/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
        $out   = [];
        foreach ($apply['plan'] as $p) {
            if (isset($p['account_id'])) {
                $out[$p['key']] = $p['account_id'];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> apply a fresh plan for real; returns the apply data */
    protected function planAndApply(array $body = [], int $maxChanges = 200): array
    {
        $plan = $this->okData('POST', '/ingest/plan', ['root' => $this->root] + $body);

        return $this->okData('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false, 'max_changes' => $maxChanges]);
    }

    protected static function copyTree(string $from, string $to): void
    {
        @mkdir($to, 0o700, true);
        foreach (scandir($from) ?: [] as $e) {
            if ('.' === $e || '..' === $e) {
                continue;
            }
            if (is_dir($from.'/'.$e)) {
                self::copyTree($from.'/'.$e, $to.'/'.$e);

                continue;
            }
            copy($from.'/'.$e, $to.'/'.$e);
        }
    }
}
