<?php

/*
 * CamtParser.php
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

use DOMDocument;
use DOMElement;
use DOMXPath;
use FireflyIII\Machine\Ingest\StatementRow;
use FireflyIII\Machine\Ingest\Values;

/**
 * ISO 20022 camt.053 (bank-to-customer statement). Reads each Stmt's account (IBAN or other id →
 * last-4), currency, FrToDt period, and each Ntry: Amt, CdtDbtInd (DBIT is money out), BookgDt
 * (else ValDt), and the text (Ustrd remittance lines, else AddtlNtryInf, else the counterparty
 * name). Parsed with DOM, no network, no entity expansion.
 */
final class CamtParser
{
    public static function looksLikeCamt(string $text): bool
    {
        $head = substr($text, 0, 4096);

        return str_contains($head, 'camt.053') || str_contains($head, 'BkToCstmrStmt');
    }

    /** @return array<string, mixed> see ParseResult */
    public static function parse(string $content): array
    {
        $result = ParseResult::empty();
        $doc    = new DOMDocument();
        $prev   = libxml_use_internal_errors(true);
        $ok     = '' !== trim($content) && $doc->loadXML($content, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            $result['warnings'][] = 'not a readable camt.053 XML document';
            $result['unreadable'] = true;

            return $result;
        }
        $xp     = new DOMXPath($doc);
        $q      = static fn (string $path, ?DOMElement $ctx = null): ?string => self::first($xp, $path, $ctx);

        $acct   = $q('//*[local-name()="Stmt"]/*[local-name()="Acct"]/*[local-name()="Id"]//*[local-name()="IBAN" or local-name()="Id"]');
        if (null !== $acct) {
            $digits = (string) preg_replace('/\D/', '', $acct);
            if (strlen($digits) >= 4) {
                $result['last4'] = substr($digits, -4);
            }
        }
        $result['currency']     = $q('//*[local-name()="Stmt"]/*[local-name()="Acct"]/*[local-name()="Ccy"]');
        $from                   = $q('//*[local-name()="Stmt"]/*[local-name()="FrToDt"]/*[local-name()="FrDtTm" or local-name()="FrDt"]');
        $to                     = $q('//*[local-name()="Stmt"]/*[local-name()="FrToDt"]/*[local-name()="ToDtTm" or local-name()="ToDt"]');
        $result['period_start'] = null === $from ? null : Values::date($from);
        $result['period_end']   = null === $to ? null : Values::date($to);
        $inst                   = $q('//*[local-name()="Stmt"]/*[local-name()="Acct"]/*[local-name()="Svcr"]//*[local-name()="Nm"]');
        if (null !== $inst) {
            $result['institution'] = $inst;
        }

        $entries                = $xp->query('//*[local-name()="Ntry"]');
        foreach (false === $entries ? [] : $entries as $entry) {
            if (!$entry instanceof DOMElement) {
                continue;
            }
            $line      = $entry->getLineNo();
            $amount    = $q('./*[local-name()="Amt"]', $entry);
            $indicator = strtoupper((string) $q('./*[local-name()="CdtDbtInd"]', $entry));
            $date      = $q('./*[local-name()="BookgDt"]/*', $entry) ?? $q('./*[local-name()="ValDt"]/*', $entry);
            $texts     = [];
            $ustrd     = $xp->query('.//*[local-name()="RmtInf"]/*[local-name()="Ustrd"]', $entry);
            foreach (false === $ustrd ? [] : $ustrd as $u) {
                $texts[] = trim((string) $u->textContent);
            }
            $text      = trim(implode(' ', array_filter($texts)));
            if ('' === $text) {
                $text = (string) ($q('./*[local-name()="AddtlNtryInf"]', $entry) ?? $q('.//*[local-name()="RltdPties"]//*[local-name()="Nm"]', $entry) ?? '');
            }
            $d         = null === $date ? null : Values::date($date);
            $value     = null === $amount ? null : Values::amount($amount);
            if (null === $d || null === $value || !in_array($indicator, ['CRDT', 'DBIT'], true)) {
                $result['bad'][] = ['line' => $line, 'text' => $text, 'reason' => null === $d ? 'unreadable date' : (null === $value ? 'unreadable amount' : 'no credit/debit indicator')];

                continue;
            }
            $value     = ltrim($value, '-');
            if ('DBIT' === $indicator && '0' !== $value) {
                $value = '-'.$value;
            }
            $result['rows'][] = new StatementRow($d, $value, $text, $line);
        }

        return $result;
    }

    private static function first(DOMXPath $xp, string $path, ?DOMElement $ctx = null): ?string
    {
        $nodes = null === $ctx ? $xp->query($path) : $xp->query($path, $ctx);
        if (false === $nodes || 0 === $nodes->length) {
            return null;
        }
        $value = trim((string) $nodes->item(0)?->textContent);

        return '' === $value ? null : $value;
    }
}
