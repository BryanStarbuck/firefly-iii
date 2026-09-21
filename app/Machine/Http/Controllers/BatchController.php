<?php

/*
 * BatchController.php
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

use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventFlags;
use FireflyIII\Events\Model\TransactionGroup\UserRequestedBatchProcessing;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\RouteTable;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * pm/apis.mdx §7.4 — POST /batch: an ordered list of write operations, run inside ONE database
 * transaction, all or nothing, under ONE confirm token.
 *
 * Every operation is a real plane write route, dispatched in-process (MirrorController::
 * dispatchInternal) through the router and the gates, so it validates, resolves names and writes
 * exactly as it would on its own — no second implementation of any write. While a batch frame is
 * open, MachineController::write() hands each operation's WriteResult to the frame instead of
 * running the protocol itself; the batch's own write() owns the token, the ceiling (it counts
 * CHANGES, not operations), the lock, the transaction and the single operation-log row, so one
 * POST /undo reverses the whole batch.
 *
 * `op` names are a closed set (OPS), published in /capabilities as batchOps — and only while the
 * route an op runs is live, so a client is never offered an op that would answer not_ready.
 */
final class BatchController extends MachineController
{
    /**
     * op name => [METHOD, path, path-parameter => op field]. Everything else in the operation is
     * the route's own body. Only write-tier routes WITH a dry run can be batched.
     *
     * @var array<string, array{0: string, 1: string, 2?: array<string, string>}>
     */
    public const array OPS = [
        'transaction.create'      => ['POST', '/transactions'],
        'transaction.update'      => ['PUT', '/transactions/{group_id}', ['group_id' => 'group_id']],
        'transaction.convert'     => ['POST', '/transactions/{group_id}/convert', ['group_id' => 'group_id']],
        'transactions.categorize' => ['POST', '/transactions/categorize'],
        'transactions.set_budget' => ['POST', '/transactions/set-budget'],
        'transaction_link.create' => ['POST', '/transaction-links'],
        'account.create'          => ['POST', '/accounts'],
        'account.update'          => ['PUT', '/accounts/{id}', ['id' => 'account_id']],
        'category.create'         => ['POST', '/categories'],
        'category.update'         => ['PUT', '/categories/{id}', ['id' => 'category_id']],
        'budget.create'           => ['POST', '/budgets'],
        'budget.update'           => ['PUT', '/budgets/{id}', ['id' => 'budget_id']],
        'budget_limit.set'        => ['PUT', '/budgets/{id}/limits', ['id' => 'budget_id']],
        'available_budget.set'    => ['PUT', '/available-budgets'],
        'subscription.create'     => ['POST', '/subscriptions'],
        'subscription.update'     => ['PUT', '/subscriptions/{id}', ['id' => 'subscription_id']],
        'piggy_bank.create'       => ['POST', '/piggy-banks'],
        'piggy_bank.update'       => ['PUT', '/piggy-banks/{id}', ['id' => 'piggy_bank_id']],
        'piggy_bank.add'          => ['POST', '/piggy-banks/{id}/add', ['id' => 'piggy_bank_id']],
        'piggy_bank.remove'       => ['POST', '/piggy-banks/{id}/remove', ['id' => 'piggy_bank_id']],
        'tag.create'              => ['POST', '/tags'],
        'tag.update'              => ['PUT', '/tags/{tag}', ['tag' => 'tag']],
        'rule.create'             => ['POST', '/rules'],
        'rule.update'             => ['PUT', '/rules/{id}', ['id' => 'rule_id']],
        'rule_group.create'       => ['POST', '/rule-groups'],
        'rule_group.update'       => ['PUT', '/rule-groups/{id}', ['id' => 'rule_group_id']],
    ];

    public const int MAX_OPERATIONS = 500;

    /** @var null|array{dry_run: bool, results: list<array{route: string, result: WriteResult}>} */
    private static ?array $frame = null;

    /** @var array<string, array{0: string, 1: string, 2?: array<string, string>}> test seam only */
    private static array $testOps = [];

    /**
     * Every op this build knows (OPS, plus test-only ops under PHPUnit).
     *
     * @return array<string, array{0: string, 1: string, 2?: array<string, string>}>
     */
    public static function ops(): array
    {
        return self::OPS + self::$testOps;
    }

    /**
     * TEST SEAM ONLY — map an op onto a test-only write route (tests/Machine). Refuses outside PHPUnit.
     *
     * @param array{0: string, 1: string, 2?: array<string, string>} $spec
     */
    public static function registerTestOp(string $op, array $spec): void
    {
        $testing = 'testing' === ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV'));
        if (!$testing) {
            throw new \LogicException('BatchController::registerTestOp() is a PHPUnit seam and refuses outside tests.');
        }
        self::$testOps[$op] = $spec;
    }

    /** TEST SEAM ONLY — forget test ops. */
    public static function resetTestOps(): void
    {
        self::$testOps = [];
    }

    // ------------------------------------------------------------ the frame ---

    /** True while a batch is dispatching its operations (MachineController::write() checks it). */
    public static function active(): bool
    {
        return null !== self::$frame;
    }

    public static function dryRun(): bool
    {
        return (bool) (self::$frame['dry_run'] ?? true);
    }

    /** Called by MachineController::write() inside a batch: one operation's result. */
    public static function collect(string $routeKey, WriteResult $result): void
    {
        if (null === self::$frame) {
            throw MachineException::internal('A batch result arrived outside a batch.');
        }
        self::$frame['results'][] = ['route' => $routeKey, 'result' => $result];
    }

    /**
     * The op names whose route is live and batchable now.
     *
     * @return list<string>
     */
    public static function availableOps(): array
    {
        $ops = [];
        foreach (self::ops() as $op => $spec) {
            $def = RouteTable::find($spec[0], $spec[1]);
            if (null !== $def && $def->isLive() && 'write' === $def->tier && $def->dryRun) {
                $ops[] = $op;
            }
        }

        return $ops;
    }

    // ---------------------------------------------------------------- route ---

    public function batch(Request $request): JsonResponse
    {
        $args       = $this->input($request, [
            'operations'      => ['required', 'array', 'min:1', 'max:'.self::MAX_OPERATIONS],
            'operations.*'    => ['required', 'array'],
            'operations.*.op' => ['required', 'string'],
        ]);
        // the validated copy drops nested keys Laravel did not validate; the body is the truth
        $raw        = array_values((array) ($this->requestBody($request)['operations'] ?? []));
        $available  = self::availableOps();
        foreach ($raw as $i => $operation) {
            $this->checkOperation($i, (array) $operation, $available);
        }
        $args['operations'] = $raw;

        return $this->write($request, $args, function (bool $dryRun) use ($request, $raw): WriteResult {
            if (self::active()) {
                throw MachineException::invalid('A batch cannot contain a batch.', 'Flatten the operations into one list');
            }
            self::$frame = ['dry_run' => $dryRun, 'results' => []];

            try {
                $reports = [];
                foreach ($raw as $i => $operation) {
                    $reports[] = $this->runOperation($request, $i, (array) $operation);
                }
                $results = self::$frame['results'];
            } finally {
                self::$frame = null;
            }

            $merged = new WriteResult();
            foreach ($results as $i => $entry) {
                /** @var WriteResult $r */
                $r = $entry['result'];
                foreach ($r->changes as $kind => $n) {
                    $merged->count((string) $kind, (int) $n);
                }
                foreach ($r->touched as $t) {
                    $merged->touched[] = $t;
                }
                $merged->basis[] = [$entry['route'], $r->fingerprint()];
            }
            if (!$dryRun) {
                $this->finishBatch();
            }

            return $merged->with(['operations' => $reports, 'operation_count' => count($reports)]);
        });
    }

    // ------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $operation
     * @param list<string>         $available
     */
    private function checkOperation(int $i, array $operation, array $available): void
    {
        $op = (string) ($operation['op'] ?? '');
        if (!array_key_exists($op, self::ops())) {
            throw MachineException::invalid(
                sprintf('Operation %d: "%s" is not a batch op.', $i, $op),
                [] === $available ? 'No batch op is available on this build yet' : sprintf('Batch ops: %s (GET /machine/v1/capabilities lists them as batchOps)', implode(', ', $available)),
                ['index' => $i, 'op' => $op, 'available' => $available],
            );
        }
        if (!in_array($op, $available, true)) {
            $spec = self::ops()[$op];

            throw MachineException::notReady(
                sprintf('Operation %d: "%s" runs %s %s, which this build has not implemented yet.', $i, $op, $spec[0], $spec[1]),
                [] === $available ? 'No batch op is available on this build yet' : sprintf('Available now: %s', implode(', ', $available)),
                ['index' => $i, 'op' => $op, 'available' => $available],
            );
        }
        foreach (array_keys(self::CONTROL_RULES) as $control) {
            if (array_key_exists($control, $operation)) {
                throw MachineException::invalid(
                    sprintf('Operation %d carries "%s"; the write protocol belongs to the batch.', $i, $control),
                    'Put dry_run, confirm_token, max_changes and idempotency_key at the top level of the batch, not inside an operation',
                    ['index' => $i, 'field' => $control],
                );
            }
        }
        foreach (self::ops()[$op][2] ?? [] as $field) {
            $value = $operation[$field] ?? null;
            if (!is_scalar($value) || '' === trim((string) $value)) {
                throw MachineException::invalid(sprintf('Operation %d ("%s") needs "%s".', $i, $op, $field), sprintf('Add "%s": the id (or name) of the record to change', $field), ['index' => $i, 'field' => $field]);
            }
        }
    }

    /**
     * Run one operation through its own route, in-process. A refusal becomes the batch's refusal,
     * naming the index, the op and why — and (thrown inside the batch's transaction) rolls every
     * earlier operation back.
     *
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private function runOperation(Request $request, int $i, array $operation): array
    {
        $op     = (string) $operation['op'];
        $spec   = self::ops()[$op];
        $path   = $spec[1];
        $body   = $operation;
        unset($body['op']);
        foreach ($spec[2] ?? [] as $param => $field) {
            $path = str_replace('{'.$param.'}', rawurlencode((string) $operation[$field]), $path);
            unset($body[$field]);
        }
        $before = count(self::$frame['results'] ?? []);

        try {
            $response = MirrorController::dispatchInternal($request, $spec[0], '/machine/v1'.$path, [], $body, ['X-Firefly-Batch' => '1']);
        } catch (MachineException $e) {
            throw self::failed($i, $op, $e->errorCode, $e->getMessage(), $e->hint, $e->details);
        } catch (Throwable $e) {
            throw self::failed($i, $op, 'internal', 'The operation failed.', null, []);
        }
        $decoded = json_decode((string) $response->getContent(), true);
        if (!is_array($decoded) || true !== ($decoded['ok'] ?? null)) {
            $error   = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $details = is_array($error['details'] ?? null) ? $error['details'] : [];
            $message = (string) ($error['message'] ?? 'The operation failed.');
            $hint    = isset($error['hint']) ? (string) $error['hint'] : null;
            // A duplicate of a row an EARLIER operation of this same batch created: name that
            // operation, not a transaction group id that only existed inside the rolled-back plan.
            $twin    = isset($details['duplicate_of']) ? self::createdBy(TransactionGroup::class, $details['duplicate_of']) : null;
            if (null !== $twin) {
                $message = sprintf('this transaction is a duplicate of operation %d of this batch.', $twin);
                $hint    = sprintf('Operations %d and %d would store the same transaction (same date, amount, accounts and description) — drop one, or make them differ', $twin, $i);
                $details = ['duplicate_of_operation' => $twin] + array_diff_key($details, ['duplicate_of' => true]);
            }

            throw self::failed($i, $op, (string) ($error['code'] ?? 'internal'), $message, $hint, $details);
        }
        if (count(self::$frame['results'] ?? []) !== $before + 1) {
            throw self::failed($i, $op, 'internal', 'The operation did not report a write.', null, []);
        }
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return ['index' => $i, 'op' => $op, 'route' => $spec[0].' '.$spec[1], 'changes' => $data['changes'] ?? [], 'data' => $data];
    }

    /**
     * The index of the operation in the open frame that created row $id of $class, or null.
     * Each operation reports exactly one result, so a result's position is its operation index.
     *
     * @param class-string $class
     */
    private static function createdBy(string $class, mixed $id): ?int
    {
        foreach (self::$frame['results'] ?? [] as $index => $entry) {
            /** @var WriteResult $result */
            $result = $entry['result'];
            foreach ($result->touched as $t) {
                if ('created' === ($t['op'] ?? null) && $class === ($t['class'] ?? null) && (string) ($t['id'] ?? '') === (string) $id) {
                    return (int) $index;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $details */
    private static function failed(int $i, string $op, string $code, string $message, ?string $hint, array $details): MachineException
    {
        $message = sprintf('Operation %d ("%s") failed: %s Nothing in the batch was written.', $i, $op, rtrim($message));
        $hint  ??= 'Fix that operation and send the whole batch again';
        $details = ['index' => $i, 'op' => $op, 'cause' => $code] + $details;

        return match ($code) {
            'not_found'      => MachineException::notFound($message, $hint, $details),
            'conflict'       => MachineException::conflict($message, $hint, $details),
            'forbidden'      => MachineException::forbidden($message, $hint, $details),
            'write_disabled' => MachineException::forbidden($message, $hint, $details),
            'not_ready'      => MachineException::notReady($message, $hint, $details),
            'upstream_error' => MachineException::upstream($message, $hint, $details),
            'internal'       => MachineException::internal($message, $hint, $details),
            default          => MachineException::invalid($message, $hint, $details),
        };
    }

    /**
     * Firefly's batch-submission deferral (§7.4): journals stored as part of a batch submission
     * are left "uncompleted" and processed in one pass — the same event /api/v1/batch/finish fires.
     */
    private function finishBatch(): void
    {
        /** @var JournalRepositoryInterface $repository */
        $repository = app(JournalRepositoryInterface::class);
        $repository->setUser($this->operator());
        if (0 === count($repository->getUncompletedJournals())) {
            return;
        }
        $flags             = new TransactionGroupEventFlags();
        $flags->applyRules = true;
        event(new UserRequestedBatchProcessing($flags));
    }
}
