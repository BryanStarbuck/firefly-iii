<?php

/*
 * Operator.php
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

namespace FireflyIII\Machine;

use FireflyIII\Machine\ErrorFile\ErrorFile;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The operator binding — apis.mdx §4.9. The machine key names neither a user nor a set of books;
 * gate 3 binds every request to exactly one of each, from configuration, and NEVER guesses:
 *
 *   FIREFLY_MACHINE_OPERATOR (email)       that user; unset + exactly one user → that one;
 *                                          otherwise not_ready naming the candidate emails.
 *   FIREFLY_MACHINE_ADMINISTRATION (id)    that administration, if the operator is a member;
 *                                          unset → the operator's current one (user_group_id).
 *
 * Binding is auth()->setUser() on the default guard and on the api guard, for this request only:
 * no session is written and no token is minted. 2FA does not apply — the plane has no login.
 */
final class Operator
{
    public const string ATTR_USER   = 'machine.operator';
    public const string ATTR_GROUP  = 'machine.administration';
    public const string ATTR_REFUSE = 'machine.operator_refusal';

    /**
     * @return array{0: User, 1: UserGroup}
     *
     * @throws MachineException not_ready (no/ambiguous user, not a member), forbidden (blocked)
     */
    public static function resolve(): array
    {
        $user  = self::resolveUser();
        $group = self::resolveAdministration($user);

        return [$user, $group];
    }

    /** Resolve and bind for this request; remember the binding on the request. */
    public static function bind(Request $request): void
    {
        [$user, $group] = self::resolve();
        $request->attributes->set(self::ATTR_USER, $user);
        $request->attributes->set(self::ATTR_GROUP, $group);
        Auth::setUser($user);
        foreach (['web', 'api'] as $guard) {
            if (null !== config(sprintf('auth.guards.%s', $guard))) {
                Auth::guard($guard)->setUser($user);
            }
        }
    }

    /**
     * Try to bind; on failure remember the refusal (for /whoami and /health, which answer
     * without an operator) instead of throwing.
     */
    public static function tryBind(Request $request): ?MachineException
    {
        try {
            self::bind($request);

            return null;
        } catch (MachineException $e) {
            ErrorFile::for('app/Machine/Operator.php')->expected('binding the operator', $e);
            $request->attributes->set(self::ATTR_REFUSE, $e);

            return $e;
        }
    }

    public static function user(Request $request): ?User
    {
        $user = $request->attributes->get(self::ATTR_USER);

        return $user instanceof User ? $user : null;
    }

    public static function administration(Request $request): ?UserGroup
    {
        $group = $request->attributes->get(self::ATTR_GROUP);

        return $group instanceof UserGroup ? $group : null;
    }

    /**
     * The administration's primary currency, read WITHOUT Firefly's side effect of assigning a
     * default when none is set (reads never mutate, R8).
     */
    public static function primaryCurrency(UserGroup $group): ?TransactionCurrency
    {
        /** @var null|TransactionCurrency $currency */
        $currency = $group->currencies()->where('group_default', true)->first();
        if (null !== $currency) {
            return $currency;
        }

        /** @var null|TransactionCurrency $system */
        $system = TransactionCurrency::query()->whereNull('deleted_at')->where('code', 'EUR')->first();

        return $system;
    }

    /** @return list<string> every user's email, sorted — for refusals and /health. */
    public static function candidates(): array
    {
        $emails = User::query()->orderBy('email')->pluck('email')->map(static fn ($e): string => (string) $e)->all();

        return array_values($emails);
    }

    private static function resolveUser(): User
    {
        $configured = config('machine.operator');
        $configured = is_string($configured) ? trim($configured) : '';

        if ('' !== $configured) {
            /** @var null|User $user */
            $user = User::query()->where('email', $configured)->first();
            if (null === $user) {
                $matches = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($configured)])->get();
                $user    = 1 === $matches->count() ? $matches->first() : null;
            }
            if (null === $user) {
                throw MachineException::notReady(
                    'FIREFLY_MACHINE_OPERATOR names a user that does not exist.',
                    'Set FIREFLY_MACHINE_OPERATOR in the app\'s .env to one of the emails in details.candidates, then restart the app',
                    ['configured' => $configured, 'candidates' => self::candidates()],
                );
            }

            return self::refuseBlocked($user);
        }

        $count = User::query()->count();
        if (0 === $count) {
            throw MachineException::notReady(
                'This Firefly III install has no users yet.',
                'Create it from the terminal — `ffx admin create-first-user --email you@example.com` (POST /machine/v1/admin/first-user) — or register it in the web UI, then retry',
                ['users' => 0, 'candidates' => []],
            );
        }
        if ($count > 1) {
            throw MachineException::notReady(
                sprintf('%d users exist and FIREFLY_MACHINE_OPERATOR is unset — the plane never guesses which one.', $count),
                'Set FIREFLY_MACHINE_OPERATOR in the app\'s .env to one of the emails in details.candidates, then restart the app',
                ['users' => $count, 'candidates' => self::candidates()],
            );
        }

        /** @var User $user */
        $user = User::query()->first();

        return self::refuseBlocked($user);
    }

    private static function refuseBlocked(User $user): User
    {
        if (true === (bool) $user->blocked || '' !== (string) $user->blocked_code) {
            throw MachineException::forbidden(
                'The operator user is blocked in Firefly III.',
                'Unblock the user in Firefly III (System → Users), or set FIREFLY_MACHINE_OPERATOR to another user',
                ['operator' => (string) $user->email],
            );
        }

        return $user;
    }

    private static function resolveAdministration(User $user): UserGroup
    {
        $configured = config('machine.administration');
        $configured = is_scalar($configured) ? trim((string) $configured) : '';
        $memberOf   = GroupMembership::query()->where('user_id', $user->id)->pluck('user_group_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();

        if ('' !== $configured) {
            if (1 !== preg_match('/^\d+$/', $configured)) {
                throw MachineException::notReady(
                    'FIREFLY_MACHINE_ADMINISTRATION must be a numeric administration id.',
                    'Set FIREFLY_MACHINE_ADMINISTRATION to one of the ids in details.candidates, or unset it',
                    ['configured' => $configured, 'candidates' => self::administrationCandidates($memberOf)],
                );
            }
            $id    = (int) $configured;
            /** @var null|UserGroup $group */
            $group = UserGroup::query()->find($id);
            if (null === $group || !in_array($id, $memberOf, true)) {
                throw MachineException::notReady(
                    sprintf('The operator is not a member of administration #%d.', $id),
                    'Set FIREFLY_MACHINE_ADMINISTRATION to one of the ids in details.candidates, or unset it to use the operator\'s current administration',
                    ['configured' => $id, 'operator' => (string) $user->email, 'candidates' => self::administrationCandidates($memberOf)],
                );
            }

            return $group;
        }

        $current = (int) $user->user_group_id;
        /** @var null|UserGroup $group */
        $group   = 0 === $current ? null : UserGroup::query()->find($current);
        if (null === $group) {
            throw MachineException::notReady(
                'The operator has no current administration.',
                'Log in to the web UI once (Firefly creates it), or set FIREFLY_MACHINE_ADMINISTRATION to one of the ids in details.candidates',
                ['operator' => (string) $user->email, 'candidates' => self::administrationCandidates($memberOf)],
            );
        }

        return $group;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array{id: int, title: string}>
     */
    private static function administrationCandidates(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return UserGroup::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'title'])
            ->map(static fn (UserGroup $g): array => ['id' => (int) $g->id, 'title' => (string) $g->title])
            ->values()->all()
        ;
    }
}
