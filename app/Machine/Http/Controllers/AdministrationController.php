<?php

/*
 * AdministrationController.php
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

use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\UserGroup;
use FireflyIII\Repositories\Currency\CurrencyRepositoryInterface;
use FireflyIII\Repositories\UserGroup\UserGroupRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\Transformers\UserGroupTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * pm/apis.mdx §8.1 — administrations (Firefly's user groups: the sets of books).
 *
 * There is deliberately no route that switches the bound administration: the binding is
 * configuration (FIREFLY_MACHINE_ADMINISTRATION, §4.9) and is echoed on every response.
 *
 *   index()   GET /administrations        UserGroupRepository::get(), each marked `bound`
 *   show()    GET /administrations/{id}   one, with member_count and primary currency
 *   update()  PUT /administrations/{id}   title and/or primary_currency_code (write protocol)
 */
final class AdministrationController extends MachineController
{
    public function index(Request $request): JsonResponse
    {
        $this->input($request, self::LIST_RULES, true);
        $params = $this->listParams($request, ['id', 'title'], 'id');
        $rows   = array_map(fn (UserGroup $g): array => $this->render($g), $this->groups()->all());
        $rows   = $this->applyList($rows, $params);

        return $this->ok(['administrations' => $rows]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->input($request, [], true);

        return $this->ok(['administration' => $this->render($this->group($id))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $args  = $this->input($request, [
            'title'                 => ['sometimes', 'string', 'min:1', 'max:255'],
            'primary_currency_code' => ['sometimes', 'string', 'size:3'],
        ]);
        $group = $this->group($id);
        if (!array_key_exists('title', $args) && !array_key_exists('primary_currency_code', $args)) {
            throw MachineException::invalid('Nothing to change.', 'Pass "title" and/or "primary_currency_code"', ['accepted' => ['title', 'primary_currency_code']]);
        }
        $user  = $this->operator();
        if (!$user->hasRoleInGroupOrOwner($group, UserRoleEnum::FULL)) {
            throw MachineException::forbidden(
                sprintf('The operator is not an owner (or full member) of administration #%d.', $group->id),
                'Only an owner can rename an administration or change its primary currency — do it as the owner in the web UI',
                ['administration_id' => $group->id],
            );
        }
        $currency = null;
        if (array_key_exists('primary_currency_code', $args)) {
            $code     = strtoupper(trim((string) $args['primary_currency_code']));

            /** @var null|TransactionCurrency $currency */
            $currency = TransactionCurrency::query()->where('code', $code)->first();
            if (null === $currency) {
                throw MachineException::notFound(sprintf('No currency with code "%s".', $code), 'GET /machine/v1/currencies lists the codes this install knows', ['primary_currency_code' => $code]);
            }
        }
        $title    = array_key_exists('title', $args) ? trim((string) $args['title']) : null;
        if ('' === $title) {
            throw MachineException::invalid('title cannot be empty.', 'Pass a non-empty "title"', ['field' => 'title']);
        }
        $groupId  = (int) $group->id;

        return $this->write($request, $args, function (bool $dryRun) use ($groupId, $title, $currency): WriteResult {
            /** @var UserGroup $group */
            $group   = UserGroup::query()->findOrFail($groupId);
            $result  = new WriteResult();
            $current = Amount::getPrimaryCurrencyByUserGroup($group);
            $retitle = null !== $title && $title !== (string) $group->title;
            $recode  = $currency instanceof TransactionCurrency && (int) $currency->id !== (int) $current->id;
            $pcCount = 0;

            if ($retitle) {
                $result->updating($group);

                /** @var UserGroupRepositoryInterface $repository */
                $repository = app(UserGroupRepositoryInterface::class);
                $repository->setUser($this->operator());
                $repository->update($group, ['title' => $title]);
                $result->count('updated');
            }
            if ($recode) {
                $pcCount    = $this->convertedAmountCount($group);

                /** @var CurrencyRepositoryInterface $currencies */
                $currencies = app(CurrencyRepositoryInterface::class);
                $currencies->setUser($this->operator());
                $currencies->setUserGroup($group);
                $currencies->makePrimary($currency);
                $result->count('primary_currency_changed');
            }
            if (!$retitle && !$recode) {
                $result->count('unchanged');
            }
            $group->refresh();
            if (!$dryRun && (int) $this->administration()->id === $groupId) {
                $this->administration()->refresh(); // meta names the books as they now are
                request()->attributes->remove('machine.primary_currency');
            }
            $result->basis = ['title' => $title, 'currency' => $currency?->code, 'pc' => $pcCount];

            return $result->with([
                'administration'          => $this->render($group),
                'pc_amounts_recalculated' => $pcCount,
                'undo_note'               => $recode ? 'undo restores the title; a primary-currency change is reversed by changing it back' : null,
            ]);
        });
    }

    // ------------------------------------------------------------------------

    /** @return Collection<int, UserGroup> the operator's administrations */
    private function groups(): Collection
    {
        /** @var UserGroupRepositoryInterface $repository */
        $repository = app(UserGroupRepositoryInterface::class);
        $repository->setUser($this->operator());

        return $repository->get()->values();
    }

    /** One of the operator's administrations, by id or title; not_found otherwise (never another user's). */
    private function group(string $idOrTitle): UserGroup
    {
        $mine   = $this->groups()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        /** @var UserGroup $group */
        $group  = $this->resolve(UserGroup::class, $idOrTitle, 'title', static function ($query) use ($mine): void {
            $query->whereIn('user_groups.id', [] === $mine ? [0] : $mine);
        });

        return $group;
    }

    /** @return array<string, mixed> Firefly's UserGroupTransformer output, plus bound and member_count */
    private function render(UserGroup $group): array
    {
        $transformer = new UserGroupTransformer();
        $transformer->collectMetaData(new Collection([$group]));
        $row         = $transformer->transform($group);
        unset($row['links']);
        $row['bound']        = (int) $group->id === (int) $this->administration()->id;
        $row['member_count'] = GroupMembership::query()->where('user_group_id', $group->id)->distinct()->count('user_id');

        return $row;
    }

    /**
     * How many stored amounts Firefly recalculates when the primary currency changes: every
     * transaction of the administration, when primary-currency conversion (and exchange rates)
     * are enabled; none otherwise (the listener does nothing then).
     */
    private function convertedAmountCount(UserGroup $group): int
    {
        if (!Amount::convertToPrimary() || false === (bool) AppConfiguration::get('enable_exchange_rates', config('cer.enabled'))->data) {
            return 0;
        }

        return DB::table('transactions')
            ->join('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
            ->where('transaction_journals.user_group_id', $group->id)
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_journals.deleted_at')
            ->count()
        ;
    }
}
