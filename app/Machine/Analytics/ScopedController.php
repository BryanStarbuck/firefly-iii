<?php

/*
 * ScopedController.php
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

namespace FireflyIII\Machine\Analytics;

use Carbon\Carbon;
use Closure;
use FireflyIII\Machine\Http\Controllers\MachineController;
use FireflyIII\Machine\MachineException;
use FireflyIII\Models\Account;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\Tag;
use FireflyIII\Models\TransactionCurrency;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * The base of the analytics, chart and report controllers: the shared arguments of apis.mdx
 * §10.2 (start, end, account_ids[], category_ids[], budget_ids[], tags[], currency_code,
 * include_transfers — plus the *_names[] forms the CLI sends for names) turned into a Scope,
 * with every id or name resolved inside the administration (ambiguity is an error, §14.4).
 */
abstract class ScopedController extends MachineController
{
    /** @var array<string, list<string>> */
    public const array RANGE_RULES = [
        'start' => ['required', 'date_format:Y-m-d'],
        'end'   => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
    ];

    /** @var array<string, list<string>> */
    public const array OPTIONAL_RANGE_RULES = [
        'start' => ['sometimes', 'date_format:Y-m-d'],
        'end'   => ['sometimes', 'date_format:Y-m-d'],
    ];

    /** @var array<string, list<string>> */
    public const array ACCOUNT_RULES = [
        'account_ids'     => ['sometimes', 'array', 'max:500'],
        'account_ids.*'   => ['integer', 'min:1'],
        'account_names'   => ['sometimes', 'array', 'max:500'],
        'account_names.*' => ['string', 'min:1', 'max:1024'],
    ];

    /** @var array<string, list<string>> */
    public const array FILTER_RULES = [
        'category_ids'      => ['sometimes', 'array', 'max:500'],
        'category_ids.*'    => ['integer', 'min:1'],
        'category_names'    => ['sometimes', 'array', 'max:500'],
        'category_names.*'  => ['string', 'min:1', 'max:1024'],
        'budget_ids'        => ['sometimes', 'array', 'max:500'],
        'budget_ids.*'      => ['integer', 'min:1'],
        'budget_names'      => ['sometimes', 'array', 'max:500'],
        'budget_names.*'    => ['string', 'min:1', 'max:1024'],
        'tags'              => ['sometimes', 'array', 'max:500'],
        'tags.*'            => ['string', 'min:1', 'max:1024'],
        'currency_code'     => ['sometimes', 'string', 'regex:/^[A-Za-z0-9]{2,10}$/'],
        'include_transfers' => ['sometimes', 'boolean'],
    ];

    /** @var array<string, list<string>> */
    public const array INTERVAL_RULES = [
        'interval' => ['sometimes', 'in:month,quarter,year,none'],
    ];

    /** @var array<string, list<string>> */
    public const array TOP_RULES = [
        'top_n' => ['sometimes', 'integer', 'min:1', 'max:10000'],
    ];

    private ?Ledger $ledger = null;

    /** The shared rules of every §10.2 route (range + accounts + filters). @return array<string, list<string>> */
    public static function commonRules(bool $rangeRequired = true): array
    {
        return array_merge($rangeRequired ? self::RANGE_RULES : self::OPTIONAL_RANGE_RULES, self::ACCOUNT_RULES, self::FILTER_RULES);
    }

    protected function ledger(): Ledger
    {
        return $this->ledger ??= new Ledger($this->operator(), $this->administration(), $this->primaryCurrency());
    }

    /**
     * Build the Scope from validated arguments.
     *
     * @param array<string, mixed> $args
     * @param null|array{0: Carbon, 1: Carbon} $defaultRange used when start/end are absent
     * @param list<string>          $defaultAccountTypes the accounts counted when none are named
     */
    protected function scope(array $args, ?array $defaultRange = null, array $defaultAccountTypes = [...Ledger::ASSET_TYPES, ...Ledger::LIABILITY_TYPES], string $defaultInterval = 'month'): Scope
    {
        [$start, $end, $defaulted] = $this->range($args, $defaultRange);
        $accounts                  = $this->resolveMany(Account::class, $args['account_ids'] ?? [], $args['account_names'] ?? [], 'name');
        $explicit                  = $accounts->isNotEmpty();
        if (!$explicit) {
            $accounts = $this->ledger()->accountsOfTypes($defaultAccountTypes);
        }

        return new Scope(
            start           : $start,
            end             : $end,
            accounts        : $accounts,
            accountsExplicit: $explicit,
            categories      : $this->resolveMany(Category::class, $args['category_ids'] ?? [], $args['category_names'] ?? [], 'name'),
            budgets         : $this->resolveMany(Budget::class, $args['budget_ids'] ?? [], $args['budget_names'] ?? [], 'name'),
            tags            : $this->resolveMany(Tag::class, [], $args['tags'] ?? [], 'tag'),
            currencyCode    : $this->currencyCode($args['currency_code'] ?? null),
            includeTransfers: (bool) ($args['include_transfers'] ?? false),
            interval        : (string) ($args['interval'] ?? $defaultInterval),
            rangeDefaulted  : $defaulted,
        );
    }

    /**
     * @param array<string, mixed>              $args
     * @param null|array{0: Carbon, 1: Carbon} $default
     *
     * @return array{0: Carbon, 1: Carbon, 2: bool}
     */
    protected function range(array $args, ?array $default): array
    {
        $tz    = (string) config('app.timezone');
        $start = isset($args['start']) ? Carbon::createFromFormat('Y-m-d', (string) $args['start'], $tz)->startOfDay() : null;
        $end   = isset($args['end']) ? Carbon::createFromFormat('Y-m-d', (string) $args['end'], $tz)->startOfDay() : null;
        if ((null === $start || null === $end) && null === $default) {
            throw MachineException::invalid('start and end are required.', 'Send start=YYYY-MM-DD and end=YYYY-MM-DD (inclusive)');
        }
        $defaulted = null === $start || null === $end;
        $start ??= $default[0]->copy()->startOfDay();
        $end   ??= $default[1]->copy()->startOfDay();
        if ($end->lt($start)) {
            throw MachineException::invalid(
                sprintf('end (%s) is before start (%s).', $end->format('Y-m-d'), $start->format('Y-m-d')),
                'Send an end on or after start — ranges are inclusive on both ends',
                ['fields' => ['end' => ['The end must be on or after start.']]],
            );
        }

        return [$start, $end, $defaulted];
    }

    /**
     * Resolve a list of ids and a list of names to models (each one exactly, §14.4). An id is
     * looked up as an id; a NAME is looked up as a name even when it looks like a number — a tag
     * called "2026" or a category called "4021" is a name, never an id.
     *
     * @template TModel of Model
     *
     * @param class-string<TModel> $class
     * @param array<int, mixed>    $ids
     * @param array<int, mixed>    $names
     *
     * @return Collection<int, TModel>
     */
    protected function resolveMany(string $class, array $ids, array $names, string $nameColumn): Collection
    {
        $found = [];
        foreach ($ids as $id) {
            $model                   = $this->resolve($class, (string) $id, $nameColumn);
            $found[$model->getKey()] = $model;
        }
        foreach ($names as $name) {
            $model                   = $this->resolveByName($class, (string) $name, $nameColumn);
            $found[$model->getKey()] = $model;
        }
        ksort($found);

        return new Collection(array_values($found));
    }

    /**
     * Resolve ONE model by its name only (exact, then case-insensitive) inside the operator's
     * administration: 0 hits is not_found, more than one is invalid_input carrying the
     * candidates (§14.4). The value is never read as an id — see resolveMany().
     *
     * @template TModel of Model
     *
     * @param class-string<TModel>          $class
     * @param null|Closure(EloquentBuilder): void $scope narrows the query instead of the default user_group_id / user_id scope
     *
     * @return TModel
     */
    protected function resolveByName(string $class, string $name, string $nameColumn = 'name', ?Closure $scope = null): Model
    {
        /** @var Model $prototype */
        $prototype = new $class();
        $entity    = strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename($class)));
        $value     = trim($name);
        if ('' === $value) {
            throw MachineException::invalid(sprintf('An empty %s name was given.', $entity), sprintf('Pass the %s\'s id or its exact name', $entity));
        }
        $base      = function () use ($class, $prototype, $scope): EloquentBuilder {
            $query = $class::query();
            if (null !== $scope) {
                $scope($query);

                return $query;
            }
            $columns = self::tableColumns($prototype->getTable());
            if (in_array('user_group_id', $columns, true)) {
                $query->where($prototype->qualifyColumn('user_group_id'), $this->administration()->id);
            } elseif (in_array('user_id', $columns, true)) {
                $query->where($prototype->qualifyColumn('user_id'), $this->operator()->id);
            }

            return $query;
        };
        $column    = $prototype->qualifyColumn($nameColumn);
        $exact     = $base()->where($column, $value)->limit(11)->get();
        if (1 === $exact->count()) {
            return $exact->first();
        }
        $matches   = $exact->count() > 1 ? $exact : $base()->whereRaw(sprintf('LOWER(%s) = ?', $column), [mb_strtolower($value)])->limit(11)->get();
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
    private static function tableColumns(string $table): array
    {
        static $cache = [];

        return $cache[$table] ??= Schema::getColumnListing($table);
    }

    protected function currencyCode(mixed $code): ?string
    {
        if (null === $code || '' === $code) {
            return null;
        }
        $upper = strtoupper((string) $code);
        if (!TransactionCurrency::query()->where('code', $upper)->exists()) {
            throw MachineException::notFound(sprintf('No currency with code "%s".', $upper), 'Use an ISO code the ledger knows, e.g. USD or EUR (GET /currencies lists them)', ['currency_code' => $upper]);
        }

        return $upper;
    }

    /**
     * The category trend's scope: exactly one category, by id or name.
     *
     * @param array<string, mixed> $args
     *
     * @return array{0: Category, 1: Scope}
     */
    protected function trendScope(array $args): array
    {
        $ref = $args['category_id'] ?? $args['category_name'] ?? null;
        if (null === $ref) {
            throw MachineException::invalid('category_id or category_name is required.', 'Send category_id=<id> or category_name=<exact name> (GET /categories lists them)');
        }
        if (isset($args['category_id'], $args['category_name'])) {
            throw MachineException::invalid('Send category_id or category_name, not both.', 'Pick one way to name the category');
        }

        /** @var Category $category */
        $category = isset($args['category_id'])
            ? $this->resolve(Category::class, (string) $ref)
            : $this->resolveByName(Category::class, (string) $ref);
        $scope    = $this->scope(array_merge($args, ['category_ids' => [(string) $category->id], 'category_names' => []]));

        return [$category, $scope];
    }

    protected function today(): Carbon
    {
        return today((string) config('app.timezone'));
    }
}
