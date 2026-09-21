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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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

    /** Fields a transaction row carries that a third party may have written (§16.3). */
    public const array UNTRUSTED_TRANSACTION_FIELDS = ['description', 'notes', 'internal_reference', 'external_id', 'account_name', 'source_name', 'destination_name', 'tags', 'group_title'];

    /** Fields an account row carries that a third party may have written (§16.3). */
    public const array UNTRUSTED_ACCOUNT_FIELDS = ['name', 'notes', 'account_name'];

    /** The fields /search/accounts matches on (AccountSearch's own constants). */
    public const array ACCOUNT_FIELDS = [AccountSearch::SEARCH_ALL, AccountSearch::SEARCH_IBAN, AccountSearch::SEARCH_NAME, AccountSearch::SEARCH_NUMBER, AccountSearch::SEARCH_ID];

    private const int MAX_QUERY = 1000;

    public function search(Request $request): JsonResponse
    {
        $args   = $this->input($request, ['query' => ['required', 'string', 'max:'.self::MAX_QUERY], 'limit' => ['sometimes', 'nullable'], 'offset' => ['sometimes', 'nullable']], true);
        $params = $this->listParams($request, ['date'], 'date');
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

        return $this->ok(
            array_merge(['transactions' => $transactions, 'total' => $total], $this->report($searcher, (string) $args['query'])),
            [
                'truncated'     => $truncated,
                'limit_applied' => $limit,
                'offset'        => $offset,
                'count'         => count($transactions),
                'next_offset'   => $truncated ? $offset + $limit : null,
                'order'         => 'date desc (Firefly\'s journal order: date, order, id)',
                'untrusted'     => self::UNTRUSTED_TRANSACTION_FIELDS,
            ] + ($params->clamped ? ['limit_requested' => $params->requestedLimit] : []),
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
        $type   = (string) ($args['type'] ?? 'all');
        $types  = $this->mapAccountTypes('' === $type ? 'all' : $type);

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

        try {
            $searcher->parseQuery($query);
        } catch (FireflyException $e) {
            throw MachineException::invalid('Firefly could not parse this query.', 'Check the quoting and the operator names — GET /machine/v1/search/operators', ['parser' => Envelope::scrub($e->getMessage())]);
        }
        $invalid = $searcher->getInvalidOperators();
        if ([] !== $invalid) {
            $known   = array_keys((array) config('search.operators'));
            $details = [];
            foreach ($invalid as $op) {
                $type      = strtolower((string) ($op['type'] ?? ''));
                $details[] = ['operator' => $type, 'did_you_mean' => self::closest($type, $known)];
            }
            $names = implode(', ', array_map(static fn (array $d): string => $d['operator'], $details));

            throw MachineException::invalid(
                sprintf('Unknown search operator%s: %s. Firefly would silently ignore %s and search wider than you meant.', 1 === count($details) ? '' : 's', $names, 1 === count($details) ? 'it' : 'them'),
                null !== $details[0]['did_you_mean'] ? sprintf('Did you mean %s:? GET /machine/v1/search/operators lists every operator', $details[0]['did_you_mean']) : 'GET /machine/v1/search/operators lists every operator',
                ['invalid_operators' => $details],
            );
        }

        return $searcher;
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
        } catch (Throwable) {
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
            } catch (Throwable) {
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
     * @template T
     *
     * @param \Closure(): T $fn
     *
     * @return T
     */
    private function run(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (MachineException $e) {
            throw $e;
        } catch (FireflyException $e) {
            throw MachineException::upstream('Firefly\'s search failed.', 'Narrow the query, or check it against GET /machine/v1/search/operators', ['reason' => Envelope::scrub($e->getMessage())]);
        } catch (Throwable $e) {
            throw $e;
        }
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

    /** @param list<string> $known */
    private static function closest(string $word, array $known): ?string
    {
        $best  = null;
        $score = PHP_INT_MAX;
        foreach ($known as $candidate) {
            $d = levenshtein($word, (string) $candidate);
            if ($d < $score) {
                $score = $d;
                $best  = (string) $candidate;
            }
        }

        return null !== $best && $score <= max(2, intdiv(strlen($word), 3)) ? $best : null;
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
