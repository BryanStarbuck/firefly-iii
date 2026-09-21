<?php

/*
 * OfxParser.php
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

namespace FireflyIII\Machine\Ingest\Parsers;

use FireflyIII\Machine\Ingest\StatementRow;
use FireflyIII\Machine\Ingest\Values;

/**
 * OFX / QFX — both the SGML 1.x dialect (no closing tags) and XML 2.x. Reads every
 * <STMTTRN> (bank and credit-card statements alike): DTPOSTED, TRNAMT, FITID, NAME, MEMO; the
 * statement's ACCTID (→ last-4), CURDEF, and DTSTART/DTEND (→ the period).
 *
 * TRNAMT is already signed from the account holder's view — a card purchase is negative — so it
 * is used as is. The FITID becomes the external_id (`ofx:{last4}:{FITID}`, apis.mdx §11.5).
 */
final class OfxParser
{
    /** @return array<string, mixed> see ParseResult */
    public static function parse(string $content): array
    {
        $result = ParseResult::empty();
        // SGML header lines and the body; strip an XML prolog, normalise line ends
        $body   = str_replace(["\r\n", "\r"], "\n", $content);
        if (!str_contains(strtoupper($body), '<OFX>')) {
            $result['warnings'][] = 'not an OFX document (no <OFX> element)';
            $result['unreadable'] = true;

            return $result;
        }
        $acct   = self::tag($body, 'ACCTID');
        if (null !== $acct) {
            $digits = (string) preg_replace('/\D/', '', $acct);
            if (strlen($digits) >= 4) {
                $result['last4'] = substr($digits, -4);
            }
        }
        $result['currency']    = self::tag($body, 'CURDEF');
        $start                 = self::tag($body, 'DTSTART');
        $end                   = self::tag($body, 'DTEND');
        $result['period_start'] = null === $start ? null : Values::date($start);
        $result['period_end']   = null === $end ? null : Values::date($end);
        // DTEND is exclusive in many exports (midnight after the last day) — keep it as written;
        // the period is an identity for grouping, not arithmetic.
        $org                   = self::tag($body, 'ORG');
        if (null !== $org) {
            $result['institution'] = $org;
        }

        if (false === preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/BANKTRANLIST>))/is', $body, $blocks, PREG_OFFSET_CAPTURE)) {
            return $result;
        }
        foreach ($blocks[1] as [$block, $offset]) {
            $line   = substr_count(substr($body, 0, (int) $offset), "\n") + 1;
            $posted = self::tag($block, 'DTPOSTED') ?? self::tag($block, 'DTUSER');
            $amount = self::tag($block, 'TRNAMT');
            $fitid  = self::tag($block, 'FITID');
            $name   = self::tag($block, 'NAME') ?? self::tag($block, 'PAYEE') ?? '';
            $memo   = self::tag($block, 'MEMO') ?? '';
            $text   = trim($name);
            if ('' !== trim($memo) && trim($memo) !== $text) {
                $text = '' === $text ? trim($memo) : $text.' '.trim($memo);
            }
            $date   = null === $posted ? null : Values::date($posted);
            $value  = null === $amount ? null : Values::amount($amount);
            if (null === $date || null === $value) {
                $result['bad'][] = ['line' => $line, 'text' => $text, 'reason' => null === $date ? 'unreadable date' : 'unreadable amount'];

                continue;
            }
            $result['rows'][] = new StatementRow($date, $value, $text, $line, null === $fitid || '' === trim($fitid) ? null : trim($fitid));
        }

        return $result;
    }

    /** The value of the first <TAG> — SGML (value runs to the next tag or line end) or XML. */
    private static function tag(string $text, string $tag): ?string
    {
        if (1 !== preg_match('/<'.$tag.'>([^<\n]*)/i', $text, $m)) {
            return null;
        }
        $value = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return '' === $value ? null : $value;
    }
}
