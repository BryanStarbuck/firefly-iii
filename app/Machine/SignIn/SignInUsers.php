<?php

/*
 * SignInUsers.php
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

namespace FireflyIII\Machine\SignIn;

use FireflyIII\Events\Security\System\NewUserRegistered;
use FireflyIII\Machine\MachineException;
use FireflyIII\Models\Role;
use FireflyIII\Notifications\Notifiables\OwnerNotifiable;
use FireflyIII\Repositories\User\UserRepositoryInterface;
use FireflyIII\User;
use SensitiveParameter;

/**
 * Sign-in accounts — pm/accounts.mdx §4.3, pm/apis.mdx §8.11a.
 *
 * The rows in the `users` table that log into the web UI at http://127.0.0.1:7373/login. NOT
 * ledger accounts (checking, savings, credit cards) — those are `AccountController`.
 *
 * Three things happen here and nowhere else in the plane:
 *
 *   create()       a new sign-in account, exactly as upstream's /register makes one: the bcrypt
 *                  hash, then NewUserRegistered, which is what attaches the `owner` role to the
 *                  first user, creates that user's administration and its membership, and seeds
 *                  the exchange rates. Firing the event (rather than re-implementing it) is R1:
 *                  an account made here is the SAME shape as one made in the browser.
 *   setPassword()  a new bcrypt hash on an existing account, through upstream's repository.
 *   find()         an account by numeric id or by email, case-insensitively, never guessing
 *                  between two matches.
 *
 * The plaintext password lives in a #[SensitiveParameter] argument and a local variable, is never
 * returned, never logged, never put in a WriteResult, and never reaches the audit line or the
 * error file (Redactor::SECRET_KEY already refuses any key matching /pass(word)?/).
 */
final class SignInUsers
{
    /**
     * The password rules, and the ONE reason they are these: they are upstream's own registration
     * rules (RequestInformation::validator() — `min:16`), so a password set from the terminal is
     * always a password the browser's own forms would have accepted. `max` is a bcrypt fact: the
     * algorithm reads at most 72 BYTES, so a longer password silently ignores its tail.
     *
     * `secure_password` is deliberately NOT copied: upstream never registers that rule with the
     * validator, so it is a no-op there, and a rule that does nothing does not belong in a spec.
     *
     * @var list<string>
     */
    public const array PASSWORD_RULES = ['required', 'string', 'min:16', 'max:72'];

    /** @var list<string> */
    public const array EMAIL_RULES = ['required', 'string', 'email', 'max:255'];

    /**
     * Create a sign-in account. The caller has already decided this is allowed (the first-user
     * bootstrap, or the admin tier).
     *
     * @throws MachineException conflict when the email is taken
     */
    public static function create(string $email, #[SensitiveParameter] string $password, bool $owner = false): User
    {
        $email = trim($email);
        if (null !== self::byEmail($email)) {
            throw MachineException::conflict(
                'A sign-in account with that email address already exists.',
                'Set its password instead: POST /machine/v1/admin/users/{id}/password (ffx admin set-password)',
                ['email' => $email],
            );
        }

        $user = User::create(['email' => $email, 'password' => bcrypt($password)]);

        // Upstream's registration event: owner role for the first user, the administration and its
        // membership, the exchange rates, and the two notifications (which NotificationSender
        // swallows when mail is not configured, so a mail problem can never lose the account).
        event(new NewUserRegistered(new OwnerNotifiable(), $user));

        if ($owner) {
            self::makeOwner($user);
        }

        return $user->refresh();
    }

    /** Give the account the `owner` role if it has not got it. Idempotent. */
    public static function makeOwner(User $user): void
    {
        if (self::isOwner($user)) {
            return;
        }

        /** @var UserRepositoryInterface $repository */
        $repository = app(UserRepositoryInterface::class);
        $repository->attachRole($user, 'owner');
    }

    /**
     * Replace the account's password. `$clearMfa` and `$unblock` are opt-in, because a forgotten
     * password very often travels with a lost authenticator or a self-inflicted block, and the
     * whole point of this route is that it is the way back in.
     *
     * @return array{mfa_cleared: bool, unblocked: bool}
     */
    public static function setPassword(User $user, #[SensitiveParameter] string $password, bool $clearMfa = false, bool $unblock = false): array
    {
        /** @var UserRepositoryInterface $repository */
        $repository = app(UserRepositoryInterface::class);
        $repository->changePassword($user, $password);

        $hadMfa     = '' !== (string) $user->mfa_secret;
        if ($clearMfa && $hadMfa) {
            $repository->setMFACode($user, null);
        }

        $wasBlocked = self::isBlocked($user);
        if ($unblock && $wasBlocked) {
            $repository->unblockUser($user);
        }

        return ['mfa_cleared' => $clearMfa && $hadMfa, 'unblocked' => $unblock && $wasBlocked];
    }

    /**
     * An account by numeric id, or by email (exact, then case-insensitively). Two case-insensitive
     * matches are an error, never a guess — §14.4.
     *
     * @throws MachineException not_found, or invalid when the email is ambiguous
     */
    public static function require(string $idOrEmail): User
    {
        $value = trim($idOrEmail);
        if (1 === preg_match('/^\d+$/', $value)) {
            /** @var null|User $user */
            $user = User::query()->find((int) $value);
            if (null === $user) {
                throw MachineException::notFound(
                    sprintf('There is no sign-in account with id %d.', (int) $value),
                    'List them: GET /machine/v1/admin/users (ffx admin users)',
                    ['id' => (int) $value],
                );
            }

            return $user;
        }

        $exact   = self::byEmail($value);
        if (null !== $exact) {
            return $exact;
        }
        $matches = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($value)])->orderBy('id')->get();
        if (1 === $matches->count()) {
            /** @var User $user */
            $user = $matches->first();

            return $user;
        }
        if ($matches->count() > 1) {
            throw MachineException::invalid(
                sprintf('%d sign-in accounts differ only in the case of "%s".', $matches->count(), $value),
                'Pass the account\'s numeric id instead — they are in details.candidates',
                ['candidates' => $matches->map(static fn (User $u): array => ['id' => (int) $u->id, 'email' => (string) $u->email])->values()->all()],
            );
        }

        throw MachineException::notFound(
            'There is no sign-in account with that email address.',
            'List them: GET /machine/v1/admin/users (ffx admin users) — or create one with POST /machine/v1/admin/users',
            ['email' => $value],
        );
    }

    /** Exactly what it says, with no side effects. */
    public static function byEmail(string $email): ?User
    {
        /** @var null|User $user */
        $user = User::query()->where('email', trim($email))->first();

        return $user;
    }

    public static function count(): int
    {
        return User::query()->count();
    }

    public static function isOwner(User $user): bool
    {
        return $user->roles()->where('name', 'owner')->exists();
    }

    public static function isBlocked(User $user): bool
    {
        return true === (bool) $user->blocked || '' !== (string) $user->blocked_code;
    }

    /**
     * How an account is published. The hash, the MFA secret and every backup code stay out (§16.2);
     * `has_mfa` is a boolean, never the secret.
     *
     * @return array<string, mixed>
     */
    public static function describe(User $user): array
    {
        $user->refresh();
        $group = $user->userGroup;

        return [
            'id'                   => (int) $user->id,
            'email'                => (string) $user->email,
            'is_owner'             => self::isOwner($user),
            'blocked'              => self::isBlocked($user),
            'blocked_code'         => '' === (string) $user->blocked_code ? null : (string) $user->blocked_code,
            'has_mfa'              => '' !== (string) $user->mfa_secret,
            'administration_id'    => null === $user->user_group_id ? null : (int) $user->user_group_id,
            'administration_title' => null === $group ? null : (string) $group->title,
            'created_at'           => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * What the caller has to do next for the CLI and the MCP to act AS this account: the plane
     * binds one operator (apis.mdx §4.9), and that binding is configuration, not a consequence of
     * creating an account. Returning null means nothing needs doing.
     */
    public static function operatorAdvice(User $user): ?string
    {
        $configured = config('machine.operator');
        $configured = is_string($configured) ? trim($configured) : '';
        $email      = (string) $user->email;

        if ('' === $configured) {
            return 1 === self::count()
                ? null // sole user: the plane binds it without being told (§4.9)
                : sprintf('FIREFLY_MACHINE_OPERATOR is unset and this install now has %d sign-in accounts, so the plane can no longer pick one. Set FIREFLY_MACHINE_OPERATOR=%s in the app\'s .env and restart it (ffx stop && ffx up).', self::count(), $email);
        }
        if (0 === strcasecmp($configured, $email)) {
            return null;
        }

        return sprintf('FIREFLY_MACHINE_OPERATOR is "%s", not "%s", so ffx and the MCP still act as that other account. Change it in the app\'s .env and restart the app if you meant to switch.', $configured, $email);
    }

    /** Whether the `owner` role exists at all — it does not on a database that was never seeded. */
    public static function ownerRoleExists(): bool
    {
        return Role::query()->where('name', 'owner')->exists();
    }
}
