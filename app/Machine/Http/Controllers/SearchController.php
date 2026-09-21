<?php

/*
 * SearchController.php
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
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Machine\Envelope;
use FireflyIII\Machine\ErrorFile\ErrorFile;
use FireflyIII\Machine\MachineException;
use FireflyIII\Models\Account;
use FireflyIII\Support\Http\Api\AccountFilter;
use FireflyIII\Support\JsonApi\Enrichments\AccountEnrichment;
use FireflyIII\Support\JsonApi\Enrichments\TransactionGroupEnrichment;
use FireflyIII\Support\Search\AccountSearch;
use FireflyIII\Support\Search\OperatorQuerySearch;
use FireflyIII\Support\Search\QueryParser\FieldNode;
use FireflyIII\Support\Search\QueryParser\Node;
use FireflyIII\Support\Search\QueryParser\NodeGroup;
use FireflyIII\Support\Search\QueryParser\QueryParserInterface;
use FireflyIII\Support\Search\QueryParser\StringNode;
use FireflyIII\Support\Search\SearchInterface;
use FireflyIII\Transformers\AccountTransformer;
use FireflyIII\Transformers\TransactionGroupTransformer;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\ParameterBag;
use Throwable;

/**
 * pm/apis.mdx §13 — Firefly's own search language, the query escape hatch.
 *
 *   GET /search            OperatorQuerySearch (the parser + the collector the rule engine uses);
 *                          the answer echoes the operators Firefly PARSED and the words it treated
 *                          as free text, and a misspelt operator is refused rather than silently
 *                          dropped (Firefly ignores an unknown operator — the search would be wider
 *                          than the caller meant).
 *   GET /search/count      the same parse, only the total.
 *   GET /search/operators  generated from config/search.php, so it cannot drift.
 *   GET /search/accounts   AccountSearch, as upstream's /api/v1/search/accounts.
 *
 * Read-only by construction: the language has no operator that mutates.
 */
final class SearchController extends MachineController
{
    use AccountFilter;

    private const string WHERE = 'app/Machine/Http/Controllers/SearchController.php';

    /** Fields a transaction row carries that a third party may have written (§16.3). */
    public const array UNTRUSTED_TRANSACTION_FIELDS = ['description', 'notes', 'internal_reference', 'external_id', 'account_name', 'source_name', 'destination_name', 'tags', 'group_title'];

    /** Fields an account row carries that a third party may have written (§16.3). */
    public const array UNTRUSTED_ACCOUNT_FIELDS = ['name', 'notes', 'account_name'];

    /** The fields /search/accounts matches on (AccountSearch's own constants). */
    public const array ACCOUNT_FIELDS = [AccountSearch::SEARCH_ALL, AccountSearch::SEARCH_IBAN, AccountSearch::SEARCH_NAME, AccountSearch::SEARCH_NUMBER, AccountSearch::SEARCH_ID];

    private const int MAX_QUERY = 1000;

    /** §13 / §15: a search over this many seconds is `upstream_error` with a narrowing hint. */
    public const int TIMEOUT_SECONDS = 10;

    public function search(Request $request): JsonResponse
    {
        $args   = $this->input($request, ['query' => ['required', 'string', 'max:'.self::MAX_QUERY]] + self::LIST_RULES, true);
        // Firefly's collector has ONE order (date desc, then order, then id — §5.5) and the search
        // cannot be told another. `order` is accepted so a list client (ffx --order) can say the
        // one order there is; anything else is refused by name rather than silently ignored.
        $order  = trim((string) ($args['order'] ?? ''));
        if ('' !== $order && '-date' !== $order) {
            throw MachineException::invalid(
                sprintf('Cannot order a search by "%s".', $order),
                'A search has a fixed order: date desc (Firefly\'s journal order). Pass order=-date or omit it',
                ['field' => 'order', 'accepted' => ['-date']],
            );
        }
        $params = $this->listParams($request, ['date'], '-date');
        $limit  = $params->limit;
        $offset = $params->offset;

        // Firefly's collector pages by (page, limit). An offset that is a multiple of the limit maps
        // to one page; any other offset reads the first offset+limit rows and slices.
        $aligned = 0 === $offset % $limit;
        $size    = $aligned ? $limit : $offset + $limit;
        $page    = $aligned ? intdiv($offset, $limit) + 1 : 1;

        $searcher = $this->searcher((string) $args['query']);
        $searcher->setPage($page);
        $searcher->setLimit($size);
        $groups   = $this->run(static fn (): LengthAwarePaginator => $searcher->searchTransactions());

        $rows     = $groups->getCollection();
        if (!$aligned) {
            $rows = $rows->slice($offset, $limit)->values();
        }
        $total     = (int) $groups->total();
        $truncated = $total > $offset + $rows->count();

        $enrichment = new TransactionGroupEnrichment();
        $enrichment->setUser($this->operator());
        $rows        = $enrichment->enrich($rows);

        /** @var TransactionGroupTransformer $transformer */
        $transformer = app(TransactionGroupTransformer::class);
        $transformer->setParameters(new ParameterBag());
        $transactions = [];
        foreach ($rows as $group) {
            $transactions[] = self::withoutLinks($transformer->transform($group));
        }

        // §13 byte cap: 8 MiB; over it the page is cut, meta.truncated says so and next_offset
        // points at the first row that was dropped, so a caller can continue rather than guess.
        [$transactions, $dropped] = MirrorController::capBytes($transactions);
        $returned  = count($transactions);
        $truncated = $truncated || $dropped > 0;

        return $this->ok(
            array_merge(['transactions' => $transactions, 'total' => $total], $this->report($searcher, (string) $args['query'])),
            [
                'truncated'     => $truncated,
                'limit_applied' => $limit,
                'offset'        => $offset,
                'count'         => $returned,
                'next_offset'   => $truncated ? $offset + $returned : null,
                'order'         => 'date desc (Firefly\'s journal order: date, order, id)',
                'untrusted'     => self::UNTRUSTED_TRANSACTION_FIELDS,
            ]
            + ($params->clamped ? ['limit_requested' => $params->requestedLimit] : [])
            + ($dropped > 0 ? ['dropped_rows' => $dropped, 'hint' => sprintf('The page was over 8 MiB: %d row(s) were dropped — continue with offset=%d, or narrow the query (date_after, date_before) or lower limit', $dropped, $offset + $returned)] : []),
        );
    }

    public function count(Request $request): JsonResponse
    {
        $args     = $this->input($request, ['query' => ['required', 'string', 'max:'.self::MAX_QUERY]], true);
        $searcher = $this->searcher((string) $args['query']);
        $searcher->setPage(1);
        $searcher->setLimit(1);
        $groups   = $this->run(static fn (): LengthAwarePaginator => $searcher->searchTransactions());

        return $this->ok(array_merge(['count' => (int) $groups->total()], $this->report($searcher, (string) $args['query'])));
    }

    /** Every operator in config/search.php, with its argument type and an example (§13). */
    public function operators(Request $request): JsonResponse
    {
        $this->input($request, [], true);
        $operators = [];
        foreach ((array) config('search.operators') as $name => $config) {
            $name        = (string) $name;
            $config      = (array) $config;
            $root        = true === ($config['alias'] ?? false) ? (string) ($config['alias_for'] ?? $name) : $name;
            $argument    = self::argumentType($root, (bool) ($config['needs_context'] ?? true));
            $operators[] = [
                'operator'    => $name,
                'argument'    => $argument,
                'example'     => self::example($name, $argument),
                'description' => self::describe($root, $argument),
                'alias_for'   => true === ($config['alias'] ?? false) ? $root : null,
                'negatable'   => true,
            ];
        }

        return $this->ok([
            'operators' => $operators,
            'syntax'    => [
                'form'      => 'operator:value — quote a value with spaces: description_contains:"whole foods"',
                'negate'    => 'prefix an operator with "-" to exclude: -category_is:Groceries',
                'combine'   => 'several operators are AND-ed; words without an operator are free text matched against the description',
                'booleans'  => 'operators whose argument is "true" take true (or false, which negates them): has_any_category:true',
                'dates'     => 'dates are YYYY-MM-DD',
                'amounts'   => 'amounts are positive decimal strings: amount_more:50.00',
                'read_only' => 'the language has no operator that changes anything',
            ],
        ], ['count' => count($operators)]);
    }

    /** Accounts matching a query by IBAN, name, number or id (AccountSearch, as upstream). */
    public function accounts(Request $request): JsonResponse
    {
        $args   = $this->input($request, [
            'query' => ['required', 'string', 'max:255'],
            'field' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::ACCOUNT_FIELDS)],
            'type'  => ['sometimes', 'nullable', 'string', 'max:64'],
        ] + self::LIST_RULES, true);
        $params = $this->listParams($request, ['name', 'id'], 'name');
        $type   = trim((string) ($args['type'] ?? 'all'));
        $type   = '' === $type ? 'all' : $type;
        // Firefly's mapAccountTypes() turns a type it does not know into "normal" — silently a
        // different search than the one asked for (§5.7); refuse it by name instead.
        $unknownTypes = array_values(array_filter(array_map('trim', explode(',', $type)), fn (string $t): bool => !array_key_exists($t, $this->types)));
        if ([] !== $unknownTypes) {
            $known = array_keys($this->types);
            sort($known);

            throw MachineException::invalid(
                sprintf('Unknown account type%s: %s.', 1 === count($unknownTypes) ? '' : 's', implode(', ', $unknownTypes)),
                sprintf('type accepts: %s (comma-separated)', implode(', ', $known)),
                ['field' => 'type', 'unknown' => $unknownTypes, 'accepted' => $known],
            );
        }
        $types  = $this->mapAccountTypes($type);

        /** @var AccountSearch $search */
        $search = app(AccountSearch::class);
        $search->setUser($this->operator());
        $search->setTypes($types);
        $search->setField((string) ($args['field'] ?? AccountSearch::SEARCH_ALL));
        $search->setQuery(trim((string) $args['query']));
        $found  = $this->run(static fn () => $search->search());
        $found  = $found->filter(static fn ($a): bool => $a instanceof Account)->values();

        $page   = collect($this->applyList($found->all(), $params));
        $enrichment = new AccountEnrichment();
        $enrichment->setDate(Carbon::now()->endOfDay());
        $enrichment->setUser($this->operator());
        $page   = $enrichment->enrich($page);

        /** @var AccountTransformer $transformer */
        $transformer = app(AccountTransformer::class);
        $transformer->setParameters(new ParameterBag(['date' => Carbon::now()->endOfDay()]));
        $accounts    = [];
        foreach ($page as $account) {
            $accounts[] = self::withoutLinks($transformer->transform($account));
        }

        return $this->ok(['accounts' => $accounts, 'total' => $found->count()], ['untrusted' => self::UNTRUSTED_ACCOUNT_FIELDS]);
    }

    // ------------------------------------------------------------------------

    /**
     * A parsed searcher for the operator — refusing a query Firefly could not parse, and a query
     * that names an operator Firefly does not have (it would silently drop it).
     */
    private function searcher(string $query): SearchInterface
    {
        if ('' === trim($query)) {
            throw MachineException::invalid('The query is empty.', 'GET /machine/v1/search/operators lists what a query can say');
        }

        /** @var SearchInterface $searcher */
        $searcher = app(SearchInterface::class);
        $searcher->setUser($this->operator());
        $searcher->setDate(Carbon::now());

        // Values Firefly would silently coerce are refused before it sees them: amount_more:abc
        // becomes amount_more:0 (every transaction), id:abc becomes id:0 (none).
        $this->refuseBadValues($query);

        try {
            $searcher->parseQuery($query);
        } catch (FireflyException $e) {
            throw MachineException::invalid('Firefly could not parse this query.', 'Check the quoting and the operator names — GET /machine/v1/search/operators', ['parser' => Envelope::scrub($e->getMessage())]);
        }
        $invalid = $searcher->getInvalidOperators();
        if ([] !== $invalid) {
            // Firefly files two different failures here: an operator it does not have, and a
            // known operator whose VALUE it could not read (a date it cannot parse). Tell them apart.
            $known   = array_keys((array) config('search.operators'));
            $unknown = [];
            $badValue = [];
            foreach ($invalid as $op) {
                $type  = strtolower((string) ($op['type'] ?? ''));
                $value = (string) ($op['value'] ?? '');
                if (in_array($type, $known, true)) {
                    $badValue[] = ['operator' => $type, 'value' => $value, 'expected' => self::argumentType(self::rootOf($type), (bool) (config('search.operators.'.$type.'.needs_context') ?? true))];

                    continue;
                }
                $unknown[] = ['operator' => $type, 'did_you_mean' => self::closest($type, $known)];
            }
            if ([] !== $badValue) {
                $first = $badValue[0];

                throw MachineException::invalid(
                    sprintf('Firefly could not read the value of %s: "%s" is not a %s.', $first['operator'], $first['value'], $first['expected']),
                    self::valueHint($first['operator'], $first['expected']),
                    ['invalid_values' => $badValue],
                );
            }
            $names = implode(', ', array_map(static fn (array $d): string => $d['operator'], $unknown));

            throw MachineException::invalid(
                sprintf('Unknown search operator%s: %s. Firefly would silently ignore %s and search wider than you meant.', 1 === count($unknown) ? '' : 's', $names, 1 === count($unknown) ? 'it' : 'them'),
                null !== $unknown[0]['did_you_mean'] ? sprintf('Did you mean %s:? GET /machine/v1/search/operators lists every operator', $unknown[0]['did_you_mean']) : 'GET /machine/v1/search/operators lists every operator',
                ['invalid_operators' => $unknown],
            );
        }

        return $searcher;
    }

    /**
     * Refuse a typed operator value Firefly would coerce instead of refusing: an amount that is
     * not a decimal (Steam::positive() makes it "0"), an id that is not an integer ((int) makes
     * it 0), a boolean operator whose value is neither true nor false (anything but "false" is
     * read as true).
     */
    private function refuseBadValues(string $query): void
    {
        // Firefly's parser reads a minus AFTER the colon as a prohibition of a new token, so
        // amount_more:-50 is NOT amount_more:50 — the complement of what a caller who thinks of
        // withdrawals as negative meant. Refuse it by name; the parsed tree has already lost the sign.
        if (preg_match_all('/(?<![\w"])([a-z_]+):-(\d[\d.,]*)(?![\w.,])/i', $query, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                $operator = strtolower($hit[1]);
                if (!is_array(config('search.operators.'.$operator))) {
                    continue;
                }
                $expected = self::argumentType(self::rootOf($operator), (bool) (config('search.operators.'.$operator.'.needs_context') ?? true));
                if (!in_array($expected, ['amount', 'id'], true)) {
                    continue;
                }

                $balance = str_contains($operator, 'balance');

                throw MachineException::invalid(
                    sprintf('%s:-%s — Firefly reads a minus after the colon as "NOT %s:%s", not as a negative %s.', $operator, $hit[2], $operator, $hit[2], $balance ? 'balance' : $expected),
                    $balance
                        ? sprintf('Firefly\'s search cannot express a negative balance; a positive one works (%s:%s), and -%s:%s excludes instead — for an overdrawn account use GET /machine/v1/accounts with its balance fields', $operator, $hit[2], $operator, $hit[2])
                        : sprintf('Amounts are positive in a search: write %s:%s — or -%s:%s to exclude those transactions', $operator, $hit[2], $operator, $hit[2]),
                    ['invalid_values' => [['operator' => $operator, 'value' => '-'.$hit[2], 'expected' => $expected, 'read_as' => sprintf('NOT %s:%s', $operator, $hit[2])]]],
                );
            }
        }

        try {
            /** @var QueryParserInterface $parser */
            $parser = app(QueryParserInterface::class);
            $tree   = $parser->parse($query);
        } catch (Throwable $e) {
            ErrorFile::for(self::WHERE)->expected('parsing the query for its value checks', $e);
            return; // the searcher's own parse reports the syntax error
        }
        $problems = self::checkValues($tree);
        if ([] === $problems) {
            return;
        }
        $first = $problems[0];

        throw MachineException::invalid(
            sprintf('%s:%s — "%s" is not a %s, and Firefly would read it as %s.', $first['operator'], $first['value'], $first['value'], $first['expected'], $first['read_as']),
            self::valueHint($first['operator'], $first['expected']),
            ['invalid_values' => $problems],
        );
    }

    /** @return list<array<string, mixed>> the operator values Firefly would coerce, in query order */
    private static function checkValues(Node $node): array
    {
        if ($node instanceof NodeGroup) {
            $problems = [];
            foreach ($node->getNodes() as $child) {
                $problems = array_merge($problems, self::checkValues($child));
            }

            return $problems;
        }
        if (!$node instanceof FieldNode) {
            return [];
        }
        $operator = strtolower($node->getOperator());
        $config   = config('search.operators.'.$operator);
        if (!is_array($config)) {
            return []; // unknown operators are reported by the searcher
        }
        $root     = self::rootOf($operator);
        $expected = self::argumentType($root, (bool) ($config['needs_context'] ?? true));
        $value    = trim((string) $node->getValue());
        $readAs   = match ($expected) {
            'amount' => 1 === preg_match('/^-?\d+(?:[.,]\d+)?$/', $value) ? null : 'an amount of 0',
            'id'     => 1 === preg_match('/^\d{1,19}$/', $value) ? null : 'id 0 (no transaction)',
            'true'   => in_array(strtolower($value), ['true', 'false'], true) ? null : 'true',
            default  => null,
        };
        if (null === $readAs) {
            return [];
        }

        return [['operator' => $operator, 'value' => $value, 'expected' => $expected, 'read_as' => $readAs]];
    }

    private static function rootOf(string $operator): string
    {
        try {
            return ltrim(OperatorQuerySearch::getRootOperator($operator), '-');
        } catch (Throwable $e) {
            ErrorFile::for(self::WHERE)->expected('resolving a root search operator', $e);
            return $operator;
        }
    }

    private static function valueHint(string $operator, string $expected): string
    {
        return match ($expected) {
            'amount'   => sprintf('Write the amount as a decimal string: %s:50.00 (GET /machine/v1/search/operators)', $operator),
            'id'       => sprintf('Pass a numeric id: %s:42', $operator),
            'date'     => sprintf('Write the date as YYYY-MM-DD: %s:2026-01-31 (Firefly also reads "today", "-1w", "2026-01")', $operator),
            'true'     => sprintf('This operator takes true or false: %s:true', $operator),
            'currency' => sprintf('Pass the currency code: %s:USD', $operator),
            default    => 'GET /machine/v1/search/operators lists every operator with an example',
        };
    }

    /**
     * What Firefly understood (§13 "Operator report"), read from the same parse tree Firefly's
     * search walks (QueryParserInterface): every field node is an operator — including the ones
     * Firefly implements as word matches, like description_contains — and every bare string is
     * free text. A caller sees exactly how its query was read.
     *
     * @return array<string, mixed>
     */
    private function report(SearchInterface $searcher, string $query): array
    {
        $parsed   = [];
        $freeText = [];
        $excluded = [];

        try {
            /** @var QueryParserInterface $parser */
            $parser = app(QueryParserInterface::class);
            $tree   = $parser->parse($query);
            $this->walk($tree, $tree->isProhibited(false), $parsed, $freeText, $excluded);
        } catch (Throwable $e) {
            ErrorFile::for(self::WHERE)->expected('parsing the query for its explanation', $e);
            // the searcher parsed it already; fall back to what it kept
            $freeText = array_values(array_map('strval', $searcher->getWords()));
            $excluded = array_values(array_map('strval', $searcher->getExcludedWords()));
        }

        return [
            'query'            => $query,
            'parsed_operators' => $parsed,
            'free_text'        => $freeText,
            'excluded_words'   => $excluded,
        ];
    }

    /**
     * @param list<array<string, mixed>> $parsed
     * @param list<string>               $freeText
     * @param list<string>               $excluded
     */
    private function walk(Node $node, bool $flip, array &$parsed, array &$freeText, array &$excluded): void
    {
        if ($node instanceof NodeGroup) {
            $prohibited = $node->isProhibited($flip);
            foreach ($node->getNodes() as $child) {
                $this->walk($child, $prohibited, $parsed, $freeText, $excluded);
            }

            return;
        }
        if ($node instanceof FieldNode) {
            $operator = strtolower($node->getOperator());

            try {
                $root = ltrim(OperatorQuerySearch::getRootOperator($operator), '-');
            } catch (Throwable $e) {
                ErrorFile::for(self::WHERE)->expected('resolving a root search operator', $e);
                $root = $operator;
            }
            $parsed[] = [
                'operator'   => $operator,
                'value'      => $node->getValue(),
                'prohibited' => $node->isProhibited($flip),
            ] + ($root !== $operator ? ['alias_for' => $root] : []);

            return;
        }
        if ($node instanceof StringNode) {
            if ($node->isProhibited($flip)) {
                $excluded[] = $node->getValue();

                return;
            }
            $freeText[] = $node->getValue();
        }
    }

    /**
     * Run the search under the §13 timeout (10 s, `upstream_error` over it) and map Firefly's own
     * failures to the envelope.
     *
     * The timeout is enforced where the driver can: PostgreSQL's statement_timeout and MySQL's
     * max_execution_time cancel the query itself. SQLite (the plane's default runtime, §3.3) has
     * no statement timeout through PDO, so there the wall clock is the judge: a search that
     * finished past the limit is refused with the same code and hint, because a query that slow
     * is a query too wide, and the caller must narrow it — not learn to wait.
     *
     * @template T
     *
     * @param \Closure(): T $fn
     *
     * @return T
     */
    private function run(\Closure $fn): mixed
    {
        $timeout = self::timeoutMicros();
        $started = hrtime(true);
        $reset   = self::armDriverTimeout($timeout);

        try {
            $out = $fn();
        } catch (MachineException $e) {
            throw $e;
        } catch (QueryException $e) {
            if (self::isDriverTimeout($e)) {
                throw self::timedOut($timeout, intdiv(hrtime(true) - $started, 1000));
            }

            throw $e;
        } catch (FireflyException $e) {
            throw MachineException::upstream('Firefly\'s search failed.', 'Narrow the query, or check it against GET /machine/v1/search/operators', ['reason' => Envelope::scrub($e->getMessage())], $e);
        } finally {
            $reset();
        }
        $elapsed = intdiv(hrtime(true) - $started, 1000);
        if ($elapsed > $timeout) {
            throw self::timedOut($timeout, $elapsed);
        }

        return $out;
    }

    /**
     * The §13 search timeout in MICROSECONDS (config machine.limits.search_timeout, in seconds,
     * default 10). Time is kept as integers here — no float ever touches a plane value, amount
     * or otherwise (MoneyTest guards the whole of app/Machine).
     */
    private static function timeoutMicros(): int
    {
        $value = config('machine.limits.search_timeout', self::TIMEOUT_SECONDS);
        if (!is_numeric($value)) {
            return self::TIMEOUT_SECONDS * 1_000_000;
        }
        $micros = (int) bcmul(sprintf('%.6F', $value), '1000000', 0);

        return $micros > 0 ? $micros : self::TIMEOUT_SECONDS * 1_000_000;
    }

    /** 10000000 → "10", 1 → "0.000001": microseconds as a decimal string of seconds. */
    private static function seconds(int $micros): string
    {
        $s = sprintf('%d.%06d', intdiv($micros, 1_000_000), $micros % 1_000_000);

        return rtrim(rtrim($s, '0'), '.');
    }

    private static function timedOut(int $timeoutMicros, int $elapsedMicros): MachineException
    {
        return MachineException::upstream(
            sprintf('The search took longer than the %s s limit (%s s).', self::seconds($timeoutMicros), self::seconds($elapsedMicros)),
            'Narrow the query — add date_after/date_before, an account_id or a category — or lower limit; GET /machine/v1/search/count says how many rows match before you page',
            ['timeout_seconds' => self::seconds($timeoutMicros), 'elapsed_seconds' => self::seconds($elapsedMicros)],
        );
    }

    /**
     * Ask the database to cancel a statement past the timeout, where the driver can; returns the
     * closure that puts the session back. SQLite has no such setting through PDO (see run()).
     *
     * @return \Closure(): void
     */
    private static function armDriverTimeout(int $timeoutMicros): \Closure
    {
        $ms = max(1, intdiv($timeoutMicros + 999, 1000));

        try {
            $connection = DB::connection();
            $driver     = $connection->getDriverName();
            if ('pgsql' === $driver) {
                $connection->statement(sprintf('SET statement_timeout = %d', $ms));

                return static function () use ($connection): void {
                    try {
                        $connection->statement('SET statement_timeout = DEFAULT');
                    } catch (Throwable $e) {
                        ErrorFile::for(self::WHERE)->expected('resetting the statement timeout', $e);
                        // the connection is per request; a stuck setting dies with it
                    }
                };
            }
            if ('mysql' === $driver || 'mariadb' === $driver) {
                $connection->statement(sprintf('SET SESSION max_execution_time = %d', $ms));

                return static function () use ($connection): void {
                    try {
                        $connection->statement('SET SESSION max_execution_time = DEFAULT');
                    } catch (Throwable $e) {
                        ErrorFile::for(self::WHERE)->expected('resetting the execution time limit', $e);
                        // as above
                    }
                };
            }
        } catch (Throwable $e) {
            ErrorFile::for(self::WHERE)->expected('setting the search time limit', $e);
            // a driver that refuses the setting is a driver without the guard, not a failed search
        }

        return static function (): void {};
    }

    /** PostgreSQL 57014 (statement cancelled by statement_timeout) and MySQL 3024 (max_execution_time exceeded). */
    private static function isDriverTimeout(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $code  = (int) ($e->errorInfo[1] ?? 0);
        $text  = strtolower($e->getMessage());

        return '57014' === $state
            || 3024 === $code
            || str_contains($text, 'statement timeout')
            || str_contains($text, 'maximum statement execution time exceeded');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function withoutLinks(array $row): array
    {
        unset($row['links']);

        return $row;
    }

    /**
     * The known operator nearest to a misspelt one: least edit distance, and on a tie the longest
     * shared prefix (amount_moar → amount_more, not amount_max).
     *
     * @param list<string> $known
     */
    private static function closest(string $word, array $known): ?string
    {
        $best   = null;
        $score  = PHP_INT_MAX;
        $shared = -1;
        foreach ($known as $candidate) {
            $candidate = (string) $candidate;
            $d         = levenshtein($word, $candidate);
            if ($d > $score) {
                continue;
            }
            $prefix = self::sharedPrefix($word, $candidate);
            if ($d < $score || $prefix > $shared) {
                $score  = $d;
                $shared = $prefix;
                $best   = $candidate;
            }
        }

        return null !== $best && $score <= max(2, intdiv(strlen($word), 3)) ? $best : null;
    }

    private static function sharedPrefix(string $a, string $b): int
    {
        $n = min(strlen($a), strlen($b));
        for ($i = 0; $i < $n; ++$i) {
            if ($a[$i] !== $b[$i]) {
                return $i;
            }
        }

        return $n;
    }

    /** The argument type of a (root) operator, from its name — the same naming Firefly's parser relies on. */
    private static function argumentType(string $root, bool $needsContext): string
    {
        if (!$needsContext) {
            return 'true';
        }

        return match (true) {
            in_array($root, ['id', 'journal_id', 'recurrence_id', 'account_id', 'source_account_id', 'destination_account_id'], true) => 'id',
            str_contains($root, 'date') || str_starts_with($root, 'created_') || str_starts_with($root, 'updated_') => 'date',
            str_contains($root, 'amount') || str_contains($root, '_balance_') => 'amount',
            'transaction_type' === $root                                                                     => 'type',
            in_array($root, ['currency_is', 'foreign_currency_is'], true)                                    => 'currency',
            default                                                                                          => 'text',
        };
    }

    private static function example(string $name, string $argument): string
    {
        return match ($argument) {
            'true'     => sprintf('%s:true', $name),
            'id'       => sprintf('%s:42', $name),
            'date'     => sprintf('%s:2026-01-31', $name),
            'amount'   => sprintf('%s:50.00', $name),
            'type'     => sprintf('%s:withdrawal', $name),
            'currency' => sprintf('%s:USD', $name),
            default    => sprintf('%s:"whole foods"', $name),
        };
    }

    private static function describe(string $root, string $argument): string
    {
        $subject = str_replace('_', ' ', (string) preg_replace('/_(is|contains|starts|ends|on|before|after|more|less|max|min|exactly|gte|gt|lte|lt)$/', '', $root));
        $verb    = match (true) {
            str_ends_with($root, '_is_not')                             => 'is not',
            str_ends_with($root, '_is')                                 => 'is exactly',
            str_ends_with($root, '_contains')                           => 'contains',
            str_ends_with($root, '_starts')                             => 'starts with',
            str_ends_with($root, '_ends')                               => 'ends with',
            str_ends_with($root, '_on')                                 => 'is on',
            str_ends_with($root, '_before')                             => 'is on or before',
            str_ends_with($root, '_after')                              => 'is on or after',
            str_ends_with($root, '_more'), str_ends_with($root, '_gt')  => 'is more than',
            str_ends_with($root, '_less'), str_ends_with($root, '_lt')  => 'is less than',
            str_ends_with($root, '_gte')                                => 'is at least',
            str_ends_with($root, '_lte')                                => 'is at most',
            default                                                     => 'matches',
        };
        if ('true' === $argument) {
            return sprintf('transactions where "%s" holds (true), or does not (false)', str_replace('_', ' ', $root));
        }

        return sprintf('transactions whose %s %s the value', $subject, $verb);
    }
}
