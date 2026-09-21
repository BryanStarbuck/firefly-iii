<?php

/*
 * MachineController.php
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

use Closure;
use FireflyIII\Machine\Audit;
use FireflyIII\Machine\Clock;
use FireflyIII\Machine\Confirm\ConfirmTokens;
use FireflyIII\Machine\DryRun;
use FireflyIII\Machine\Envelope;
use FireflyIII\Machine\Idempotency;
use FireflyIII\Machine\ListParams;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Machine\Operator;
use FireflyIII\Machine\Undo\OperationLog;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The base of every plane controller — the helpers that make R1–R10 cheap to obey:
 *
 *   input()       gate 5: unknown top-level fields REJECTED (§5.7), Laravel rules, invalid_input
 *   listParams()  §5.5: limit (default 200, clamped to 5,000), offset, order ("-field")
 *   applyList()   runs the list, appends the `id` tie-break, sets meta.truncated / limit_applied
 *   ok()          the success envelope with the standard meta (§5.1)
 *   resolve()     §8/§14.4: numeric id, else exact name, else case-insensitive; ambiguity is an error
 *   write()       THE write protocol (§7): dry run by default, confirm token, fingerprint, ceiling,
 *                 write lock, one DB transaction, operation log, audit line, idempotency
 */
abstract class MachineController extends Controller
{
    /** The write-protocol control fields every dry-run route accepts beside its own arguments. */
    public const array CONTROL_RULES = [
        'dry_run'         => ['sometimes', 'boolean'],
        'confirm_token'   => ['sometimes', 'nullable', 'string', 'max:64'],
        'max_changes'     => ['sometimes', 'integer', 'min:0', 'max:100000000'],
        'idempotency_key' => ['sometimes', 'nullable', 'string', 'min:1', 'max:128'],
    ];

    public const string ATTR_META = 'machine.meta';

    // ------------------------------------------------------------------ input ---

    /**
     * Validate a route's arguments (gate 5). Unknown top-level fields are refused — a typo'd
     * `start_date` that silently means "no filter" is how a decade lands in one month (§5.7).
     *
     * On a route that takes a dry run, the control fields (dry_run, confirm_token, max_changes,
     * idempotency_key) are accepted automatically and returned with the arguments.
     *
     * @param array<string, mixed> $rules Laravel rules; nested keys ("transactions.*.amount") allowed
     * @param bool                 $query validate the query string (GET) instead of the JSON body
     *
     * @return array<string, mixed> the validated arguments
     */
    protected function input(Request $request, array $rules, bool $query = false): array
    {
        $source = $query ? $request->query->all() : $this->requestBody($request);
        if ($this->routeTakesDryRun($request)) {
            $rules += self::CONTROL_RULES;
        }
        $known   = [];
        foreach (array_keys($rules) as $key) {
            $known[explode('.', (string) $key, 2)[0]] = true;
        }
        $unknown = array_values(array_diff(array_map('strval', array_keys($source)), array_keys($known)));
        if ([] !== $unknown) {
            $knownList = array_keys($known);
            sort($knownList);

            throw MachineException::invalid(
                sprintf('Unknown argument%s: %s.', 1 === count($unknown) ? '' : 's', implode(', ', $unknown)),
                [] === $knownList ? 'This route takes no arguments' : sprintf('This route accepts: %s', implode(', ', $knownList)),
                ['unknown' => $unknown, 'accepted' => $knownList],
            );
        }
        $source    = $this->normaliseBooleans($source, $rules);
        $validator = Validator::make($source, $rules);
        if ($validator->fails()) {
            $fields = $validator->errors()->toArray();
            $first  = null;
            foreach ($fields as $messages) {
                $first = (string) ($messages[0] ?? '');

                break;
            }

            throw MachineException::invalid('Invalid input.', '' === (string) $first ? 'Check the arguments' : $first, ['fields' => $fields]);
        }

        return $validator->validated();
    }

    /** @return array<string, mixed> the JSON body as an object (a list or scalar body is refused) */
    protected function requestBody(Request $request): array
    {
        if ($request->isJson()) {
            $content = trim((string) $request->getContent());
            if ('' === $content) {
                return [];
            }
            $all = $request->json()->all();
            if ('{' !== $content[0]) {
                throw MachineException::invalid('The request body must be a JSON object.', 'Send {"field": value, …} with Content-Type: application/json');
            }

            return $all;
        }

        return $request->request->all();
    }

    /**
     * "true"/"false" (a query string, or a sloppy client) become booleans for fields whose
     * rule is `boolean`; Laravel's boolean rule accepts true/false/1/0 but not the words.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $rules
     *
     * @return array<string, mixed>
     */
    private function normaliseBooleans(array $source, array $rules): array
    {
        foreach ($rules as $field => $rule) {
            $ruleList = is_array($rule) ? $rule : explode('|', (string) $rule);
            if (!in_array('boolean', array_map(static fn ($r): string => is_string($r) ? $r : '', $ruleList), true)) {
                continue;
            }
            if (str_contains((string) $field, '.') || !array_key_exists($field, $source) || !is_string($source[$field])) {
                continue;
            }
            $v = strtolower(trim($source[$field]));
            if (in_array($v, ['true', 'yes', 'on'], true)) {
                $source[$field] = true;
            }
            if (in_array($v, ['false', 'no', 'off'], true)) {
                $source[$field] = false;
            }
        }

        return $source;
    }

    // ------------------------------------------------------------------- lists ---

    /**
     * The universal list arguments (§5.5). Reads limit/offset/order from the query string. Call
     * input() for the route's own arguments with 'limit', 'offset' and 'order' among its rules
     * (or use LIST_RULES) so they are not refused as unknown.
     *
     * @param array<int|string, string> $orderable the orderable public field names, or a map of
     *                                             public name => column ("date" => "transaction_journals.date")
     * @param string                    $default   "-date", "name", …
     */
    protected function listParams(Request $request, array $orderable, string $default): ListParams
    {
        $max      = (int) config('machine.limits.max_limit', 5000);
        $fallback = (int) config('machine.limits.default_limit', 200);
        $map      = [];
        foreach ($orderable as $k => $v) {
            $map[is_int($k) ? $v : $k] = $v;
        }

        $rawLimit  = $request->query('limit');
        $requested = $fallback;
        if (null !== $rawLimit && '' !== $rawLimit) {
            if (!is_string($rawLimit) || 1 !== preg_match('/^\d{1,9}$/', $rawLimit)) {
                throw MachineException::invalid('limit must be a whole number.', sprintf('Pass limit=1..%d (larger values are clamped, never refused)', $max), ['field' => 'limit']);
            }
            $requested = intval($rawLimit);
        }
        $limit     = max(1, min($max, $requested));

        $rawOffset = $request->query('offset');
        $offset    = 0;
        if (null !== $rawOffset && '' !== $rawOffset) {
            if (!is_string($rawOffset) || 1 !== preg_match('/^\d{1,9}$/', $rawOffset)) {
                throw MachineException::invalid('offset must be a whole number ≥ 0.', 'Pass offset=0, 200, 400… with a stable order', ['field' => 'offset']);
            }
            $offset = intval($rawOffset);
        }

        $order     = $request->query('order');
        $order     = is_string($order) && '' !== trim($order) ? trim($order) : $default;
        $desc      = str_starts_with($order, '-');
        $field     = ltrim($order, '-+');
        if (!array_key_exists($field, $map)) {
            $names = array_keys($map);

            throw MachineException::invalid(
                sprintf('Cannot order by "%s".', $field),
                [] === $names ? 'This list has a fixed order' : sprintf('order accepts: %s (prefix "-" for descending)', implode(', ', $names)),
                ['field' => 'order', 'accepted' => $names],
            );
        }

        return new ListParams($limit, $offset, $field, $desc, $requested, $requested > $max, $map[$field]);
    }

    /** Rules for the universal list arguments, to add to a list route's input() rules. */
    protected const array LIST_RULES = [
        'limit'  => ['sometimes', 'nullable'],
        'offset' => ['sometimes', 'nullable'],
        'order'  => ['sometimes', 'nullable', 'string', 'max:64'],
    ];

    /**
     * Run a list with the order (plus an `id` tie-break, so the order is total), offset and
     * limit applied, and record meta.truncated / limit_applied (R5). Accepts an Eloquent or
     * query builder (the query is run) or an array/Collection of rows (sorted and sliced here).
     *
     * @param EloquentBuilder<Model>|QueryBuilder|Relation<Model, Model, mixed>|Collection<int, mixed>|array<int, mixed> $source
     *
     * @return Collection<int, mixed>|list<mixed> a Collection for a builder, a list for an array
     */
    protected function applyList(mixed $source, ListParams $params, string $idColumn = 'id'): array|Collection
    {
        if ($source instanceof EloquentBuilder || $source instanceof QueryBuilder || $source instanceof Relation) {
            $query = clone $source;
            $query->orderBy($params->column(), $params->direction());
            if ($params->column() !== $idColumn && $params->orderField !== 'id') {
                $query->orderBy($idColumn, 'asc');
            }
            $rows      = $query->offset($params->offset)->limit($params->limit + 1)->get();
            $truncated = $rows->count() > $params->limit;
            $rows      = $rows->slice(0, $params->limit)->values();
            $this->recordListMeta($params, $truncated, $rows->count());

            return $rows;
        }

        $isCollection = $source instanceof Collection;
        $rows         = $isCollection ? $source->values()->all() : array_values((array) $source);
        $column       = $params->column();
        usort($rows, static function (mixed $a, mixed $b) use ($column, $params, $idColumn): int {
            $cmp = self::compareValues(self::field($a, $column), self::field($b, $column));
            if ($params->descending) {
                $cmp = -$cmp;
            }

            return 0 !== $cmp ? $cmp : self::compareValues(self::field($a, $idColumn), self::field($b, $idColumn));
        });
        $truncated    = count($rows) > $params->offset + $params->limit;
        $page         = array_slice($rows, $params->offset, $params->limit);
        $this->recordListMeta($params, $truncated, count($page));

        return $isCollection ? collect($page) : $page;
    }

    private function recordListMeta(ListParams $params, bool $truncated, int $returned): void
    {
        $meta = [
            'truncated'     => $truncated,
            'limit_applied' => $params->limit,
            'offset'        => $params->offset,
            'order'         => $params->order(),
            'count'         => $returned,
            'next_offset'   => $truncated ? $params->offset + $params->limit : null,
        ];
        if ($params->clamped) {
            $meta['limit_requested'] = $params->requestedLimit;
        }
        $this->addMeta($meta);
    }

    private static function field(mixed $row, string $column): mixed
    {
        if (is_array($row)) {
            return $row[$column] ?? null;
        }
        if (is_object($row)) {
            return $row->{$column} ?? null;
        }

        return null;
    }

    /** Nulls last; decimal strings with bcmath (never floats); otherwise natural, case-insensitive. */
    private static function compareValues(mixed $a, mixed $b): int
    {
        if (null === $a || null === $b) {
            return (null === $a) <=> (null === $b);
        }
        if (is_int($a) && is_int($b)) {
            return $a <=> $b;
        }
        $sa = is_scalar($a) ? (string) $a : Envelope::encode($a);
        $sb = is_scalar($b) ? (string) $b : Envelope::encode($b);
        if (Money::isDecimal($sa) && Money::isDecimal($sb)) {
            return Money::compare($sa, $sb);
        }

        return strnatcasecmp($sa, $sb);
    }

    // ---------------------------------------------------------------- envelope ---

    /**
     * The success envelope with the standard meta (§5.1): target, operator, administrationId,
     * administrationName, primaryCurrency, serverVersion, planeVersion, asOf, tookMs, truncated,
     * tier, untrusted — plus whatever a list recorded and $meta adds.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    protected function ok(array $data, array $meta = []): JsonResponse
    {
        return Envelope::ok($data, array_merge(self::standardMeta(request()), (array) request()->attributes->get(self::ATTR_META, []), $meta));
    }

    /** Add to this response's meta (lists, composed routes, untrusted fields…). @param array<string, mixed> $meta */
    protected function addMeta(array $meta): void
    {
        $request = request();
        $request->attributes->set(self::ATTR_META, array_merge((array) $request->attributes->get(self::ATTR_META, []), $meta));
    }

    /** @return array<string, mixed> */
    public static function standardMeta(Request $request): array
    {
        $user     = Operator::user($request);
        $group    = Operator::administration($request);
        $currency = null;
        if (null !== $group) {
            $currency = $request->attributes->get('machine.primary_currency');
            if (!$currency instanceof TransactionCurrency) {
                $currency = Operator::primaryCurrency($group);
                $request->attributes->set('machine.primary_currency', $currency);
            }
        }
        $route    = $request->route();
        $action   = null === $route ? [] : $route->getAction();
        $meta     = [
            'target'             => 'local',
            'operator'           => null === $user ? null : (string) $user->email,
            'administrationId'   => null === $group ? null : (int) $group->id,
            'administrationName' => null === $group ? null : (string) $group->title,
            'primaryCurrency'    => $currency?->code,
            'serverVersion'      => (string) config('firefly.version'),
            'planeVersion'       => 'v1',
            'asOf'               => Clock::nowIso(),
            'tookMs'             => Clock::elapsedMs($request),
            'truncated'          => false,
            'tier'               => (string) ($action['machine_tier'] ?? 'read'),
            'untrusted'          => [],
        ];
        if (true === ($action['machine_composed'] ?? false)) {
            $meta['composed'] = true;
        }

        return $meta;
    }

    // ---------------------------------------------------------------- operator ---

    protected function operator(): User
    {
        $user = Operator::user(request());
        if (null === $user) {
            throw MachineException::notReady('No operator is bound to this request.', 'GET /machine/v1/health says why');
        }

        return $user;
    }

    protected function administration(): UserGroup
    {
        $group = Operator::administration(request());
        if (null === $group) {
            throw MachineException::notReady('No administration is bound to this request.', 'GET /machine/v1/health says why');
        }

        return $group;
    }

    protected function primaryCurrency(): TransactionCurrency
    {
        $currency = Operator::primaryCurrency($this->administration());
        if (null === $currency) {
            throw MachineException::notReady('The administration has no primary currency.', 'Set one in Firefly III (Options → Currencies)');
        }

        return $currency;
    }

    /**
     * Resolve an id-or-name to a model within the operator's scope (§8, §14.4): a numeric value
     * is an id; otherwise an exact name match, then a case-insensitive one. No match is
     * not_found; more than one is invalid_input carrying the candidates — never a silent pick.
     *
     * The default scope is the bound administration (user_group_id) when the table has that
     * column, else the operator (user_id), else none; pass $scope to override.
     *
     * @template TModel of Model
     *
     * @param class-string<TModel>                     $modelClass
     * @param null|Closure(EloquentBuilder<TModel>): mixed $scope
     *
     * @return TModel
     */
    protected function resolve(string $modelClass, string $idOrName, string $nameColumn = 'name', ?Closure $scope = null): Model
    {
        /** @var TModel $prototype */
        $prototype = new $modelClass();
        $entity    = strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename($modelClass)));
        $base      = static function () use ($modelClass, $prototype, $scope): EloquentBuilder {
            $query = $modelClass::query();
            if (null !== $scope) {
                $scope($query);

                return $query;
            }
            $columns = self::columns($prototype->getTable());
            if (in_array('user_group_id', $columns, true)) {
                $query->where($prototype->qualifyColumn('user_group_id'), Operator::administration(request())?->id);
            } elseif (in_array('user_id', $columns, true)) {
                $query->where($prototype->qualifyColumn('user_id'), Operator::user(request())?->id);
            }

            return $query;
        };

        $value = trim($idOrName);
        if (1 === preg_match('/^\d{1,19}$/', $value)) {
            $found = $base()->where($prototype->qualifyColumn($prototype->getKeyName()), $value)->first();
            if (null === $found) {
                throw MachineException::notFound(sprintf('No %s with id %s.', $entity, $value), sprintf('List them with the matching GET route, or pass the %s\'s name instead', $entity), ['id' => $value]);
            }

            return $found;
        }
        if ('' === $value) {
            throw MachineException::invalid(sprintf('An empty %s name was given.', $entity), sprintf('Pass the %s\'s id or its exact name', $entity));
        }

        $column = $prototype->qualifyColumn($nameColumn);
        $exact  = $base()->where($column, $value)->limit(11)->get();
        if (1 === $exact->count()) {
            return $exact->first();
        }
        $matches = $exact->count() > 1 ? $exact : $base()->whereRaw(sprintf('LOWER(%s) = ?', $column), [mb_strtolower($value)])->limit(11)->get();
        if (1 === $matches->count()) {
            return $matches->first();
        }
        if (0 === $matches->count()) {
            throw MachineException::notFound(sprintf('No %s named "%s".', $entity, $value), sprintf('List them with the matching GET route, or pass the %s\'s id', $entity), ['name' => $value]);
        }

        throw MachineException::invalid(
            sprintf('"%s" matches %s%d %ss.', $value, $matches->count() > 10 ? 'more than ' : '', min(10, $matches->count()), $entity),
            sprintf('Pass the %s\'s id instead — the candidates are in details.candidates', $entity),
            ['name' => $value, 'candidates' => $matches->take(10)->map(static fn (Model $m): array => ['id' => $m->getKey(), 'name' => (string) $m->getAttribute($nameColumn)])->values()->all()],
        );
    }

    /** @return list<string> */
    private static function columns(string $table): array
    {
        static $cache = [];

        return $cache[$table] ??= Schema::getColumnListing($table);
    }

    // ------------------------------------------------------------------ writes ---

    /**
     * THE write protocol — apis.mdx §7.
     *
     *   dry_run (default TRUE)  $apply(true) runs inside DryRun (the real code, rolled back);
     *                           the answer carries dry_run, changes, change_count, fingerprint,
     *                           would_fire_webhooks, held, confirm_token, expires_at + the data.
     *   dry_run: false          needs the confirm_token the dry run returned (single use, 10 min,
     *                           bound to this route + these arguments + the key). Under the write
     *                           lock the change set is recomputed; a moved fingerprint is refused
     *                           `conflict` WITH THE NEW COUNTS; the ceiling (max_changes, default
     *                           200) is enforced with the real count; then $apply(false) runs for
     *                           real in ONE DB transaction with its machine_operations row.
     *
     * On a route declared without a dry run (dryRun: false — /recurrences/{id}/trigger), the
     * write applies directly under the lock and in a transaction, and is still logged and audited.
     *
     * @param array<string, mixed>                              $args   the route's validated arguments (control fields are ignored)
     * @param Closure(bool): WriteResult                        $apply  called with $dryRun
     * @param null|Closure(WriteResult, bool): array<string, mixed> $render optional: the response data from the result
     * @param array{record?: bool, route?: string, require_token?: bool} $opts
     *        record: false skips the operation log (undo itself);
     *        require_token: true makes a route WITHOUT a dry run still demand and redeem the
     *        confirm_token its plan route minted with planToken() (POST /undo ← GET /undo/last);
     *        the redeemed entry (fingerprint, payload) is on the request as `machine.confirm`
     */
    protected function write(Request $request, array $args, Closure $apply, ?Closure $render = null, array $opts = []): JsonResponse
    {
        $routeKey = $opts['route'] ?? $this->routeKey($request);
        $tier     = (string) ($request->route()?->getAction('machine_tier') ?? 'write');
        $controls = $this->controls($request, $args);
        $argsHash = $this->argsHash($request, $args);
        $max      = $controls['max_changes'] ?? (int) config('machine.limits.max_changes_default', 200);
        $record   = $opts['record'] ?? true;
        $render ??= static fn (WriteResult $r, bool $dry): array => $r->data;

        // Inside POST /batch (§7.4) the batch owns the protocol — one token, one lock, one DB
        // transaction, one operation-log row — so an operation only runs its write and reports it.
        if (BatchController::active()) {
            if (!$this->routeTakesDryRun($request)) {
                throw MachineException::invalid(sprintf('%s cannot run inside a batch.', $routeKey), 'Call it on its own');
            }
            $result = self::writeResult($apply(BatchController::dryRun()));
            BatchController::collect($routeKey, $result);

            return $this->ok(array_merge($render($result, BatchController::dryRun()), ['changes' => $result->changes, 'change_count' => $result->changeCount()]));
        }

        $dryRun   =$this->routeTakesDryRun($request) ? ($controls['dry_run'] ?? true) : false;

        try {
            if ($dryRun) {
                $held    = $this->locked(static fn (): \FireflyIII\Machine\DryRunResult => DryRun::run(static fn () => $apply(true)));
                $preview = self::writeResult($held->value);
                $count   = $preview->changeCount();
                if ($count > $max) {
                    throw self::tooMany($preview, $count, $max);
                }
                $fingerprint = $preview->fingerprint();
                $token       = ConfirmTokens::mint($routeKey, $argsHash, $fingerprint, ['change_count' => $count]);

                return $this->ok(array_merge($render($preview, true), [
                    'dry_run'             => true,
                    'changes'             => $preview->changes,
                    'change_count'        => $count,
                    'would_fire_webhooks' => $held->webhooks,
                    'held'                => $held->held(),
                    'fingerprint'         => $fingerprint,
                    'confirm_token'       => $token['confirm_token'],
                    'expires_at'          => $token['expires_at'],
                ]), ['dryRun' => true]);
            }

            $idem = $controls['idempotency_key'] ?? null;
            if (null !== $idem) {
                $replay = Idempotency::recall($routeKey, $idem, $argsHash);
                if (null !== $replay) {
                    return $this->ok($replay['data'], array_merge($replay['meta'], ['replayed' => true]));
                }
            }

            $takesDryRun = $this->routeTakesDryRun($request);
            $needsToken  = $takesDryRun || true === ($opts['require_token'] ?? false);
            $token       = $controls['confirm_token'] ?? null;
            if ($needsToken && (null === $token || '' === $token)) {
                throw MachineException::forbidden(
                    'A real write needs the confirm_token from its dry run.',
                    'Call the route with dry_run: true (the default), read the plan, then repeat it with dry_run: false and "confirm_token" from that answer',
                );
            }

            $result = null;
            $opId   = null;
            $this->locked(function () use ($request, $takesDryRun, $needsToken, $token, $routeKey, $argsHash, $apply, $max, $record, &$result, &$opId): void {
                if ($needsToken && !$takesDryRun) {
                    $request->attributes->set('machine.confirm', ConfirmTokens::redeem((string) $token, $routeKey, $argsHash));
                }
                if ($takesDryRun) {
                    $entry   = ConfirmTokens::redeem((string) $token, $routeKey, $argsHash);
                    $recheck = self::writeResult(DryRun::run(static fn () => $apply(true))->value);
                    if (!hash_equals((string) $entry['fingerprint'], $recheck->fingerprint())) {
                        throw MachineException::conflict(
                            'The ledger changed since this plan was made — nothing was written.',
                            'Re-plan: run the call again with dry_run: true, read the new counts, and confirm with the new token',
                            ['changes' => $recheck->changes, 'change_count' => $recheck->changeCount(), 'planned_change_count' => $entry['payload']['change_count'] ?? null],
                        );
                    }
                    if ($recheck->changeCount() > $max) {
                        throw self::tooMany($recheck, $recheck->changeCount(), $max);
                    }
                }
                DB::transaction(function () use ($apply, $routeKey, $record, &$result, &$opId): void {
                    $result = self::writeResult($apply(false));
                    if ($record) {
                        $opId = OperationLog::record($routeKey, $result);
                    }
                });
            }, true);

            /** @var WriteResult $result */
            Audit::line($request, ['route' => $routeKey, 'tier' => $tier, 'op' => $opId, 'token' => $token, 'dry_run' => false, 'changed' => $result->changeCount(), 'ok' => true]);
            $data = array_merge($render($result, false), [
                'dry_run'      => false,
                'changes'      => $result->changes,
                'change_count' => $result->changeCount(),
            ], $record ? ['operation_id' => $opId] : []);
            if (null !== $idem) {
                Idempotency::remember($routeKey, $idem, $argsHash, $data);
            }

            return $this->ok($data, ['dryRun' => false]);
        } catch (Throwable $e) {
            // every write — and every refused or failed write — leaves one audit line (§16.4)
            if (!$dryRun) {
                Audit::line($request, ['route' => $routeKey, 'tier' => $tier, 'token' => $controls['confirm_token'] ?? null, 'dry_run' => false, 'ok' => false, 'code' => Envelope::fromThrowable($e)->errorCode]);
            }

            throw $e;
        }
    }

    /**
     * Mint a confirm token on a PLAN route for a different APPLY route (reconcile/plan →
     * reconcile/apply, GET /undo/last → POST /undo). The apply route must present $args (and
     * the same route parameters) for the token to redeem.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $payload
     *
     * @return array{confirm_token: string, expires_at: string}
     */
    protected function planToken(Request $request, string $applyRouteKey, array $args, string $fingerprint, array $payload = []): array
    {
        return ConfirmTokens::mint($applyRouteKey, $this->argsHash($request, $args), $fingerprint, $payload);
    }

    /** "POST /accounts/{id}/reconcile/apply" — this route's identity. */
    protected function routeKey(Request $request): string
    {
        $key = $request->route()?->getAction('machine_key');

        return is_string($key) ? $key : strtoupper($request->method()).' /'.ltrim((string) $request->path(), '/');
    }

    /**
     * Run $fn under the plane's write lock (§15): Cache::lock('machine:write', 120). A writer
     * that waits past write_lock_wait gets `conflict` with a retry hint, never a partial apply.
     *
     * @template T
     *
     * @param Closure(): T $fn
     *
     * @return T
     */
    protected function locked(Closure $fn, bool $long = false): mixed
    {
        $lock = Cache::lock('machine:write', (int) config('machine.write_lock_ttl', 120));

        try {
            return $lock->block($long ? (int) config('machine.write_lock_wait', 30) : 10, $fn);
        } catch (LockTimeoutException) {
            throw MachineException::conflict('Another plane write is in progress.', 'Retry in a few seconds — plane writes run one at a time');
        }
    }

    /**
     * The control fields of this call, from the body (or the query string on a body-less call).
     *
     * @param array<string, mixed> $args
     *
     * @return array{dry_run?: bool, confirm_token?: null|string, max_changes?: int, idempotency_key?: null|string}
     */
    private function controls(Request $request, array $args): array
    {
        $raw = [];
        foreach (array_keys(self::CONTROL_RULES) as $field) {
            if (array_key_exists($field, $args)) {
                $raw[$field] = $args[$field];

                continue;
            }
            $body = $request->isJson() ? $request->json()->all() : $request->request->all();
            if (array_key_exists($field, $body)) {
                $raw[$field] = $body[$field];
            } elseif ($request->query->has($field)) {
                $raw[$field] = $request->query->get($field);
            }
        }
        $raw       = $this->normaliseBooleans($raw, ['dry_run' => 'boolean']);
        $validator = Validator::make($raw, self::CONTROL_RULES);
        if ($validator->fails()) {
            throw MachineException::invalid('Invalid write-protocol argument.', (string) $validator->errors()->first(), ['fields' => $validator->errors()->toArray()]);
        }
        $out = [];
        if (array_key_exists('dry_run', $raw)) {
            $out['dry_run'] = (bool) filter_var($raw['dry_run'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($raw['confirm_token']) && '' !== (string) $raw['confirm_token']) {
            $out['confirm_token'] = (string) $raw['confirm_token'];
        }
        if (array_key_exists('max_changes', $raw) && null !== $raw['max_changes']) {
            $out['max_changes'] = intval($raw['max_changes']);
        }
        if (isset($raw['idempotency_key']) && '' !== (string) $raw['idempotency_key']) {
            $out['idempotency_key'] = (string) $raw['idempotency_key'];
        }

        return $out;
    }

    /** @param array<string, mixed> $args */
    private function argsHash(Request $request, array $args): string
    {
        foreach (array_keys(self::CONTROL_RULES) as $field) {
            unset($args[$field]);
        }
        $params = $request->route()?->parameters() ?? [];

        return ConfirmTokens::argsHash(['args' => $args, 'params' => array_map(static fn ($v) => is_scalar($v) ? (string) $v : null, $params)]);
    }

    private function routeTakesDryRun(Request $request): bool
    {
        return true === $request->route()?->getAction('machine_dry_run');
    }

    private static function writeResult(mixed $value): WriteResult
    {
        if (!$value instanceof WriteResult) {
            throw MachineException::internal('A write handler did not return a WriteResult.');
        }

        return $value;
    }

    private static function tooMany(WriteResult $result, int $count, int $max): MachineException
    {
        return MachineException::conflict(
            sprintf('%s change%s would be made, over the ceiling of %s — nothing was written.', self::thousands($count), 1 === $count ? '' : 's', self::thousands($max)),
            sprintf('%d changes would be made; raise max_changes to at least %d, or narrow the request', $count, $count),
            ['change_count' => $count, 'max_changes' => $max, 'changes' => $result->changes],
        );
    }

    /** 1904 → "1,904", for integer counts only (never an amount). */
    private static function thousands(int $n): string
    {
        $s   = (string) abs($n);
        $out = '';
        while (strlen($s) > 3) {
            $out = ','.substr($s, -3).$out;
            $s   = substr($s, 0, -3);
        }

        return ($n < 0 ? '-' : '').$s.$out;
    }
}
