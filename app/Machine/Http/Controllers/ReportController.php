<?php

/*
 * ReportController.php
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

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Machine\Analytics\Reports;
use FireflyIII\Machine\Analytics\ScopedController;
use FireflyIII\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The numbers of Firefly's six report types — apis.mdx §8.9. Each calls the same report code
 * the UI calls (AccountTasker, the Audit report generator, the budget/category/tag operations)
 * and returns what it computed, in sections of rows, per currency. The HTML stays in the UI.
 *
 * Reports count asset accounts by default (as the UI's report form does); account_ids[] (or
 * account_names[]) narrows them.
 */
final class ReportController extends ScopedController
{
    private const array REPORT_ACCOUNT_TYPES = [AccountTypeEnum::ASSET->value];

    public function default(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::RANGE_RULES, self::ACCOUNT_RULES, ['currency_code' => self::FILTER_RULES['currency_code']]), true);

        return $this->ok($this->reports()->default($this->scope($args, null, self::REPORT_ACCOUNT_TYPES, 'none')));
    }

    /**
     * Every journal with the running balance after it. The journals section is a list, so it is
     * bounded like every list (§5.5): `limit` (default and cap 5,000 — clamped, never rejected)
     * and `offset`, in date order; meta.truncated says when a cap bound it. The per-account
     * summaries count the whole account, not the page.
     */
    public function audit(Request $request): JsonResponse
    {
        $args      = $this->input($request, array_merge(self::RANGE_RULES, self::ACCOUNT_RULES, [
            'currency_code' => self::FILTER_RULES['currency_code'],
            'limit'         => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'offset'        => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
        ]), true);
        $requested = (int) ($args['limit'] ?? Reports::AUDIT_MAX_ROWS);
        $limit     = max(1, min($requested, Reports::AUDIT_MAX_ROWS)); // clamped, never rejected (§5.5)
        $offset    = (int) ($args['offset'] ?? 0);
        $report    = $this->reports()->audit($this->scope($args, null, self::REPORT_ACCOUNT_TYPES));
        $total     = count($report['journals']);
        $page      = array_slice($report['journals'], $offset, $limit);
        $truncated = $offset + count($page) < $total;
        $meta      = [
            'truncated'     => $truncated,
            'limit_applied' => $limit,
            'offset'        => $offset,
            'order'         => 'account_id,date,journal_id',
            'count'         => count($page),
            'total'         => $total,
            'next_offset'   => $truncated ? $offset + $limit : null,
        ];
        if ($requested !== $limit) {
            $meta['limit_requested'] = $requested;
        }
        $this->addMeta($meta);
        $report['journals'] = $page;
        if ($truncated) {
            $report['notes'][] = sprintf('journals is a page: %d of %d — continue with offset=%d, or narrow with start/end or account_ids', count($page), $total, $offset + $limit);
        }

        return $this->ok($report);
    }

    public function budget(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::RANGE_RULES, self::ACCOUNT_RULES, [
            'budget_ids'     => self::FILTER_RULES['budget_ids'],
            'budget_ids.*'   => self::FILTER_RULES['budget_ids.*'],
            'budget_names'   => self::FILTER_RULES['budget_names'],
            'budget_names.*' => self::FILTER_RULES['budget_names.*'],
            'currency_code'  => self::FILTER_RULES['currency_code'],
        ]), true);

        return $this->ok($this->reports()->budget($this->scope($args, null, self::REPORT_ACCOUNT_TYPES)));
    }

    public function category(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::RANGE_RULES, self::ACCOUNT_RULES, self::INTERVAL_RULES, [
            'category_ids'     => self::FILTER_RULES['category_ids'],
            'category_ids.*'   => self::FILTER_RULES['category_ids.*'],
            'category_names'   => self::FILTER_RULES['category_names'],
            'category_names.*' => self::FILTER_RULES['category_names.*'],
            'currency_code'    => self::FILTER_RULES['currency_code'],
        ]), true);

        return $this->ok($this->reports()->grouped($this->scope($args, null, self::REPORT_ACCOUNT_TYPES), 'category'));
    }

    public function tag(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::RANGE_RULES, self::ACCOUNT_RULES, self::INTERVAL_RULES, [
            'tags'          => self::FILTER_RULES['tags'],
            'tags.*'        => self::FILTER_RULES['tags.*'],
            'currency_code' => self::FILTER_RULES['currency_code'],
        ]), true);

        return $this->ok($this->reports()->grouped($this->scope($args, null, self::REPORT_ACCOUNT_TYPES), 'tag'));
    }

    public function double(Request $request): JsonResponse
    {
        $args  = $this->input($request, array_merge(self::RANGE_RULES, self::ACCOUNT_RULES, [
            'counterparty_ids'     => ['sometimes', 'array', 'max:500'],
            'counterparty_ids.*'   => ['integer', 'min:1'],
            'counterparty_names'   => ['sometimes', 'array', 'max:500'],
            'counterparty_names.*' => ['string', 'min:1', 'max:1024'],
            'currency_code'        => self::FILTER_RULES['currency_code'],
        ]), true);
        $scope = $this->scope($args, null, self::REPORT_ACCOUNT_TYPES);
        $types = [AccountTypeEnum::EXPENSE->value, AccountTypeEnum::REVENUE->value, AccountTypeEnum::BENEFICIARY->value];
        $group = $this->administration()->id;
        $found = [];
        $only  = static function ($query) use ($group, $types): void {
            // whereHas, not a join: a join on account_types would overwrite accounts.id
            $query->where('accounts.user_group_id', $group)->whereHas('accountType', static fn ($t) => $t->whereIn('type', $types));
        };
        foreach ($args['counterparty_ids'] ?? [] as $id) {
            /** @var Account $account */
            $account             = $this->resolve(Account::class, (string) $id, 'name', $only);
            $found[$account->id] = $account;
        }
        foreach ($args['counterparty_names'] ?? [] as $name) {
            // a name is a name, even "4021" (§14.4) — never read as an id
            /** @var Account $account */
            $account             = $this->resolveByName(Account::class, (string) $name, 'name', $only);
            $found[$account->id] = $account;
        }
        ksort($found);

        return $this->ok($this->reports()->double($scope, collect(array_values($found))));
    }

    private function reports(): Reports
    {
        return new Reports($this->ledger());
    }
}
