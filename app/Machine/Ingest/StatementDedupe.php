<?php

/*
 * StatementDedupe.php
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

namespace FireflyIII\Machine\Ingest;

/**
 * De-duplication layer one — the STATEMENT level (cli.mdx §10.4, apis.mdx §11.5, LOCKED).
 *
 * "The same month, scanned twice." Statements are grouped on (entity, institution, last-4,
 * period) as read from INSIDE each document. Within a group:
 *
 *   1. identical bytes              → one statement; the copies are `duplicate_identical`
 *   2. different bytes that agree   → ranked by a fixed rule chain — more rows; wider date range;
 *      (one's transactions contain    better extraction source; newer mtime; first path — and the
 *       the other's)                   losers are `superseded`, each recording the rule that decided
 *   3. candidates that disagree     → `conflict`: blocks the account-months it covers until the
 *      about which transactions        operator prefers one (/ingest/prefer). Choosing silently is
 *      exist                           choosing which transactions exist.
 *
 * Nothing is deleted; every verdict is reported.
 */
final class StatementDedupe
{
    public const array RULE_CHAIN = ['more_rows', 'wider_date_range', 'better_source', 'newer_mtime', 'first_path'];

    /**
     * @param list<ParsedStatement>  $statements  one account's readable statements (status primary on entry)
     * @param array<string, string>  $preferences group key => the preferred statement's relative path
     *
     * @return array{groups: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, blocked_months: list<string>}
     */
    public static function run(array $statements, array $account, array $preferences): array
    {
        $groups = [];
        foreach ($statements as $s) {
            if (ParsedStatement::PRIMARY !== $s->status) {
                continue;
            }
            $s->groupKey             = self::groupKey($s, $account);
            $groups[$s->groupKey][]  = $s;
        }
        ksort($groups, SORT_STRING);
        $report    = [];
        $conflicts = [];
        $blocked   = [];
        foreach ($groups as $key => $members) {
            usort($members, static fn (ParsedStatement $a, ParsedStatement $b): int => strcmp($a->relative, $b->relative));
            // 1. identical bytes
            $distinct = [];
            foreach ($members as $m) {
                if (isset($distinct[$m->sha256])) {
                    $m->status       = ParsedStatement::DUPLICATE_IDENTICAL;
                    $m->rule         = 'identical_bytes';
                    $m->supersededBy = $distinct[$m->sha256]->relative;

                    continue;
                }
                $distinct[$m->sha256] = $m;
            }
            $distinct = array_values($distinct);
            $verdict  = 1 === count($members) ? 'single' : 'duplicate_identical';
            if (count($distinct) > 1) {
                $preferred = $preferences[$key] ?? null;
                $pick      = null;
                foreach ($distinct as $d) {
                    if (null !== $preferred && ($d->relative === $preferred || $d->source() === $preferred)) {
                        $pick = $d;
                    }
                }
                if (null !== $pick) {
                    foreach ($distinct as $d) {
                        if ($d !== $pick) {
                            $d->status       = ParsedStatement::SUPERSEDED;
                            $d->rule         = 'operator_preferred';
                            $d->supersededBy = $pick->relative;
                        }
                    }
                    $pick->rule = 'operator_preferred';
                    $verdict    = 'resolved';
                } else {
                    usort($distinct, self::rank(...));
                    $winner = $distinct[0];
                    $agree  = true;
                    foreach (array_slice($distinct, 1) as $loser) {
                        if (!self::contains($winner->fingerprintBag(), $loser->fingerprintBag())) {
                            $agree = false;
                        }
                    }
                    if ($agree) {
                        foreach (array_slice($distinct, 1) as $loser) {
                            $loser->status       = ParsedStatement::SUPERSEDED;
                            $loser->rule         = self::decidingRule($winner, $loser);
                            $loser->supersededBy = $winner->relative;
                        }
                        $verdict = 'superseded';
                    } else {
                        foreach ($distinct as $d) {
                            $d->status = ParsedStatement::CONFLICT;
                            $d->rule   = 'transactions_disagree';
                        }
                        $months      = [];
                        foreach ($distinct as $d) {
                            $months = array_merge($months, $d->months());
                        }
                        $months      = array_values(array_unique($months));
                        sort($months);
                        $blocked     = array_merge($blocked, $months);
                        $conflicts[] = [
                            'group'   => $key,
                            'account' => $account['key'],
                            'period'  => $distinct[0]->period(),
                            'months'  => $months,
                            'files'   => array_map(static fn (ParsedStatement $d): string => $d->relative, $distinct),
                            'rows'    => array_map(static fn (ParsedStatement $d): int => count($d->rows), $distinct),
                        ];
                        $verdict     = 'conflict';
                    }
                }
            }
            $report[] = [
                'group'   => $key,
                'account' => $account['key'],
                'period'  => $members[0]->period(),
                'verdict' => $verdict,
                'members' => array_map(static fn (ParsedStatement $m): array => [
                    'file'          => $m->relative,
                    'source'        => $m->source(),
                    'source_kind'   => $m->sourceKind,
                    'sha256'        => $m->sha256,
                    'rows'          => count($m->rows),
                    'status'        => $m->status,
                    'rule'          => $m->rule,
                    'superseded_by' => $m->supersededBy,
                ], $members),
            ];
        }
        $blocked = array_values(array_unique($blocked));
        sort($blocked);

        return ['groups' => $report, 'conflicts' => $conflicts, 'blocked_months' => $blocked];
    }

    /**
     * The group: entity, institution, last-4 and period — from inside the document, falling back
     * to the account only where the document is silent.
     *
     * @param array<string, mixed> $account
     */
    public static function groupKey(ParsedStatement $s, array $account): string
    {
        return implode('|', [
            mb_strtolower(trim((string) ($s->entity ?? $account['entity'] ?? ''))),
            mb_strtolower(trim((string) ($s->institution ?? $account['institution'] ?? ''))),
            (string) ($s->last4 ?? $account['last4'] ?? ''),
            $s->periodStart ?? '?',
            $s->periodEnd ?? '?',
        ]);
    }

    /** The fixed rule chain: negative when $a ranks first. */
    private static function rank(ParsedStatement $a, ParsedStatement $b): int
    {
        foreach (self::RULE_CHAIN as $rule) {
            $c = self::compare($rule, $a, $b);
            if (0 !== $c) {
                return $c;
            }
        }

        return 0;
    }

    private static function decidingRule(ParsedStatement $winner, ParsedStatement $loser): string
    {
        foreach (self::RULE_CHAIN as $rule) {
            if (0 !== self::compare($rule, $winner, $loser)) {
                return $rule;
            }
        }

        return 'first_path';
    }

    private static function compare(string $rule, ParsedStatement $a, ParsedStatement $b): int
    {
        return match ($rule) {
            'more_rows'        => count($b->rows) <=> count($a->rows),
            'wider_date_range' => self::span($b) <=> self::span($a),
            'better_source'    => (ParsedStatement::SOURCE_RANK[$a->sourceKind] ?? 9) <=> (ParsedStatement::SOURCE_RANK[$b->sourceKind] ?? 9),
            'newer_mtime'      => $b->mtime <=> $a->mtime,
            default            => strcmp($a->relative, $b->relative),
        };
    }

    private static function span(ParsedStatement $s): int
    {
        $r = $s->rowRange();

        return null === $r ? 0 : Values::daysBetween($r[0], $r[1]);
    }

    /**
     * Does multiset $big contain multiset $small?
     *
     * @param array<string, int> $big
     * @param array<string, int> $small
     */
    private static function contains(array $big, array $small): bool
    {
        foreach ($small as $k => $n) {
            if (($big[$k] ?? 0) < $n) {
                return false;
            }
        }

        return true;
    }
}
