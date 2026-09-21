<?php

/*
 * ReferenceReviewTest.php
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

namespace Tests\Machine\Reference;

use FireflyIII\Models\Account;
use FireflyIII\Models\CurrencyExchangeRate;
use FireflyIII\Models\LinkType;
use FireflyIII\Models\Role;
use FireflyIII\Models\Tag;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Singleton\PreferencesSingleton;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;
use Tests\Machine\Rules\SeedsLedger;

/**
 * Regressions from the adversarial review of the reference family (apis.mdx §8.8, §8.10):
 * path segments are decoded once, a numeric tag name is never a silent id lookup, link-type
 * writes keep upstream's owner gate, and an exchange-rate write that recalculates converted
 * amounts is not offered to /undo as reversible.
 *
 * @internal
 *
 * @coversNothing
 */
final class ReferenceReviewTest extends MachineTestCase
{
    use SeedsLedger;

    private User $user;
    private Account $checking;
    private TransactionGroup $coffee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = $this->operatorUser();
        $this->primaryUsd($this->user);
        $this->checking = $this->asset($this->user);
        $this->coffee   = $this->withdrawal($this->user, $this->checking, 'Morning coffee', '4.50', '2026-08-03', 'Bean Cart');
    }

    private function tag(string $name): Tag
    {
        return Tag::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'tag' => $name, 'tag_mode' => 'nothing']);
    }

    /** Laravel hands the controller an already-decoded segment: decoding it again turns "+" into a space and breaks "%". */
    public function testTagNamesWithPlusAndPercentResolveExactly(): void
    {
        $plus    = $this->tag('C++');
        $percent = $this->tag('50%off');
        $this->tag('C  '); // what a second urldecode() of "C++" would produce — it must NOT be picked

        $env = $this->envelope($this->machine('GET', '/tags/'.rawurlencode('C++')));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame($plus->id, (int) $env['data']['tag']['id']);
        $this->assertSame('C++', $env['data']['tag']['tag']);

        $env = $this->envelope($this->machine('GET', '/tags/'.rawurlencode('50%off')));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame($percent->id, (int) $env['data']['tag']['id']);

        $this->enableWrites();
        $plan = $this->envelope($this->machine('PUT', '/tags/'.rawurlencode('C++'), ['description' => 'the language']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame('C++', $plan['data']['tag']['tag']);
    }

    /** Tags are often years ("2025"): a numeric segment must not be read as an id when a tag carries that name. */
    public function testANumericTagNameIsNeverASilentIdLookup(): void
    {
        $alpha = $this->tag('alpha');            // id 1
        $one   = $this->tag((string) $alpha->id); // a tag literally named "1"
        $year  = $this->tag('2025');

        // both an id and a name match, and they differ: ambiguity is an error carrying the candidates (§14.4)
        $err = $this->assertPlaneError($this->machine('GET', '/tags/'.$alpha->id), 400, 'invalid_input');
        $ids = array_map(static fn (array $c): int => (int) $c['id'], $err['error']['details']['candidates']);
        sort($ids);
        $this->assertSame([$alpha->id, $one->id], $ids);

        // only a name matches: the tag named "2025", never "no tag with id 2025"
        $env = $this->envelope($this->machine('GET', '/tags/2025'));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame($year->id, (int) $env['data']['tag']['id']);

        // only an id matches: the id
        $env = $this->envelope($this->machine('GET', '/tags/'.$year->id));
        $this->assertSame($year->id, (int) $env['data']['tag']['id']);

        // neither: not_found (and a DELETE of it is deleted: 0)
        $this->assertPlaneError($this->machine('GET', '/tags/99999'), 404, 'not_found');
        $this->enableWrites();
        $this->enableAdmin();
        $this->assertSame(0, $this->envelope($this->machine('DELETE', '/tags/99999'))['data']['deleted']);
    }

    /** Upstream mounts link-type writes behind its owner gate (routes/api.php "api-admin"): the plane keeps it. */
    public function testLinkTypeWritesNeedTheInstallOwner(): void
    {
        $this->enableWrites();
        $this->enableAdmin();
        $body    = ['name' => 'Reimburses', 'inward' => 'is reimbursed by', 'outward' => 'reimburses'];
        $builtIn = LinkType::query()->where('editable', true)->first() ?? LinkType::query()->firstOrFail();

        $this->assertFalse($this->user->hasRole('owner'), 'the fixture operator is not the install owner');
        $err = $this->assertPlaneError($this->machine('POST', '/link-types', $body), 403, 'forbidden');
        $this->assertStringContainsString('owner', (string) $err['error']['hint']);
        $this->assertPlaneError($this->machine('PUT', '/link-types/'.$builtIn->id, ['outward' => 'x']), 403, 'forbidden');
        $this->assertPlaneError($this->machine('DELETE', '/link-types/'.$builtIn->id), 403, 'forbidden');
        $this->assertSame(0, LinkType::query()->where('name', 'Reimburses')->count());

        $this->user->roles()->attach(Role::query()->where('name', 'owner')->firstOrFail()->id);
        $plan  = $this->envelope($this->machine('POST', '/link-types', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $apply = $this->envelope($this->machine('POST', '/link-types', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(1, LinkType::query()->where('name', 'Reimburses')->count());
    }

    /**
     * With "convert to primary" on, Firefly's exchange-rate listener recalculates every converted
     * amount in the books — rows the operation log does not trace. Undo must refuse, and the
     * response must say how to reverse it (re-post the previous rate).
     */
    public function testAnExchangeRateWriteThatRecalculatesTheBooksIsNotUndoable(): void
    {
        $this->enableWrites();
        $body = ['from' => 'EUR', 'to' => 'USD', 'date' => '2026-09-01', 'rate' => '1.0842'];

        // conversion off: the rate row is the only change, and undo restores it
        $plan  = $this->envelope($this->machine('POST', '/exchange-rates', $body));
        $apply = $this->envelope($this->machine('POST', '/exchange-rates', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertTrue($apply['data']['reversible']);
        $this->assertNull($apply['data']['previous_rate']);
        $last  = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertTrue($last['data']['reversible']);

        // conversion on: the listener touches pc_* amounts the log cannot restore
        Preferences::setForUser($this->user, 'convert_to_primary', true);
        AppConfiguration::set('enable_exchange_rates', true);
        PreferencesSingleton::getInstance()->resetPreferences(); // per-request in production; per-process here

        $plan  = $this->envelope($this->machine('POST', '/exchange-rates', ['rate' => '1.09'] + $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['updated' => 1], $plan['data']['changes']);
        $this->assertFalse($plan['data']['reversible']);
        $this->assertSame('1.0842', $plan['data']['previous_rate']);
        $this->assertSame('1.0842', CurrencyExchangeRate::query()->firstOrFail()->rate, 'the dry run wrote nothing');
        $apply = $this->envelope($this->machine('POST', '/exchange-rates', ['rate' => '1.09', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']] + $body));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertFalse($apply['data']['reversible']);
        $this->assertStringContainsString('1.0842', (string) $apply['data']['undo_note']);
        $last  = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertSame($apply['data']['operation_id'], $last['data']['operation_id']);
        $this->assertFalse($last['data']['reversible'], 'a rate change that recalculated the books must not be offered as undoable');
        $this->assertNotEmpty($last['data']['blocked_by']);
        $this->assertNull($last['data']['confirm_token']);
        $this->assertSame('1.09', rtrim(rtrim(CurrencyExchangeRate::query()->firstOrFail()->rate, '0'), '.'));
    }

    /** GET /tags/{tag} counts the tag's journals without loading them. */
    public function testTagJournalCountIsACount(): void
    {
        $tag = $this->tag('household');
        $this->coffee->transactionJournals()->first()->tags()->attach($tag->id);
        $this->withdrawal($this->user, $this->checking, 'Beans', '18.00', '2026-08-15', 'Bean Cart')->transactionJournals()->first()->tags()->attach($tag->id);

        DB::enableQueryLog();
        $env = $this->envelope($this->machine('GET', '/tags/household'));
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(2, $env['data']['journals']);
        $wide = array_filter($log, static fn (array $q): bool => str_contains($q['query'], 'select "transaction_journals".*') && str_contains($q['query'], 'tag_transaction_journal') && !str_contains($q['query'], 'limit 1'));
        $this->assertSame([], array_values($wide), 'counting a tag\'s journals must not load them: '.json_encode(array_column($wide, 'query')));
    }
}
