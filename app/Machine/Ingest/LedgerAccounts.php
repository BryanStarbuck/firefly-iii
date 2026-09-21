<?php

/*
 * LedgerAccounts.php
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

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\UserGroup;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\User;

/**
 * The administration's statement-bearing accounts — asset and liability — as the ingest plane
 * matches them: id, name, type, role, number, IBAN, currency. Read through Firefly's own account
 * repository (its meta and currency rules), never re-derived.
 */
final class LedgerAccounts
{
    public const array TYPES = [AccountTypeEnum::ASSET->value, AccountTypeEnum::LOAN->value, AccountTypeEnum::DEBT->value, AccountTypeEnum::MORTGAGE->value];

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    public function __construct(private readonly User $user, private readonly UserGroup $group, private readonly TransactionCurrency $primary)
    {
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);
        $accounts   = Account::query()
            ->where('accounts.user_group_id', $group->id)
            ->whereHas('accountType', static fn ($q) => $q->whereIn('type', self::TYPES))
            ->with(['accountType', 'accountMeta'])
            ->orderBy('accounts.id')
            ->get()
        ;

        /** @var Account $a */
        foreach ($accounts as $a) {
            $currency           = $repository->getAccountCurrency($a) ?? $primary;
            $this->rows[$a->id] = [
                'id'             => (int) $a->id,
                'name'           => (string) $a->name,
                'type'           => AccountTypeEnum::ASSET->value === $a->accountType->type ? 'asset' : 'liability',
                'firefly_type'   => (string) $a->accountType->type,
                'role'           => $repository->getMetaValue($a, 'account_role'),
                'account_number' => $repository->getMetaValue($a, 'account_number'),
                'iban'           => null === $a->iban || '' === $a->iban ? null : (string) $a->iban,
                'currency_code'  => (string) $currency->code,
                'currency_places' => (int) $currency->decimal_places,
                'active'         => (bool) $a->active,
            ];
        }
    }

    public function user(): User
    {
        return $this->user;
    }

    public function group(): UserGroup
    {
        return $this->group;
    }

    public function primary(): TransactionCurrency
    {
        return $this->primary;
    }

    /** @return null|array<string, mixed> */
    public function find(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values($this->rows);
    }

    /** @return list<array<string, mixed>> accounts whose number or IBAN ends in $last4 */
    public function byLast4(string $last4): array
    {
        if (1 !== preg_match('/^\d{4}$/', $last4)) {
            return [];
        }

        return array_values(array_filter($this->rows, static function (array $r) use ($last4): bool {
            foreach ([$r['account_number'], $r['iban']] as $n) {
                $digits = (string) preg_replace('/\D/', '', (string) $n);
                if (strlen($digits) >= 4 && str_ends_with($digits, $last4)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** @return list<array<string, mixed>> exact name, else case-insensitive */
    public function byName(string $name): array
    {
        $exact = array_values(array_filter($this->rows, static fn (array $r): bool => $r['name'] === $name));
        if ([] !== $exact) {
            return $exact;
        }
        $lower = mb_strtolower($name);

        return array_values(array_filter($this->rows, static fn (array $r): bool => mb_strtolower($r['name']) === $lower));
    }

    /** @param array<string, mixed> $row */
    public static function brief(array $row): array
    {
        return ['id' => $row['id'], 'name' => $row['name'], 'type' => $row['type'], 'account_number' => $row['account_number'], 'currency_code' => $row['currency_code']];
    }
}
