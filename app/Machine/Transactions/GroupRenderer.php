<?php

/*
 * GroupRenderer.php
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

namespace FireflyIII\Machine\Transactions;

use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Machine\Money;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalLink;
use FireflyIII\Models\UserGroup;
use FireflyIII\Transformers\TransactionGroupTransformer;
use FireflyIII\Transformers\TransactionLinkTransformer;
use FireflyIII\Support\JsonApi\Enrichments\TransactionGroupEnrichment;
use FireflyIII\User;
use Illuminate\Support\Collection;

/**
 * Renders transaction groups exactly as upstream's /api/v1 does — the group collector with
 * withAPIInformation(), TransactionGroupEnrichment, TransactionGroupTransformer — minus the
 * JSON:API wrapper (apis.mdx §5.1: data is Firefly's, verbatim, snake_case). The one change is
 * §14.1: amounts are rendered at their currency's own decimal places, as strings.
 */
final class GroupRenderer
{
    /** Split fields that are amounts, and the field that names the places they render at. */
    private const array AMOUNT_PLACES = [
        'amount'                    => 'currency_decimal_places',
        'foreign_amount'            => 'foreign_currency_decimal_places',
        'pc_amount'                 => 'primary_currency_decimal_places',
        'pc_foreign_amount'         => 'primary_currency_decimal_places',
        'source_balance_after'      => 'currency_decimal_places',
        'destination_balance_after' => 'currency_decimal_places',
    ];

    /** A group collector scoped to the operator and the bound administration. */
    public static function collector(User $user, UserGroup $group): GroupCollectorInterface
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($user)->setUserGroup($group);

        return $collector;
    }

    /**
     * Render these groups (every split of each), in the order given. Missing ids are skipped.
     *
     * @param list<int> $groupIds
     *
     * @return list<array<string, mixed>>
     */
    public static function groups(array $groupIds, User $user, UserGroup $group): array
    {
        if ([] === $groupIds) {
            return [];
        }
        $collector   = self::collector($user, $group);
        $collector->setIds($groupIds)->withAPIInformation();
        $raw         = $collector->getGroups();

        $enrichment  = new TransactionGroupEnrichment();
        $enrichment->setUser($user);
        $raw         = $enrichment->enrich($raw);

        /** @var TransactionGroupTransformer $transformer */
        $transformer = app(TransactionGroupTransformer::class);
        $byId        = [];
        foreach ($raw as $entry) {
            $rendered                    = self::money($transformer->transform($entry));
            $byId[(int) $rendered['id']] = $rendered;
        }
        $out         = [];
        foreach ($groupIds as $id) {
            if (array_key_exists((int) $id, $byId)) {
                $out[] = $byId[(int) $id];
            }
        }

        return $out;
    }

    /** @return null|array<string, mixed> */
    public static function one(int $groupId, User $user, UserGroup $group): ?array
    {
        return self::groups([$groupId], $user, $group)[0] ?? null;
    }

    /**
     * Amounts at their currency's places (§14.1), always strings; an absent amount stays null.
     *
     * @param array<string, mixed> $group
     *
     * @return array<string, mixed>
     */
    public static function money(array $group): array
    {
        foreach ((array) ($group['transactions'] ?? []) as $i => $split) {
            foreach (self::AMOUNT_PLACES as $field => $placesField) {
                $value  = $split[$field] ?? null;
                $places = $split[$placesField] ?? null;
                if (null === $value || '' === $value || null === $places) {
                    continue;
                }
                $group['transactions'][$i][$field] = Money::format((string) $value, (int) $places);
            }
        }

        return $group;
    }

    /**
     * The journal links of a group (Firefly's "relates to", "refunds", "reimburses"…), each seen
     * from this group's side: which of our splits, the relation as Firefly words it in that
     * direction, and the other transaction.
     *
     * @param list<int> $journalIds
     *
     * @return list<array<string, mixed>>
     */
    public static function links(array $journalIds): array
    {
        if ([] === $journalIds) {
            return [];
        }
        $set         = TransactionJournalLink::query()
            ->where(static function ($q) use ($journalIds): void {
                $q->whereIn('source_id', $journalIds)->orWhereIn('destination_id', $journalIds);
            })
            ->with(['linkType', 'source', 'destination'])
            ->orderBy('id')
            ->get()
        ;

        /** @var TransactionLinkTransformer $transformer */
        $transformer = app(TransactionLinkTransformer::class);
        $out         = [];

        /** @var TransactionJournalLink $link */
        foreach ($set as $link) {
            $base       = $transformer->transform($link);
            unset($base['links']);
            $ours       = in_array((int) $link->source_id, $journalIds, true);
            $other      = $ours ? $link->destination : $link->source;
            $out[]      = array_merge($base, [
                'link_type_name'         => $link->linkType?->name,
                'journal_id'             => (string) ($ours ? $link->source_id : $link->destination_id),
                // the sentence reads "<this journal> <relation> <the other>"
                'relation'               => $ours ? $link->linkType?->outward : $link->linkType?->inward,
                'direction'              => $ours ? 'outward' : 'inward',
                'other_journal_id'       => null === $other ? null : (string) $other->id,
                'other_group_id'         => null === $other ? null : (string) $other->transaction_group_id,
                'other_description'      => $other?->description,
            ], self::journalAmount($other));
        }

        return $out;
    }

    /**
     * The positive amount of a journal with its currency code — never a formatted display string.
     *
     * @return array{other_amount: null|string, other_currency_code: null|string}
     */
    private static function journalAmount(?TransactionJournal $journal): array
    {
        if (null === $journal) {
            return ['other_amount' => null, 'other_currency_code' => null];
        }

        /** @var null|Transaction $positive */
        $positive = $journal->transactions()->where('amount', '>', 0)->with('transactionCurrency')->first();
        if (null === $positive || null === $positive->transactionCurrency) {
            return ['other_amount' => null, 'other_currency_code' => null];
        }

        return [
            'other_amount'        => Money::forCurrency((string) $positive->amount, $positive->transactionCurrency),
            'other_currency_code' => $positive->transactionCurrency->code,
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $journals
     *
     * @return list<int>
     */
    public static function groupIdsOf(Collection $journals): array
    {
        return array_values(array_unique($journals->map(static fn (array $j): int => (int) $j['transaction_group_id'])->all()));
    }
}
