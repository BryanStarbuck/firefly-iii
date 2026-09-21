<?php

/*
 * CategoryTree.php
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

namespace FireflyIII\Machine\Categories;

use Carbon\CarbonImmutable;

/**
 * The two-level category tree — apis.mdx §8.4a.
 *
 * Firefly III categories are FLAT: a category has a name and no parent. The fork's convention
 * for a tree is in the name: "Group > Sub" (split on the FIRST " > ") is subcategory "Sub" of
 * group "Group"; a name with no " > " is a group of its own. When both "Food" and
 * "Food > Groceries" exist, "Food" is the group's own id; when only the second exists, the group
 * "Food" exists only as a prefix and its id is null. A name with a blank side ("Food > ",
 * " > Groceries") does not split — it is a group named as written.
 *
 * The server computes this tree and its YAML document, so the CLI and the MCP only print it —
 * the same shape the sister apps (ezBookkeeping, Actual Budget) publish; Firefly has no category
 * type and no hidden flag, so those keys are absent.
 */
final class CategoryTree
{
    public const string SEPARATOR = ' > ';
    public const string APP       = 'firefly_iii';

    /**
     * "Food > Groceries" → ['Food', 'Groceries']; "Food" → ['Food', null]; only the FIRST
     * separator splits ("A > B > C" → ['A', 'B > C']).
     *
     * @return array{0: string, 1: null|string}
     */
    public static function split(string $name): array
    {
        $at = strpos($name, self::SEPARATOR);
        if (false === $at) {
            return [$name, null];
        }
        $group = trim(substr($name, 0, $at));
        $sub   = trim(substr($name, $at + strlen(self::SEPARATOR)));
        if ('' === $group || '' === $sub) {
            return [$name, null];
        }

        return [$group, $sub];
    }

    /**
     * @param iterable<array{id: int|string, name: string}> $categories every category of the administration
     *
     * @return array{counts: array{groups: int, subcategories: int}, groups: list<array{name: string, id: null|string, subcategories: list<array{name: string, full_name: string, id: string}>}>}
     */
    public static function build(iterable $categories): array
    {
        $groups = [];
        foreach ($categories as $category) {
            $full         = (string) $category['name'];
            $id           = (string) $category['id'];
            [$group, $sub] = self::split($full);
            $groups[$group] ??= ['name' => $group, 'id' => null, 'subcategories' => []];
            if (null === $sub) {
                $groups[$group]['id'] = $id;

                continue;
            }
            $groups[$group]['subcategories'][] = ['name' => $sub, 'full_name' => $full, 'id' => $id];
        }
        $subCount = 0;
        foreach ($groups as &$g) {
            usort($g['subcategories'], static fn (array $a, array $b): int => 0 !== ($c = self::byName($a['name'], $b['name'])) ? $c : strcmp($a['id'], $b['id']));
            $subCount += count($g['subcategories']);
        }
        unset($g);
        $list   = array_values($groups);
        usort($list, static fn (array $a, array $b): int => self::byName($a['name'], $b['name']));

        return ['counts' => ['groups' => count($list), 'subcategories' => $subCount], 'groups' => $list];
    }

    /**
     * The tree as the shared YAML document (key order fixed, two-space indent, one document).
     *
     * @param array{counts: array{groups: int, subcategories: int}, groups: list<array{name: string, id: null|string, subcategories: list<array{name: string, full_name: string, id: string}>}>} $tree
     */
    public static function yaml(array $tree, ?CarbonImmutable $generatedAt = null): string
    {
        $at    = ($generatedAt ?? CarbonImmutable::now('UTC'))->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
        $lines = [
            'app: '.self::APP,
            'generated_at: '.$at,
            'counts:',
            '  groups: '.$tree['counts']['groups'],
            '  subcategories: '.$tree['counts']['subcategories'],
        ];
        if ([] === $tree['groups']) {
            $lines[] = 'groups: []';

            return implode("\n", $lines)."\n";
        }
        $lines[] = 'groups:';
        foreach ($tree['groups'] as $group) {
            $lines[] = '  - name: '.self::scalar($group['name']);
            $lines[] = '    id: '.self::scalar($group['id']);
            if ([] === $group['subcategories']) {
                $lines[] = '    subcategories: []';

                continue;
            }
            $lines[] = '    subcategories:';
            foreach ($group['subcategories'] as $sub) {
                $lines[] = '      - name: '.self::scalar($sub['name']);
                $lines[] = '        full_name: '.self::scalar($sub['full_name']);
                $lines[] = '        id: '.self::scalar($sub['id']);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * One YAML scalar. A string stays plain only when no YAML reader could take it for anything
     * but that string: it starts with a letter, holds only letters, digits, spaces and _ . , ( ) / ' + - >,
     * does not end in a space, and is not a YAML 1.1 boolean or null word. Everything else —
     * ids ("12" must stay a string), names with ':' '&' '#' '*' '!' '"' or a leading '-' — is
     * double-quoted with JSON escapes, which are valid YAML double-quoted escapes.
     */
    public static function scalar(null|int|string $value): string
    {
        if (null === $value) {
            return 'null';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (1 === preg_match("~^\\p{L}[\\p{L}\\p{N} _.,()/'+>-]*(?<! )$~u", $value)
            && 1 !== preg_match('/^(y|n|yes|no|on|off|true|false|null)$/i', $value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** Case-insensitive, then case-sensitive, so the order is total and stable. */
    private static function byName(string $a, string $b): int
    {
        $c = strcmp(mb_strtolower($a), mb_strtolower($b));

        return 0 !== $c ? $c : strcmp($a, $b);
    }
}
