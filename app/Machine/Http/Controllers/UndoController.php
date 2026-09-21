<?php

/*
 * UndoController.php
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

namespace FireflyIII\Machine\Http\Controllers;

use FireflyIII\Machine\Audit;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\RouteTable;
use FireflyIII\Machine\Undo\OperationLog;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Account;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Models\AccountBalanceCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * pm/apis.mdx §7.5 — undo, for the plane's OWN writes (Firefly III has no undo stack).
 *
 *   GET /undo/last  the plan: the most recent un-reversed plane operation of this administration,
 *                   what each touched row would go back to, what BLOCKS it (a row changed since —
 *                   probably by a human in the browser) and, only when it can be undone, the
 *                   confirm_token POST /undo needs. Nothing to undo is an answer, not an error.
 *   POST /undo      no dry run (its plan is GET /undo/last) and the token is REQUIRED. It reverses
 *                   exactly the operation the plan named — and refuses `conflict` if a newer plane
 *                   write has happened since the plan, or a touched row changed (OperationLog
 *                   refuses rather than clobbers). Account balances touched by the reversal are
 *                   recalculated with Firefly's own calculator.
 */
final class UndoController extends MachineController
{
    public const string APPLY_ROUTE = 'POST /undo';

    public function last(Request $request): JsonResponse
    {
        $this->input($request, [], true);
        $last = OperationLog::last();
        if (null === $last) {
            return $this->ok([
                'operation_id'  => null,
                'reversible'    => false,
                'effects'       => [],
                'blocked_by'    => [],
                'confirm_token' => null,
                'description'   => 'Nothing to undo: no plane write (ffx or the MCP) is recorded for this administration in the last 30 days. Changes made in the Firefly web UI are never in this log.',
            ]);
        }
        $data = $last + ['tier' => self::tierOf((string) $last['route']), 'description' => self::describe($last)];
        if (true === $last['reversible']) {
            return $this->ok($data + $this->planToken($request, self::APPLY_ROUTE, [], self::planFingerprint($last), ['operation_id' => $last['operation_id']]));
        }

        return $this->ok($data + ['confirm_token' => null, 'expires_at' => null]);
    }

    public function undo(Request $request): JsonResponse
    {
        $args = $this->input($request, ['confirm_token' => ['required', 'string', 'max:64']]);
        unset($args['confirm_token']);
        $this->refuseBelowTier($request, OperationLog::last());

        return $this->write($request, $args, static function (bool $dryRun) use ($request): WriteResult {
            $entry   = (array) $request->attributes->get('machine.confirm', []);
            $planned = (int) ($entry['payload']['operation_id'] ?? 0);
            $current = OperationLog::last();
            if (null === $current || (int) $current['operation_id'] !== $planned) {
                throw MachineException::conflict(
                    sprintf('The plan was to undo operation #%d, but the most recent plane write is now %s — nothing was undone.', $planned, null === $current ? 'none' : '#'.$current['operation_id']),
                    'Re-plan: GET /machine/v1/undo/last, read what it would undo, then POST /machine/v1/undo with the new token',
                    ['planned_operation_id' => $planned, 'last_operation_id' => $current['operation_id'] ?? null],
                );
            }
            if (!hash_equals((string) ($entry['fingerprint'] ?? ''), self::planFingerprint($current))) {
                throw MachineException::conflict(
                    sprintf('Operation #%d changed since the plan (a touched row was edited) — nothing was undone.', $planned),
                    'Re-plan: GET /machine/v1/undo/last shows what blocks it now',
                    ['operation_id' => $planned, 'blocked_by' => $current['blocked_by']],
                );
            }

            $accounts = self::accountsTouched($current['operation_id']);
            $done     = OperationLog::reverse($planned);
            $accounts = array_values(array_unique(array_merge($accounts, self::accountsTouched($current['operation_id']))));
            self::recalculate($accounts);

            $undone   = (array) ($done['undone'] ?? []);
            $changes  = [];
            foreach (['deleted', 'restored', 'reinserted'] as $kind) {
                if ((int) ($undone[$kind] ?? 0) > 0) {
                    $changes[$kind] = (int) $undone[$kind];
                }
            }

            return new WriteResult($changes, [
                'operation_id'      => $planned,
                'route'             => $done['route'] ?? $current['route'],
                'undone'            => $undone,
                'effects'           => $current['effects'],
                'accounts_rebalanced' => count($accounts),
                'description'       => sprintf('Undid operation #%d (%s): %s.', $planned, (string) $current['route'], self::summarise($undone)),
            ]);
        }, null, ['record' => false, 'require_token' => true]);
    }

    // ------------------------------------------------------------------------

    /** "POST /webhooks" → admin: the tier of the route that made an operation (unknown routes count as write). */
    private static function tierOf(string $routeKey): string
    {
        $parts = explode(' ', trim($routeKey), 2);
        $def   = 2 === count($parts) ? RouteTable::find($parts[0], $parts[1]) : null;

        return null === $def ? 'write' : $def->tier;
    }

    /**
     * §7.6: every destructive delete is admin-tier and the MCP never has it. Undoing an admin
     * write (a webhook created by the operator in a terminal) deletes what it made, so it needs
     * the admin tier too — the write tier must not become a back door to it.
     *
     * @param null|array<string, mixed> $last
     */
    private function refuseBelowTier(Request $request, ?array $last): void
    {
        if (null === $last || 'admin' !== self::tierOf((string) $last['route'])) {
            return;
        }
        $route   = (string) $last['route'];
        $refusal = null;
        if ('mcp' === Audit::caller($request)) {
            $refusal = MachineException::forbidden(
                sprintf('The last plane write was %s, an admin route — undoing it is admin work, which is never available to the MCP.', $route),
                'Undo it yourself in a terminal (ffx undo, with FIREFLY_MACHINE_ALLOW_ADMIN=1) or in the Firefly III web UI',
                ['operation_id' => $last['operation_id'], 'route' => $route, 'tier' => 'admin'],
            );
        } elseif (true !== (bool) config('machine.allow_admin')) {
            $refusal = MachineException::forbidden(
                sprintf('The last plane write was %s, an admin route — undoing it needs the admin tier, which is off on this server.', $route),
                'Set FIREFLY_MACHINE_ALLOW_ADMIN=1 in the app\'s .env and restart it (ffx stop && ffx up), then retry',
                ['operation_id' => $last['operation_id'], 'route' => $route, 'tier' => 'admin', 'switch' => 'FIREFLY_MACHINE_ALLOW_ADMIN'],
            );
        }
        if (null !== $refusal) {
            Audit::line($request, ['route' => self::APPLY_ROUTE, 'tier' => 'admin', 'ok' => false, 'code' => $refusal->errorCode]);

            throw $refusal;
        }
    }

    /**
     * The token's fingerprint: the operation, and the state of every row it touched as the plan
     * saw it — so a row edited between plan and apply refuses the token.
     *
     * @param array<string, mixed> $preview
     */
    private static function planFingerprint(array $preview): string
    {
        return 'sha256:'.substr(hash('sha256', json_encode([$preview['operation_id'], $preview['effects'], $preview['blocked_by']]) ?: ''), 0, 32);
    }

    /** @param array<string, mixed> $preview */
    private static function describe(array $preview): string
    {
        $counts = [];
        foreach ((array) $preview['effects'] as $effect) {
            $key          = sprintf('%s %s', (string) ($effect['did'] ?? 'changed'), (string) ($effect['entity'] ?? 'row'));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $parts  = [];
        foreach ($counts as $what => $n) {
            $parts[] = sprintf('%d × %s', $n, $what);
        }
        $did    = [] === $parts ? 'changed nothing that undo can see' : implode(', ', $parts);
        $text   = sprintf('Operation #%d (%s, %s) %s.', (int) $preview['operation_id'], (string) $preview['route'], (string) $preview['at'], $did);
        if ([] !== $preview['blocked_by']) {
            return $text.sprintf(' It CANNOT be undone: %d row(s) block it (see blocked_by) — most likely edited in the browser since, and undo never destroys a later change.', count($preview['blocked_by']));
        }

        return $text.' Undo would reverse exactly this, and nothing made in the Firefly web UI.';
    }

    /** @param array<string, mixed> $undone */
    private static function summarise(array $undone): string
    {
        $parts = [];
        foreach (['deleted' => 'deleted %d created row(s)', 'restored' => 'restored %d row(s) to their previous values', 'reinserted' => 're-inserted %d row(s)', 'already_gone' => '%d row(s) were already gone'] as $k => $f) {
            if ((int) ($undone[$k] ?? 0) > 0) {
                $parts[] = sprintf($f, (int) $undone[$k]);
            }
        }

        return [] === $parts ? 'nothing needed changing' : implode(', ', $parts);
    }

    /**
     * The accounts whose transactions the operation touched (read from the rows as they are now,
     * and from the before-images), so their running balances can be recalculated after undo.
     *
     * @return list<int>
     */
    private static function accountsTouched(int $operationId): array
    {
        try {
            $row = DB::table(OperationLog::TABLE)->where('id', $operationId)->first();
            if (null === $row) {
                return [];
            }
            $touched      = json_decode((string) $row->touched, true);
            $accounts     = [];
            $transactions = [];
            $journals     = [];
            $groups       = [];
            foreach (is_array($touched) ? $touched : [] as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $class  = (string) ($t['class'] ?? '');
                $id     = $t['id'] ?? null;
                $before = is_array($t['before'] ?? null) ? $t['before'] : [];
                switch ($class) {
                    case Transaction::class:
                        $accounts[]     = (int) ($before['account_id'] ?? 0);
                        $transactions[] = $id;

                        break;

                    case TransactionJournal::class:
                        $journals[] = $id;

                        break;

                    case TransactionGroup::class:
                        $groups[] = $id;

                        break;

                    case Account::class:
                        $accounts[] = (int) $id;

                        break;
                }
            }
            // one query per kind, never one per touched row (an operation may touch thousands)
            foreach (array_chunk($transactions, 500) as $chunk) {
                $accounts = array_merge($accounts, DB::table('transactions')->whereIn('id', $chunk)->pluck('account_id')->map(static fn ($v): int => (int) $v)->all());
            }
            foreach (array_chunk($groups, 500) as $chunk) {
                $journals = array_merge($journals, DB::table('transaction_journals')->whereIn('transaction_group_id', $chunk)->pluck('id')->all());
            }
            foreach (array_chunk(array_values(array_unique($journals)), 500) as $chunk) {
                $accounts = array_merge($accounts, DB::table('transactions')->whereIn('transaction_journal_id', $chunk)->pluck('account_id')->map(static fn ($v): int => (int) $v)->all());
            }

            return array_values(array_filter(array_unique($accounts), static fn (int $id): bool => $id > 0));
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<int> $accountIds */
    private static function recalculate(array $accountIds): void
    {
        if ([] === $accountIds) {
            return;
        }
        $accounts = Account::query()->whereIn('id', $accountIds)->get();
        if ($accounts->isNotEmpty()) {
            AccountBalanceCalculator::optimizedCalculation($accounts);
        }
        Preferences::mark();
    }
}
