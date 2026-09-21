<?php

/*
 * RowBuilder.php
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
 * De-duplication layer two — the TRANSACTION level (cli.mdx §10.5–§10.6, apis.mdx §11.5, LOCKED).
 *
 * Every row gets a deterministic external_id:
 *   OFX/QFX with a FITID   ofx:{last4}:{FITID}                  the bank's own id, never minted over
 *   everything else        ff1:{last4}:{YYYYMMDD}:{amount}:{ordinal}:{sha1_8(normalised description)}
 *
 * THE ORDINAL IS COUNTED OVER THE MERGED ACCOUNT-MONTH, never per statement: every primary
 * statement feeding the account is concatenated — sorted by period_end, then sha256, rows in
 * source_line order — and ordinals are assigned per (date, amount, normalised description)
 * tie-group over that merged set. Two genuine $5.00 coffees on the same day get ordinals 0 and 1
 * even when a cycle-dated card (15th → 14th) reports them in two different statements; the same
 * coffee seen in two scans of one month (layer one collapsed the scan) gets the same id.
 *
 * Rows sharing an external_id (the same FITID in two overlapping OFX downloads) are collapsed
 * here and reported; everything else about "already exists" is Firefly's decision (§11.6).
 */
final class RowBuilder
{
    /**
     * @param array<string, mixed>  $account         a manifest entry
     * @param list<ParsedStatement> $statements      the account's statements, with layer-one verdicts
     * @param list<string>          $blockedMonths   YYYY-MM blocked by a statement conflict
     *
     * @return array{rows: list<array<string, mixed>>, dupes: list<array<string, mixed>>, bad: list<array<string, mixed>>}
     */
    public static function build(array $account, array $statements, array $blockedMonths): array
    {
        $primaries = array_values(array_filter($statements, static fn (ParsedStatement $s): bool => ParsedStatement::PRIMARY === $s->status));
        usort($primaries, static function (ParsedStatement $a, ParsedStatement $b): int {
            $ea = $a->periodEnd ?? ($a->rowRange()[1] ?? '');
            $eb = $b->periodEnd ?? ($b->rowRange()[1] ?? '');

            return [$ea, $a->sha256, $a->relative] <=> [$eb, $b->sha256, $b->relative];
        });
        $last4     = (string) ($account['last4'] ?? '');
        $idLast4   = '' === $last4 ? '0000' : $last4;
        $blocked   = array_flip($blockedMonths);
        $kept      = [];
        $seen      = [];
        $dupes     = [];
        $bad       = [];
        // pass 1: read every primary statement in the merged order; a bank id seen twice
        // (overlapping OFX downloads) is collapsed here and reported
        foreach ($primaries as $s) {
            $lines = $s->rows;
            usort($lines, static fn (StatementRow $a, StatementRow $b): int => $a->line <=> $b->line);
            foreach ($lines as $r) {
                $description = Normaliser::description($r->text, $account['institution'] ?? $s->institution);
                $month       = substr($r->date, 0, 7);
                $row         = [
                    'account'            => $account['key'],
                    'date'               => $r->date,
                    'month'              => $month,
                    'amount'             => $r->amount,
                    'type'               => str_starts_with($r->amount, '-') ? 'withdrawal' : 'deposit',
                    'description'        => $description,
                    'internal_reference' => $r->text,
                    'external_id'        => null,
                    'ordinal'            => 0,
                    'tie_key'            => sprintf('%s|%s|%s', $r->date, $r->amount, Normaliser::tieKey($description)),
                    'tie_group'          => 1,
                    'source_file'        => $s->source(),
                    'source_kind'        => $s->sourceKind,
                    'source_line'        => $r->line,
                    'statement'          => $s->relative,
                    'status'             => isset($blocked[$month]) ? 'blocked' : ('0' === $r->amount ? 'zero_amount' : 'ok'),
                    'bank_id'            => null !== $r->fitid,
                ];
                if (null !== $r->fitid) {
                    $row['external_id'] = sprintf('ofx:%s:%s', $idLast4, $r->fitid);
                    if (isset($seen[$row['external_id']])) {
                        $dupes[] = [
                            'layer'         => 'transaction',
                            'account'       => $row['account'],
                            'period'        => $row['month'],
                            'verdict'       => 'collapsed',
                            'rule'          => 'same_external_id',
                            'file'          => $row['source_file'].':'.$row['source_line'],
                            'superseded_by' => $seen[$row['external_id']],
                            'external_id'   => $row['external_id'],
                            'ordinal'       => null,
                            'tie_group'     => null,
                        ];

                        continue;
                    }
                    $seen[$row['external_id']] = $row['source_file'].':'.$row['source_line'];
                }
                $kept[] = $row;
            }
            foreach ($s->bad as $b) {
                $bad[] = ['account' => $account['key'], 'file' => $s->source(), 'line' => $b['line'], 'text' => $b['text'], 'reason' => $b['reason']];
            }
        }
        // pass 2: ordinals per (date, amount, normalised description) tie-group over the MERGED
        // account-month; a minted id carries its ordinal, a bank id already differs by itself
        $counters  = [];
        foreach ($kept as $i => $row) {
            $ordinal                 = $counters[$row['tie_key']] ?? 0;
            $counters[$row['tie_key']] = $ordinal + 1;
            $kept[$i]['ordinal']     = $ordinal;
            if (!$row['bank_id']) {
                $kept[$i]['external_id'] = sprintf('ff1:%s:%s:%s:%d:%s', $idLast4, str_replace('-', '', $row['date']), $row['amount'], $ordinal, Normaliser::sha1_8($row['description']));
            }
        }
        // tie-group sizes, recorded because they are the one thing a human cannot re-derive
        $out       = [];
        foreach ($kept as $row) {
            $row['tie_group'] = $counters[$row['tie_key']];
            unset($row['tie_key'], $row['bank_id']);
            $out[] = $row;
            if ($row['tie_group'] > 1) {
                $dupes[] = [
                    'layer'         => 'transaction',
                    'account'       => $row['account'],
                    'period'        => $row['month'],
                    'verdict'       => 'kept',
                    'rule'          => 'ordinal',
                    'file'          => $row['source_file'].':'.$row['source_line'],
                    'superseded_by' => null,
                    'external_id'   => $row['external_id'],
                    'ordinal'       => $row['ordinal'],
                    'tie_group'     => $row['tie_group'],
                ];
            }
        }
        foreach ($out as $row) {
            if ('zero_amount' === $row['status']) {
                $bad[] = ['account' => $row['account'], 'file' => $row['source_file'], 'line' => $row['source_line'], 'text' => $row['internal_reference'], 'reason' => 'zero amount (Firefly stores no zero transactions)'];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['date'], $a['external_id']] <=> [$b['date'], $b['external_id']]);

        return ['rows' => $out, 'dupes' => $dupes, 'bad' => $bad];
    }
}
