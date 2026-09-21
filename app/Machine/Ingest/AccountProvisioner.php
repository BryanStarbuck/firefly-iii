<?php

/*
 * AccountProvisioner.php
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

use Carbon\Carbon;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;

/**
 * Account provisioning from a manifest — apis.mdx §12.
 *
 * plan(): per manifest row, one of
 *   skip       already in the map, and the account still exists
 *   link       an existing account matched — on the proposed name, else on IBAN/number last-4
 *   create     nothing matched; the proposed account is shown in full
 *   ambiguous  several candidates (two entities sharing a last-4 is the NORMAL case), or two rows
 *              would get the same name — every candidate is returned and the row is blocked until
 *              the caller resolves it (PUT /ingest/map). Ambiguous NEVER becomes a create.
 *
 * apply(): the `create` rows through Firefly's own AccountRepository::store() (its factory, its
 * opening-balance transactions), the `link` rows into the map, then the map is written beside the
 * statements so the very next /ingest/plan has a complete mapping.
 *
 * Kinds (§12.3): checking → asset/defaultAsset; savings → asset/savingAsset; card → asset/ccAsset
 * (Firefly's credit-card model); brokerage → asset/sharedAsset, include_net_worth; loan and
 * mortgage → liabilities the operator owes (liability_direction "debit" — Firefly's "I owe this
 * debt"). `liability_kinds[]` moves any kind to the liability side; a manifest `firefly_type`
 * column overrides one row.
 */
final class AccountProvisioner
{
    public const string DEFAULT_NAMING = '{Entity} · {Institution} {Kind} ••{last4}';

    private const array ROLES = ['checking' => 'defaultAsset', 'savings' => 'savingAsset', 'card' => 'ccAsset', 'brokerage' => 'sharedAsset'];
    private const array KIND_LABEL = ['checking' => 'Checking', 'savings' => 'Savings', 'card' => 'Card', 'brokerage' => 'Brokerage', 'loan' => 'Loan', 'mortgage' => 'Mortgage'];
    private const array UPPER = ['llc' => 'LLC', 'inc' => 'Inc', 'ltd' => 'Ltd', 'lp' => 'LP', 'llp' => 'LLP', 'plc' => 'PLC', 'pc' => 'PC', 'co' => 'Co', 'na' => 'NA', 'fsb' => 'FSB', 'usa' => 'USA', 'us' => 'US'];

    /**
     * @param list<array<string, mixed>>          $manifestAccounts
     * @param array<string, array<string, mixed>> $map
     * @param list<string>                        $liabilityKinds
     *
     * @return array{plan: list<array<string, mixed>>, summary: array<string, int>}
     */
    public static function plan(array $manifestAccounts, LedgerAccounts $ledger, array $map, string $naming, array $liabilityKinds): array
    {
        $plan    = [];
        $claimed = [];
        foreach ($map as $entry) {
            if (null !== $ledger->find((int) $entry['account_id'])) {
                $claimed[(int) $entry['account_id']] = true;
            }
        }
        foreach ($manifestAccounts as $m) {
            if (true === ($m['ignored'] ?? false)) {
                continue;
            }
            $manifest = ['entity' => $m['entity'], 'institution' => $m['institution'], 'label' => $m['label'], 'last4' => $m['last4'], 'kind' => $m['kind']];
            $row      = ['key' => $m['key'], 'manifest' => $manifest];
            $mapped   = $map[$m['key']] ?? null;
            if (null !== $mapped && null !== ($existing = $ledger->find((int) $mapped['account_id']))) {
                $plan[] = $row + ['action' => 'skip', 'existing' => LedgerAccounts::brief($existing), 'reason' => sprintf('already mapped to #%d', $existing['id'])];

                continue;
            }
            if (null === $m['kind']) {
                $plan[] = $row + ['action' => 'ambiguous', 'candidates' => [], 'reason' => 'the manifest row has no usable kind — fix the manifest, or map it by hand with PUT /ingest/map'];

                continue;
            }
            $proposed = self::proposed($m, $naming, $liabilityKinds, $ledger->primary());
            $byName   = array_values(array_filter($ledger->byName($proposed['name']), static fn (array $r): bool => !isset($claimed[$r['id']])));
            if (1 === count($byName)) {
                $plan[] = $row + ['action' => 'link', 'existing' => LedgerAccounts::brief($byName[0]), 'proposed' => $proposed, 'reason' => sprintf('matched on name "%s"', $byName[0]['name'])];

                continue;
            }
            $byLast4  = null === $m['last4'] ? [] : array_values(array_filter($ledger->byLast4((string) $m['last4']), static fn (array $r): bool => !isset($claimed[$r['id']])));
            if (1 === count($byLast4)) {
                $plan[] = $row + ['action' => 'link', 'existing' => LedgerAccounts::brief($byLast4[0]), 'proposed' => $proposed, 'reason' => sprintf('matched on IBAN/number last-4 %s', $m['last4'])];

                continue;
            }
            if (count($byLast4) > 1 || count($byName) > 1) {
                $cands  = array_map(LedgerAccounts::brief(...), count($byLast4) > 1 ? $byLast4 : $byName);
                $plan[] = $row + ['action' => 'ambiguous', 'candidates' => $cands, 'proposed' => $proposed, 'reason' => sprintf('%d existing accounts match (%s) — pick one with PUT /ingest/map', count($cands), count($byLast4) > 1 ? 'last-4 '.$m['last4'] : 'name')];

                continue;
            }
            $plan[]   = $row + ['action' => 'create', 'proposed' => $proposed, 'reason' => self::createReason($m, $proposed)];
        }
        // the same existing account linked twice, or two creates with the same name: ambiguous
        $links    = [];
        $names    = [];
        foreach ($plan as $i => $p) {
            if ('link' === $p['action']) {
                $links[$p['existing']['id']][] = $i;
            }
            if ('create' === $p['action']) {
                $names[mb_strtolower($p['proposed']['name'])][] = $i;
            }
        }
        foreach ([$links, $names] as $groups) {
            foreach ($groups as $indexes) {
                if (count($indexes) < 2) {
                    continue;
                }
                foreach ($indexes as $i) {
                    $plan[$i]['action']     = 'ambiguous';
                    $plan[$i]['candidates'] = isset($plan[$i]['existing']) ? [$plan[$i]['existing']] : [];
                    $plan[$i]['reason']     = sprintf('%d manifest rows resolve to the same %s — map them by hand (PUT /ingest/map) or pass a naming template with {label}', count($indexes), isset($plan[$i]['existing']) ? 'existing account' : 'new name');
                    unset($plan[$i]['existing']);
                }
            }
        }
        $summary  = ['create' => 0, 'link' => 0, 'skip' => 0, 'ambiguous' => 0];
        foreach ($plan as $p) {
            ++$summary[$p['action']];
        }

        return ['plan' => $plan, 'summary' => $summary];
    }

    /**
     * Create the `create` rows and collect the map entries of `create` and `link` rows. Only on a
     * real run ($dryRun false) is the map file written.
     *
     * @param list<array<string, mixed>>          $plan
     * @param list<array<string, mixed>>          $manifestAccounts
     * @param array<string, array<string, mixed>> $map
     */
    public static function apply(array $plan, array $manifestAccounts, LedgerAccounts $ledger, array $map, Staging $staging, bool $dryRun, string $basis): WriteResult
    {
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($ledger->user());
        $byKey      = [];
        foreach ($manifestAccounts as $m) {
            $byKey[$m['key']] = $m;
        }
        $result     = new WriteResult();
        $created    = [];
        $rows       = [];
        foreach ($plan as $p) {
            $m = $byKey[$p['key']] ?? null;
            if ('create' === $p['action'] && null !== $m) {
                $account         = $repository->store(self::storeData($p['proposed'], $m));
                $result->created($account);
                $result->count('created');
                $map[$p['key']]  = self::mapEntry($m, (int) $account->id, (string) $account->name, 'accounts/apply:create');
                $created[]       = ['id' => (int) $account->id, 'name' => (string) $account->name, 'manifest' => $p['manifest']];
                $p['account_id'] = (int) $account->id;
            }
            if ('link' === $p['action'] && null !== $m) {
                $result->count('linked');
                $map[$p['key']]  = self::mapEntry($m, (int) $p['existing']['id'], (string) $p['existing']['name'], 'accounts/apply:link');
                $p['account_id'] = (int) $p['existing']['id'];
            }
            if ('ambiguous' === $p['action']) {
                $result->count('blocked');
            }
            if ('skip' === $p['action']) {
                $result->count('unchanged');
            }
            $rows[] = $p;
        }
        if (!$dryRun && ($result->changes['created'] ?? 0) + ($result->changes['linked'] ?? 0) > 0) {
            MapFile::write($staging, $map);
        }
        $result->changeCount = ($result->changes['created'] ?? 0) + ($result->changes['linked'] ?? 0);
        $result->basis       = ['plan' => $basis];

        return $result->with(['plan' => $rows, 'created' => $created, 'map_path' => Staging::DIR.'/'.MapFile::FILE]);
    }

    /**
     * @param array<string, mixed> $m
     *
     * @return array<string, mixed>
     */
    public static function mapEntry(array $m, int $accountId, string $name, string $via): array
    {
        return [
            'account_id'   => $accountId,
            'account_name' => $name,
            'entity'       => $m['entity'],
            'institution'  => $m['institution'],
            'label'        => $m['label'],
            'last4'        => $m['last4'],
            'via'          => $via,
        ];
    }

    /**
     * "{Entity} · {Institution} {Kind} ••{last4}" — {entity} {institution} {label} {kind} {last4}
     * are the raw values, the capitalised forms are title-cased ("acme_llc" → "Acme LLC"). A
     * missing last-4 drops its "••".
     *
     * @param array<string, mixed> $m
     */
    public static function name(array $m, string $naming): string
    {
        $last4 = (string) ($m['last4'] ?? '');
        $kind  = (string) ($m['kind'] ?? '');
        $vars  = [
            '{Entity}'      => self::title((string) $m['entity']),
            '{entity}'      => (string) $m['entity'],
            '{Institution}' => self::title((string) $m['institution']),
            '{institution}' => (string) $m['institution'],
            '{Label}'       => self::title((string) $m['label']),
            '{label}'       => (string) $m['label'],
            '{Kind}'        => self::KIND_LABEL[$kind] ?? self::title($kind),
            '{kind}'        => $kind,
            '{last4}'       => $last4,
        ];
        $tpl   = $naming;
        if ('' === $last4) {
            $tpl = (string) preg_replace('/\s*(?:••|x|#|\*+)?\{last4\}/u', '', $tpl);
        }
        $name  = strtr($tpl, $vars);
        $name  = (string) preg_replace(['/\s{2,}/u', '/^[\s·\-]+|[\s·\-]+$/u'], [' ', ''], $name);

        return mb_substr('' === $name ? (string) $m['label'] : $name, 0, 255);
    }

    public static function title(string $value): string
    {
        $words = preg_split('/[\s_]+/', trim($value)) ?: [];
        $out   = [];
        foreach ($words as $w) {
            if ('' === $w) {
                continue;
            }
            $lower = mb_strtolower($w);
            $out[] = self::UPPER[$lower] ?? (preg_match('/[A-Z]/', substr($w, 1)) ? $w : mb_strtoupper(mb_substr($w, 0, 1)).mb_substr($w, 1));
        }

        return implode(' ', $out);
    }

    /**
     * @param array<string, mixed> $m
     * @param list<string>         $liabilityKinds
     *
     * @return array<string, mixed>
     */
    private static function proposed(array $m, string $naming, array $liabilityKinds, TransactionCurrency $primary): array
    {
        $kind      = (string) $m['kind'];
        $override  = $m['firefly_type'] ?? null;
        $liability = 'liability' === $override || ('asset' !== $override && in_array($kind, $liabilityKinds, true));
        $name      = null !== ($m['name'] ?? null) ? mb_substr((string) $m['name'], 0, 255) : self::name($m, $naming);
        $currency  = (string) ($m['currency'] ?? $primary->code);
        if ($liability) {
            $type = in_array($kind, ['loan', 'mortgage'], true) ? $kind : 'debt';

            return [
                'name'                => $name,
                'type'                => 'liability',
                'liability_type'      => $type,
                'liability_direction' => 'debit',
                'currency_code'       => $currency,
                'include_net_worth'   => true,
            ];
        }

        return array_filter([
            'name'                 => $name,
            'type'                 => 'asset',
            'account_role'         => self::ROLES[$kind] ?? 'defaultAsset',
            'currency_code'        => $currency,
            'include_net_worth'    => true,
            'credit_card_type'     => 'card' === $kind ? 'monthlyFull' : null,
            'monthly_payment_date' => 'card' === $kind ? self::paymentDate($m) : null,
        ], static fn ($v): bool => null !== $v);
    }

    /** @param array<string, mixed> $m */
    private static function paymentDate(array $m): string
    {
        $given = Values::date((string) ($m['payment_date'] ?? ''));

        return $given ?? '2000-01-01';
    }

    /**
     * @param array<string, mixed> $m
     * @param array<string, mixed> $proposed
     */
    private static function createReason(array $m, array $proposed): string
    {
        $why = null === $m['last4'] ? 'no existing account matched on name' : 'no existing account matched on IBAN/number last-4 or name';
        if ('liability' === $proposed['type']) {
            $why .= sprintf('; kind=%s → liability (%s) by the liability_kinds rule%s', (string) $m['kind'], $proposed['liability_type'], 'liability' === ($m['firefly_type'] ?? null) ? ' (manifest firefly_type override)' : '');
        }
        if ('brokerage' === $m['kind'] && 'asset' === $proposed['type']) {
            $why .= '; brokerage: market movement is not income — keep it out of budgets with a rule';
        }
        if ('card' === $m['kind'] && 'asset' === $proposed['type'] && null === Values::date((string) ($m['payment_date'] ?? ''))) {
            $why .= '; card: no payment_date in the manifest — set the monthly payment date in Firefly afterwards';
        }
        if (true === ($m['currency_defaulted'] ?? false)) {
            $why .= sprintf('; currency defaulted to the primary currency %s', (string) $proposed['currency_code']);
        }

        return $why;
    }

    /**
     * The data Firefly's own API hands AccountRepository::store() (Api\V1\Requests\Models\Account\StoreRequest::getAllAccountData()).
     *
     * @param array<string, mixed> $proposed
     * @param array<string, mixed> $m
     *
     * @return array<string, mixed>
     */
    private static function storeData(array $proposed, array $m): array
    {
        $currency = TransactionCurrency::where('code', $proposed['currency_code'])->first();
        if (null === $currency) {
            throw MachineException::invalid(sprintf('Unknown currency %s for %s.', (string) $proposed['currency_code'], (string) $m['key']), 'Fix the manifest\'s currency column (an ISO code Firefly knows), or enable the currency in Firefly');
        }
        $opening  = null;
        $openDate = null;
        if (null !== ($m['opening_balance'] ?? null) && null !== ($m['opening_balance_date'] ?? null)) {
            $amount = Values::amount((string) $m['opening_balance']);
            $date   = Values::date((string) $m['opening_balance_date']);
            if (null !== $amount && null !== $date && '0' !== $amount) {
                $opening  = Money::normalize($amount, (int) $currency->decimal_places, 'opening_balance', (string) $currency->code);
                $openDate = Carbon::createFromFormat('!Y-m-d', $date, (string) config('app.timezone'));
            }
        }
        $data     = [
            'name'                    => $proposed['name'],
            'active'                  => true,
            'include_net_worth'       => (bool) $proposed['include_net_worth'],
            'account_type_name'       => 'liability' === $proposed['type'] ? $proposed['liability_type'] : 'asset',
            'account_type_id'         => null,
            'currency_id'             => (int) $currency->id,
            'currency_code'           => (string) $currency->code,
            'virtual_balance'         => null,
            'iban'                    => null,
            'BIC'                     => null,
            'account_number'          => $m['last4'],
            'account_role'            => $proposed['account_role'] ?? null,
            'opening_balance'         => $opening,
            'opening_balance_date'    => $openDate,
            'cc_type'                 => $proposed['credit_card_type'] ?? null,
            'cc_monthly_payment_date' => $proposed['monthly_payment_date'] ?? null,
            'interest'                => null,
            'interest_period'         => null,
        ];
        if ('liability' === $proposed['type']) {
            $data['liability_direction'] = $proposed['liability_direction'];
        }

        return $data;
    }
}
