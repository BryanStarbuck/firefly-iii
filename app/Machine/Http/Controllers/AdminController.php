<?php

/*
 * AdminController.php
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

use Carbon\Carbon;
use FireflyIII\Jobs\SendWebhookMessage;
use FireflyIII\Machine\Audit;
use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Machine\Credentials\CredentialsRefused;
use FireflyIII\Machine\Envelope;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Operator;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Webhook;
use FireflyIII\Models\WebhookMessage;
use FireflyIII\Repositories\User\UserRepositoryInterface;
use FireflyIII\Repositories\Webhook\WebhookRepositoryInterface;
use FireflyIII\Rules\IsBoolean;
use FireflyIII\Rules\Webhook\IsValidWebhookUrl;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\Support\Http\Controllers\CronRunner;
use FireflyIII\Support\JsonApi\Enrichments\WebhookEnrichment;
use FireflyIII\Transformers\UserTransformer;
use FireflyIII\Transformers\WebhookTransformer;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * pm/apis.mdx §8.11 — the admin tier (FIREFLY_MACHINE_ALLOW_ADMIN, never the MCP; TierGate
 * enforces both before any of this runs), and §8.10's webhook authoring.
 *
 * Each route calls the Firefly service upstream's own controller or command calls (R1):
 *
 *   POST /admin/key/rotate        CredentialsFile::rotate() — the answer is the fingerprint only
 *   POST /admin/cron              CronRunner (recurring, auto-budgets, bill warnings, exchange
 *                                 rates when enabled, webhooks) for the operator — no dry run
 *   POST /admin/correct-database  every corrector firefly-iii:correct-database runs, one by one,
 *                                 so the answer says what EACH changed; dry run by default
 *   POST /admin/data/destroy      upstream's DELETE /api/v1/data/destroy, in-process; dry run
 *                                 counts what would go, table by table
 *   POST /admin/data/purge        upstream's DELETE /api/v1/data/purge, in-process; dry run counts
 *   GET  /admin/users             UserRepository + UserTransformer
 *   GET  /admin/configuration     upstream's GET /api/v1/configuration, the editable values
 *   POST/PUT/DELETE /webhooks…    WebhookRepository, as upstream's webhook controllers
 *
 * Cron, corrections, destroy and purge cannot be reversed row by row, so each records an
 * `irreversible` marker in the operation log: /undo/last then says so instead of half-undoing.
 */
final class AdminController extends MachineController
{
    use CronRunner;

    /** The correctors firefly-iii:correct-database runs, in its order (CorrectsDatabase::handle). */
    public const array CORRECTORS = [
        'correction:timezones',
        'correction:create-group-memberships',
        'correction:group-information',
        'correction:piggy-banks',
        'correction:link-types',
        'correction:bills',
        'correction:amounts',
        'correction:currencies',
        'correction:transfer-budgets',
        'correction:uneven-amounts',
        'correction:zero-amounts',
        'correction:orphaned-transactions',
        'correction:empty-journals',
        'correction:empty-groups',
        'correction:account-types',
        'correction:ibans',
        'correction:account-order',
        'correction:meta-fields',
        'correction:opening-balance-currencies',
        'correction:long-descriptions',
        'correction:recurring-transactions',
        'correction:frontpage-accounts',
        'correction:group-accounts',
        'correction:recalculates-liabilities',
        'correction:preferences',
        'correction:corrects-inverted-budget-limits',
        'correction:recalculate-pc-amounts',
        'correction:remove-links-to-deleted-objects',
        'correction:clears-empty-foreign-amounts',
    ];

    /** Correctors the plane never runs: they write OAuth key files or mint access tokens (secrets, §16.2). */
    public const array SKIPPED_CORRECTORS = [
        'correction:restore-oauth-keys' => 'writes OAuth key files outside the database — run php artisan firefly-iii:correct-database for it',
        'correction:access-tokens'      => 'mints access tokens (secrets) — run php artisan firefly-iii:correct-database for it',
    ];

    /** Firefly's data/destroy object list (DestroyController::destroy). */
    public const array DESTROY_OBJECTS = [
        'budgets', 'bills', 'piggy_banks', 'rules', 'recurring', 'categories', 'tags', 'object_groups',
        'not_assets_liabilities', 'accounts', 'asset_accounts', 'expense_accounts', 'revenue_accounts', 'liabilities',
        'transactions', 'withdrawals', 'deposits', 'transfers',
    ];

    /** The tables data/destroy and data/purge change, counted for the preview (owner column). */
    private const array COUNTED_TABLES = [
        'accounts'            => 'user_id',
        'transaction_groups'  => 'user_id',
        'transaction_journals'=> 'user_id',
        'budgets'             => 'user_id',
        'bills'               => 'user_id',
        'rules'               => 'user_id',
        'rule_groups'         => 'user_id',
        'recurrences'         => 'user_id',
        'categories'          => 'user_id',
        'tags'                => 'user_id',
        'object_groups'       => 'user_id',
        'piggy_banks'         => null,
    ];

    // ------------------------------------------------------------ the key ---

    public function rotateKey(Request $request): JsonResponse
    {
        $this->input($request, []);
        $route = $this->routeKey($request);
        $key   = CredentialsFile::resolve();
        if (null === $key || 'credentials-file' !== $key->source) {
            Audit::line($request, ['route' => $route, 'tier' => 'admin', 'ok' => false, 'code' => 'conflict']);

            throw MachineException::conflict(
                sprintf('The machine key comes from %s, not the credentials file, so the plane cannot rotate it.', null === $key ? 'nowhere' : ('env' === $key->source ? 'FIREFLY_MACHINE_KEY' : 'FIREFLY_MACHINE_KEY_FILE')),
                'Change the key where it is set (or unset the override and rotate here), then restart the app',
                ['source' => $key?->source],
            );
        }

        try {
            $rotated = $this->locked(static fn (): array => CredentialsFile::fromConfig()->rotate('machine-plane (POST /admin/key/rotate)'), true);
        } catch (CredentialsRefused $e) {
            Audit::line($request, ['route' => $route, 'tier' => 'admin', 'ok' => false, 'code' => 'conflict']);

            throw MachineException::conflict('The credentials file could not be rewritten: '.Envelope::scrub($e->getMessage()), '' !== $e->fix ? $e->fix : 'Check the file\'s permissions (0600, owned by you)');
        }
        CredentialsFile::forget();
        Audit::line($request, ['route' => $route, 'tier' => 'admin', 'ok' => true, 'changed' => 1]);

        return $this->ok([
            'rotated'              => true,
            'fingerprint'          => $rotated['fingerprint'],
            'previous_fingerprint' => $rotated['previous'],
            'note'                 => 'The old key stopped working now, and every outstanding confirm token died with it. ffx and the MCP read the new key from the credentials file on their next call.',
        ]);
    }

    // ---------------------------------------------------------------- cron ---

    public function cron(Request $request): JsonResponse
    {
        $args = $this->input($request, ['force' => ['sometimes', 'boolean'], 'date' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return $this->write($request, $args, function (bool $dryRun) use ($args): WriteResult {
            $user  = $this->operator();
            $force = (bool) ($args['force'] ?? false);
            $date  = isset($args['date']) ? Carbon::createFromFormat('Y-m-d', (string) $args['date'])->startOfDay() : Carbon::now();
            $jobs  = [
                'recurring_transactions' => $this->runRecurring($user, $force, $date),
                'auto_budgets'           => $this->runAutoBudget($user, $force, $date),
                'bill_notifications'     => $this->billWarningCronJob($user, $force, $date),
                'webhooks'               => $this->webhookCronJob($user, $force, $date),
            ];
            if (true === AppConfiguration::get('enable_external_rates', config('cer.download_enabled'))->data) {
                $jobs['exchange_rates'] = $this->exchangeRatesCronJob($user, $force, $date);
            }
            $result = new WriteResult();
            $report = [];
            foreach ($jobs as $name => $job) {
                $fired         = (bool) ($job['job_fired'] ?? false);
                $report[$name] = [
                    'fired'     => $fired,
                    'succeeded' => (bool) ($job['job_succeeded'] ?? false),
                    'errored'   => (bool) ($job['job_errored'] ?? false),
                    'message'   => Envelope::scrub((string) ($job['message'] ?? '')),
                ];
                $result->count($fired ? 'jobs_fired' : 'skipped');
            }
            self::irreversible($result, 'cron', 'ran Firefly\'s cron', 'cron creates recurring transactions, budget limits and notifications that undo cannot trace — fix any of them by hand');

            return $result->with(['jobs' => $report, 'date' => $date->toDateString(), 'forced' => $force, 'undoable' => false]);
        });
    }

    // ---------------------------------------------------- correct-database ---

    public function correctDatabase(Request $request): JsonResponse
    {
        $args = $this->input($request, []);

        return $this->write($request, $args, static function (bool $dryRun): WriteResult {
            $known     = array_keys(Artisan::all());
            $result    = new WriteResult();
            $report    = [];
            $corrected = 0;
            foreach (self::CORRECTORS as $command) {
                if (!in_array($command, $known, true)) {
                    $report[] = ['command' => $command, 'ran' => false, 'changed' => 0, 'lines' => [], 'note' => 'this Firefly version has no such corrector'];

                    continue;
                }
                $code    = Artisan::call($command);
                $lines   = self::outputLines(Artisan::output());
                $changes = array_values(array_filter($lines, static fn (string $l): bool => !str_starts_with($l, '[✓]') && !str_starts_with($l, 'Now executing')));
                $corrected += count($changes);
                $report[] = ['command' => $command, 'ran' => true, 'exit_code' => $code, 'changed' => count($changes), 'lines' => $lines];
            }
            $result->count('corrected', $corrected);
            foreach (self::SKIPPED_CORRECTORS as $command => $why) {
                $report[] = ['command' => $command, 'ran' => false, 'changed' => 0, 'lines' => [], 'note' => $why];
            }
            $result->basis = array_map(static fn (array $r): array => [$r['command'], $r['lines']], $report);
            if ($corrected > 0) {
                self::irreversible($result, 'database', sprintf('corrected %d thing(s)', $corrected), 'integrity repairs are not recorded row by row — they are safe to leave in place');
            }

            return $result->with(['correctors' => $report, 'corrected' => $corrected, 'undoable' => false]);
        }, null);
    }

    // ------------------------------------------------------ destroy / purge ---

    public function destroyData(Request $request): JsonResponse
    {
        $args = $this->input($request, [
            'objects' => ['required', 'string', 'in:'.implode(',', self::DESTROY_OBJECTS)],
            'unused'  => ['sometimes', 'boolean'],
        ]);

        return $this->write($request, $args, function (bool $dryRun) use ($request, $args): WriteResult {
            $before = $this->liveCounts();
            $query  = ['objects' => (string) $args['objects']] + (true === ($args['unused'] ?? false) ? ['unused' => 'true'] : []);
            $this->upstreamWrite($request, 'DELETE', '/api/v1/data/destroy', $query);
            $after  = $this->liveCounts();
            $result = new WriteResult();
            $gone   = [];
            foreach ($before as $table => $n) {
                $d = max(0, $n - ($after[$table] ?? 0));
                if ($d > 0) {
                    $gone[$table] = $d;
                    $result->count($table, $d);
                }
            }
            if ([] !== $gone) {
                self::irreversible($result, (string) $args['objects'], sprintf('destroyed %s', (string) $args['objects']), 'data/destroy also removes links and pivots with raw deletes, so undo cannot rebuild it — restore from a backup');
            }

            return $result->with(['objects' => (string) $args['objects'], 'unused_only' => (bool) ($args['unused'] ?? false), 'destroyed' => (object) $gone, 'undoable' => false]);
        });
    }

    public function purgeData(Request $request): JsonResponse
    {
        $args = $this->input($request, []);

        return $this->write($request, $args, function (bool $dryRun) use ($request): WriteResult {
            $before = $this->trashedCounts();
            $this->upstreamWrite($request, 'DELETE', '/api/v1/data/purge', []);
            $after  = $this->trashedCounts();
            $result = new WriteResult();
            $gone   = [];
            foreach ($before as $table => $n) {
                $d = max(0, $n - ($after[$table] ?? 0));
                if ($d > 0) {
                    $gone[$table] = $d;
                    $result->count($table, $d);
                }
            }
            if ([] !== $gone) {
                self::irreversible($result, 'soft_deleted_rows', 'purged soft-deleted rows', 'purged rows are gone from the database for good');
            }

            return $result->with([
                'purged'   => (object) $gone,
                'undoable' => false,
                'warning'  => 'Purging permanently removes soft-deleted rows — after this, /undo can no longer restore any of those rows, including ones an earlier plane write deleted.',
            ]);
        });
    }

    // ----------------------------------------------------------------- reads ---

    public function users(Request $request): JsonResponse
    {
        $this->input($request, self::LIST_RULES, true);
        $params      = $this->listParams($request, ['id', 'email', 'created_at'], 'id');

        /** @var UserRepositoryInterface $repository */
        $repository  = app(UserRepositoryInterface::class);

        /** @var UserTransformer $transformer */
        $transformer = app(UserTransformer::class);
        $transformer->setParameters(new ParameterBag());
        $operatorId  = Operator::user($request)?->id;
        $rows        = [];
        foreach ($repository->all() as $user) {
            /** @var User $user */
            $row                  = $transformer->transform($user);
            unset($row['links']);
            $row['administration_id'] = null === $user->user_group_id ? null : (int) $user->user_group_id;
            $row['is_operator']       = $operatorId === $user->id;
            $rows[]                   = $row;
        }

        return $this->ok(['users' => $this->applyList($rows, $params), 'operator_setting' => 'FIREFLY_MACHINE_OPERATOR']);
    }

    public function configuration(Request $request): JsonResponse
    {
        $this->input($request, [], true);
        $response = MirrorController::dispatchInternal($request, 'GET', '/api/v1/configuration', [], null, ['Accept' => 'application/json']);
        $decoded  = json_decode((string) $response->getContent(), true);
        if ($response->getStatusCode() >= 400 || !is_array($decoded)) {
            throw MachineException::upstream('Firefly could not read its configuration.', 'Check storage/logs/laravel.log', ['upstream_status' => $response->getStatusCode()]);
        }
        $rows     = array_is_list($decoded) ? $decoded : (array) ($decoded['data'] ?? []);
        $values   = [];
        foreach ($rows as $row) {
            $title = (string) ($row['title'] ?? '');
            if (!str_starts_with($title, 'configuration.')) {
                continue;
            }
            $values[] = ['name' => substr($title, strlen('configuration.')), 'value' => MirrorController::scrubSecrets($row['value'] ?? null), 'editable' => (bool) ($row['editable'] ?? true)];
        }

        return $this->ok(['configuration' => $values, 'note' => 'Edit these in Firefly III (Administration → Configuration).']);
    }

    // -------------------------------------------------------------- webhooks ---

    public function createWebhook(Request $request): JsonResponse
    {
        $this->webhooksEnabled();
        $args = $this->input($request, self::webhookRules(null));

        return $this->write($request, $args, function (bool $dryRun) use ($args): WriteResult {
            $webhook = $this->webhooks()->store(self::webhookData($args, null));

            return (new WriteResult())->created($webhook)->count('created')->with(['webhook' => $this->renderWebhook($webhook)]);
        });
    }

    public function updateWebhook(Request $request, string $id): JsonResponse
    {
        $this->webhooksEnabled();
        $webhook = $this->webhook($id);
        $args    = $this->input($request, self::webhookRules($webhook));

        return $this->write($request, $args, function (bool $dryRun) use ($args, $webhook): WriteResult {
            $webhook = Webhook::query()->findOrFail($webhook->id);
            $result  = (new WriteResult())->updating($webhook)->count('updated');
            $webhook = $this->webhooks()->update($webhook, self::webhookData($args, $webhook));

            return $result->with(['webhook' => $this->renderWebhook($webhook)]);
        });
    }

    public function submitWebhook(Request $request, string $id): JsonResponse
    {
        $this->webhooksEnabled();
        $webhook = $this->webhook($id);
        $args    = $this->input($request, []);

        return $this->write($request, $args, function (bool $dryRun) use ($webhook): WriteResult {
            $messages = $this->webhooks()->getReadyMessages($webhook);
            $result   = new WriteResult();
            foreach ($messages as $message) {
                /** @var WebhookMessage $message */
                SendWebhookMessage::dispatch($message)->afterResponse();
                $result->count('queued');
            }
            if ($messages->count() > 0) {
                self::irreversible($result, 'webhook_message', 'sent webhook messages', 'a message that left this machine cannot be recalled');
            }

            return $result->with(['webhook_id' => (int) $webhook->id, 'queued' => $messages->count(), 'note' => 'Firefly sends at most three ready messages per submit; GET /machine/v1/webhooks/{id}/messages shows what is left.']);
        });
    }

    public function deleteWebhook(Request $request, string $id): JsonResponse
    {
        $args    = $this->input($request, []);
        $webhook = null;

        try {
            $webhook = $this->webhook($id);
        } catch (MachineException $e) {
            if ('not_found' !== $e->errorCode || 1 !== preg_match('/^\d+$/', trim($id))) {
                throw $e;
            }
        }
        if (null === $webhook) {
            // §5.6: a DELETE of something already gone is ok with deleted: 0, so a retry terminates
            return $this->ok(['deleted' => 0, 'webhook_id' => (int) $id]);
        }

        return $this->write($request, $args, function (bool $dryRun) use ($webhook): WriteResult {
            $webhook = Webhook::query()->findOrFail($webhook->id);
            $result  = new WriteResult();
            foreach ($webhook->webhookMessages()->get() as $message) {
                $result->deleting($message);
            }
            $result->deleting($webhook)->count('deleted');
            $this->webhooks()->destroy($webhook);

            return $result->with(['deleted' => 1, 'webhook_id' => (int) $webhook->id]);
        });
    }

    // ------------------------------------------------------------------------

    /**
     * Run an upstream /api/v1 WRITE in-process (data/destroy, data/purge), as the operator. Only
     * the admin routes above call this, with a fixed path — it is never reachable from input.
     *
     * @param array<string, mixed> $query
     */
    private function upstreamWrite(Request $request, string $method, string $uri, array $query): void
    {
        $response = MirrorController::dispatchInternal($request, $method, $uri, $query, null, ['Accept' => 'application/json', 'Content-Type' => 'application/json']);
        $status   = $response->getStatusCode();
        if ($status < 400) {
            return;
        }
        $decoded = json_decode((string) $response->getContent(), true);
        $message = Envelope::scrub(is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Firefly refused the request.');

        throw match (true) {
            401 === $status, 403 === $status => MachineException::forbidden('Firefly refused: '.$message, 'The operator needs the owner (full) role in this administration', ['upstream_status' => $status]),
            422 === $status, 400 === $status => MachineException::invalid('Firefly rejected the arguments: '.$message, 'Check objects against the accepted list', ['upstream_status' => $status]),
            default                          => MachineException::upstream('Firefly failed: '.$message, 'Nothing was written; see storage/logs/laravel.log', ['upstream_status' => $status]),
        };
    }

    /** @return array<string, int> live (not soft-deleted) rows per table for the operator */
    private function liveCounts(): array
    {
        return $this->counts(false);
    }

    /** @return array<string, int> soft-deleted rows per table for the operator */
    private function trashedCounts(): array
    {
        return $this->counts(true);
    }

    /** @return array<string, int> */
    private function counts(bool $trashed): array
    {
        $userId = $this->operator()->id;
        $out    = [];
        foreach (self::COUNTED_TABLES as $table => $owner) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $query = DB::table($table);
            if (null === $owner) {
                // piggy banks belong to accounts (piggy_bank_account pivot, or the older account_id column)
                $accountIds = DB::table('accounts')->where('user_id', $userId)->pluck('id')->all();
                if (Schema::hasColumn($table, 'account_id')) {
                    $query->whereIn('account_id', $accountIds);
                } elseif (Schema::hasTable('account_piggy_bank')) {
                    $query->whereIn('id', DB::table('account_piggy_bank')->whereIn('account_id', $accountIds)->pluck('piggy_bank_id')->all());
                } else {
                    continue;
                }
            } else {
                $query->where($owner, $userId);
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                $trashed ? $query->whereNotNull('deleted_at') : $query->whereNull('deleted_at');
            } elseif ($trashed) {
                continue;
            }
            $out[$table] = (int) $query->count();
        }

        return $out;
    }

    /** Mark an operation that undo must refuse, naming why (OperationLog 'irreversible'). */
    private static function irreversible(WriteResult $result, string $what, string $did, string $reason): void
    {
        $result->touched[] = ['class' => self::class, 'id' => 0, 'op' => 'irreversible', 'before' => ['what' => $what, 'did' => $did, 'reason' => $reason]];
    }

    /** @return list<string> the command's output, one trimmed line each, secrets scrubbed */
    private static function outputLines(string $output): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim((string) preg_replace('/\e\[[\d;]*m/', '', $line));
            if ('' !== $line) {
                $lines[] = Envelope::scrub($line);
            }
        }

        return $lines;
    }

    private function webhooksEnabled(): void
    {
        if (false === AppConfiguration::get('allow_webhooks', config('firefly.allow_webhooks'))->data) {
            throw MachineException::forbidden('Webhooks are switched off in this Firefly III install.', 'Turn them on in Firefly III (Administration → Configuration → "Allow webhooks"), then retry', ['setting' => 'allow_webhooks']);
        }
    }

    private function webhook(string $id): Webhook
    {
        /** @var Webhook $webhook */
        $webhook = $this->resolve(Webhook::class, urldecode($id), 'title', fn ($q) => $q->where('user_id', $this->operator()->id));

        return $webhook;
    }

    private function webhooks(): WebhookRepositoryInterface
    {
        /** @var WebhookRepositoryInterface $repository */
        $repository = app(WebhookRepositoryInterface::class);
        $repository->setUser($this->operator());

        return $repository;
    }

    /** @return array<string, mixed> WebhookTransformer's shape, without the signing secret (§16.2) */
    private function renderWebhook(Webhook $webhook): array
    {
        $enrichment = new WebhookEnrichment();
        $enrichment->setUser($this->operator());
        $webhook    = $enrichment->enrichSingle($webhook);

        /** @var WebhookTransformer $transformer */
        $transformer = app(WebhookTransformer::class);
        $transformer->setParameters(new ParameterBag());
        $row         = $transformer->transform($webhook);
        unset($row['links'], $row['secret']);
        $row['secret_note'] = 'The signing secret is shown in Firefly III (Automation → Webhooks), never on this API.';

        return $row;
    }

    /** @return array<string, mixed> the rules of upstream's webhook Create/UpdateRequest */
    private static function webhookRules(?Webhook $webhook): array
    {
        $triggers   = implode(',', array_values(Webhook::getTriggers()));
        $responses  = implode(',', array_values(Webhook::getResponses()));
        $deliveries = implode(',', array_values(Webhook::getDeliveries()));
        $protocols  = AppConfiguration::get('valid_url_protocols', config('firefly.valid_url_protocols'))->data;
        $required   = null === $webhook ? 'required' : 'sometimes';

        return [
            'title'        => [$required, 'string', 'min:1', 'max:255', null === $webhook ? 'uniqueObjectForUser:webhooks,title' : sprintf('uniqueObjectForUser:webhooks,title,%d', $webhook->id)],
            'active'       => ['sometimes', new IsBoolean()],
            'triggers'     => [$required, 'array', 'min:1', 'max:10'],
            'triggers.*'   => sprintf('required|in:%s', $triggers),
            'responses'    => [$required, 'array', 'min:1', 'max:1'],
            'responses.*'  => sprintf('required|in:%s', $responses),
            'deliveries'   => [$required, 'array', 'min:1', 'max:1'],
            'deliveries.*' => sprintf('required|in:%s', $deliveries),
            'url'          => array_values(array_filter([$required, 'string', sprintf('url:%s', $protocols), null === $webhook ? null : sprintf('uniqueExistingWebhook:%d', $webhook->id), new IsValidWebhookUrl()])),
        ];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed> the array WebhookRepository::store/update take
     */
    private static function webhookData(array $args, ?Webhook $webhook): array
    {
        $data = [
            'title'      => $args['title'] ?? $webhook?->title,
            'active'     => array_key_exists('active', $args) ? filter_var($args['active'], FILTER_VALIDATE_BOOLEAN) : (null === $webhook ? true : (bool) $webhook->active),
            'url'        => $args['url'] ?? $webhook?->url,
            'triggers'   => $args['triggers'] ?? ($webhook?->webhookTriggers()->pluck('title')->all() ?? []),
            'responses'  => $args['responses'] ?? ($webhook?->webhookResponses()->pluck('title')->all() ?? []),
            'deliveries' => $args['deliveries'] ?? ($webhook?->webhookDeliveries()->pluck('title')->all() ?? []),
        ];

        return $data;
    }
}
