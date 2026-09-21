<?php

/*
 * ReferenceRoutesTest.php
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

use Carbon\Carbon;
use FireflyIII\Machine\Undo\OperationLog;
use FireflyIII\Models\Account;
use FireflyIII\Models\Attachment;
use FireflyIII\Models\CurrencyExchangeRate;
use FireflyIII\Models\LinkType;
use FireflyIII\Models\ObjectGroup;
use FireflyIII\Models\Preference;
use FireflyIII\Models\Tag;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\Webhook;
use FireflyIII\Models\WebhookMessage;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Machine\MachineTestCase;
use Tests\Machine\Rules\SeedsLedger;

/**
 * pm/apis.mdx §8.8 and §8.10 — tags, object groups, currencies, exchange rates, link types,
 * attachments, preferences and the read-only webhook surface, through the real HTTP stack.
 *
 * @internal
 *
 * @coversNothing
 */
final class ReferenceRoutesTest extends MachineTestCase
{
    use SeedsLedger;

    private User $user;
    private Account $checking;
    private TransactionGroup $coffee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('upload');
        $this->user     = $this->operatorUser();
        $this->primaryUsd($this->user);
        $this->checking = $this->asset($this->user);
        $this->coffee   = $this->withdrawal($this->user, $this->checking, 'Morning coffee', '4.50', '2026-08-03', 'Bean Cart');
        $this->withdrawal($this->user, $this->checking, 'Groceries run', '82.10', '2026-08-20');
    }

    // ------------------------------------------------------------------- tags ---

    public function testTagsListShowAndSums(): void
    {
        $tag = Tag::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'tag' => 'vacation-2026', 'tag_mode' => 'nothing', 'date' => Carbon::parse('2026-08-01')]);
        Tag::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'tag' => 'acme-llc', 'tag_mode' => 'nothing']);
        $journal = $this->coffee->transactionJournals()->first();
        $journal->tags()->attach($tag->id);

        $env = $this->envelope($this->machine('GET', '/tags'));
        $this->assertSame(['acme-llc', 'vacation-2026'], array_column($env['data']['tags'], 'tag'));
        $this->assertSame('2026-08-01', $env['data']['tags'][1]['date']);
        $this->assertContains('tag', $env['meta']['untrusted']);
        $search = $this->envelope($this->machine('GET', '/tags', ['search' => 'VACA']));
        $this->assertSame(['vacation-2026'], array_column($search['data']['tags'], 'tag'));

        $one = $this->envelope($this->machine('GET', '/tags/vacation-2026', ['start' => '2026-08-01', 'end' => '2026-08-31']));
        $this->assertSame('vacation-2026', $one['data']['tag']['tag']);
        $this->assertSame(['start' => '2026-08-01', 'end' => '2026-08-31'], $one['data']['range']);
        $this->assertSame(1, $one['data']['journals']);
        $this->assertCount(1, $one['data']['sums']);
        $this->assertSame('USD', $one['data']['sums'][0]['currency_code']);
        $this->assertSame('-4.50', $one['data']['sums'][0]['spent']);
        $this->assertSame('0.00', $one['data']['sums'][0]['earned']);

        $july = $this->envelope($this->machine('GET', '/tags/'.$tag->id, ['start' => '2026-07-01', 'end' => '2026-07-31']));
        $this->assertSame([], $july['data']['sums'], 'no rows is an empty list, never a row of zeros (§14.2)');

        $this->assertPlaneError($this->machine('GET', '/tags/nope'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/tags', ['serach' => 'x']), 400, 'invalid_input');
    }

    public function testTagCreateRenameDelete(): void
    {
        $this->enableWrites();
        $plan = $this->envelope($this->machine('POST', '/tags', ['tag' => 'home-office', 'date' => '2026-09-01']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['created' => 1], $plan['data']['changes']);
        $this->assertSame(0, Tag::query()->count());
        $apply = $this->envelope($this->machine('POST', '/tags', ['tag' => 'home-office', 'date' => '2026-09-01', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $tag   = Tag::query()->where('tag', 'home-office')->firstOrFail();
        $this->assertPlaneError($this->machine('POST', '/tags', ['tag' => 'home-office']), 400, 'invalid_input');

        $this->coffee->transactionJournals()->first()->tags()->attach($tag->id);
        $plan  = $this->envelope($this->machine('PUT', '/tags/home-office', ['tag' => 'home-office-2026']));
        $apply = $this->envelope($this->machine('PUT', '/tags/home-office', ['tag' => 'home-office-2026', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame('home-office-2026', $apply['data']['tag']['tag']);
        $this->assertSame(1, $apply['data']['journals'], 'a rename keeps every journal tagged');

        $this->assertPlaneError($this->machine('DELETE', '/tags/'.$tag->id), 403, 'forbidden');
        $this->enableAdmin();
        $plan  = $this->envelope($this->machine('DELETE', '/tags/'.$tag->id));
        $this->assertSame(1, $plan['data']['changes']['journals_untagged']);
        $this->assertSame(1, DB::table('tag_transaction_journal')->count(), 'the dry run unlinked nothing');
        $apply = $this->envelope($this->machine('DELETE', '/tags/'.$tag->id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertNull(Tag::query()->find($tag->id));
        $this->assertSame(0, $this->envelope($this->machine('DELETE', '/tags/'.$tag->id))['data']['deleted']);
    }

    // ---------------------------------------------------------- object groups ---

    public function testObjectGroupsListRenameAndDelete(): void
    {
        $a = ObjectGroup::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'title' => 'Savings goals', 'order' => 1]);
        $b = ObjectGroup::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'title' => 'Utilities', 'order' => 2]);

        $env = $this->envelope($this->machine('GET', '/object-groups'));
        $this->assertSame(['Savings goals', 'Utilities'], array_column($env['data']['object_groups'], 'title'));
        $this->assertSame(0, $env['data']['object_groups'][0]['piggy_bank_count']);

        $this->enableWrites();
        $plan  = $this->envelope($this->machine('PUT', '/object-groups/'.$b->id, ['order' => 1, 'title' => 'Household bills']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(1, $plan['data']['changes']['updated']);
        $apply = $this->envelope($this->machine('PUT', '/object-groups/'.$b->id, ['order' => 1, 'title' => 'Household bills', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(['Household bills', 'Savings goals'], ObjectGroup::query()->orderBy('order')->pluck('title')->all());
        $this->assertPlaneError($this->machine('PUT', '/object-groups/'.$b->id, ['title' => 'Savings goals']), 400, 'invalid_input');

        $this->enableAdmin();
        $plan  = $this->envelope($this->machine('DELETE', '/object-groups/'.$a->id));
        $apply = $this->envelope($this->machine('DELETE', '/object-groups/'.$a->id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertNull(ObjectGroup::query()->find($a->id));
    }

    // ------------------------------------------------------------- currencies ---

    public function testCurrenciesListPrimaryEnableDisable(): void
    {
        $eur = TransactionCurrency::query()->where('code', 'EUR')->firstOrFail();
        $env = $this->envelope($this->machine('GET', '/currencies', ['enabled' => 'true']));
        $this->assertSame(['USD'], array_column($env['data']['currencies'], 'code'));
        $this->assertTrue($env['data']['currencies'][0]['primary']);
        $all = $this->envelope($this->machine('GET', '/currencies'));
        $this->assertSame('USD', $all['data']['currencies'][0]['code'], 'the primary currency sorts first');
        $this->assertGreaterThan(1, count($all['data']['currencies']));
        $primary = $this->envelope($this->machine('GET', '/currencies/primary'));
        $this->assertSame('USD', $primary['data']['currency']['code']);

        $this->enableWrites();
        $plan  = $this->envelope($this->machine('POST', '/currencies/eur/enable'));
        $this->assertSame(['enabled' => 1], $plan['data']['changes']);
        $this->assertSame(0, $this->user->userGroup->currencies()->where('transaction_currencies.id', $eur->id)->count());
        $apply = $this->envelope($this->machine('POST', '/currencies/eur/enable', ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertTrue($apply['data']['currency']['enabled']);
        $this->assertSame(['unchanged' => 1], $this->envelope($this->machine('POST', '/currencies/EUR/enable'))['data']['changes']);

        $this->assertPlaneError($this->machine('POST', '/currencies/USD/disable'), 409, 'conflict');
        $plan  = $this->envelope($this->machine('POST', '/currencies/EUR/disable'));
        $apply = $this->envelope($this->machine('POST', '/currencies/EUR/disable', ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertFalse($apply['data']['currency']['enabled']);
        $this->assertPlaneError($this->machine('POST', '/currencies/XXQ/enable'), 404, 'not_found');
    }

    // --------------------------------------------------------- exchange rates ---

    public function testExchangeRatesAreStringsAndUpsertByDate(): void
    {
        $this->enableWrites();
        $this->assertPlaneError($this->machine('POST', '/exchange-rates', ['from' => 'EUR', 'to' => 'USD', 'date' => '2026-09-01', 'rate' => 1.0842]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/exchange-rates', ['from' => 'EUR', 'to' => 'USD', 'date' => '2026-09-01', 'rate' => '-1']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/exchange-rates', ['from' => 'EUR', 'to' => 'EUR', 'date' => '2026-09-01', 'rate' => '1']), 400, 'invalid_input');

        $body  = ['from' => 'EUR', 'to' => 'USD', 'date' => '2026-09-01', 'rate' => '1.0842'];
        $plan  = $this->envelope($this->machine('POST', '/exchange-rates', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['created' => 1], $plan['data']['changes']);
        $this->assertSame(0, CurrencyExchangeRate::query()->count());
        $apply = $this->envelope($this->machine('POST', '/exchange-rates', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame('1.0842', $apply['data']['exchange_rate']['rate']);
        $this->assertSame('2026-09-01', $apply['data']['exchange_rate']['date']);

        $again = $this->envelope($this->machine('POST', '/exchange-rates', ['rate' => '1.09'] + $body));
        $this->assertSame(['updated' => 1], $again['data']['changes']);

        $list  = $this->envelope($this->machine('GET', '/exchange-rates', ['from' => 'EUR', 'start' => '2026-09-01', 'end' => '2026-09-01']));
        $this->assertCount(1, $list['data']['exchange_rates']);
        $this->assertIsString($list['data']['exchange_rates'][0]['rate']);
        $this->assertSame('EUR', $list['data']['exchange_rates'][0]['from_currency_code']);
        $this->assertCount(0, $this->envelope($this->machine('GET', '/exchange-rates', ['start' => '2026-09-02']))['data']['exchange_rates']);

        $id    = $list['data']['exchange_rates'][0]['id'];
        $this->enableAdmin();
        $plan  = $this->envelope($this->machine('DELETE', '/exchange-rates/'.$id));
        $apply = $this->envelope($this->machine('DELETE', '/exchange-rates/'.$id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(0, CurrencyExchangeRate::query()->count());
        $this->assertSame(0, $this->envelope($this->machine('DELETE', '/exchange-rates/'.$id))['data']['deleted']);
    }

    // ------------------------------------------------------------- link types ---

    public function testLinkTypes(): void
    {
        $env = $this->envelope($this->machine('GET', '/link-types'));
        $this->assertNotEmpty($env['data']['link_types']);
        $builtIn = LinkType::query()->where('editable', false)->firstOrFail();

        $this->enableWrites();
        $body  = ['name' => 'Reimburses', 'inward' => 'is reimbursed by', 'outward' => 'reimburses'];
        $plan  = $this->envelope($this->machine('POST', '/link-types', $body));
        $apply = $this->envelope($this->machine('POST', '/link-types', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $mine  = LinkType::query()->where('name', 'Reimburses')->firstOrFail();
        $this->assertTrue($mine->editable);
        $this->assertPlaneError($this->machine('POST', '/link-types', $body), 400, 'invalid_input');

        $plan  = $this->envelope($this->machine('PUT', '/link-types/Reimburses', ['outward' => 'pays back']));
        $apply = $this->envelope($this->machine('PUT', '/link-types/Reimburses', ['outward' => 'pays back', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertSame('pays back', $apply['data']['link_type']['outward']);
        $this->assertPlaneError($this->machine('PUT', '/link-types/'.$builtIn->id, ['outward' => 'x']), 403, 'forbidden');

        $this->enableAdmin();
        $plan  = $this->envelope($this->machine('DELETE', '/link-types/'.$mine->id));
        $apply = $this->envelope($this->machine('DELETE', '/link-types/'.$mine->id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertNull(LinkType::query()->find($mine->id));
    }

    // ------------------------------------------------------------ attachments ---

    public function testAttachmentUploadListDownloadDelete(): void
    {
        $journal = $this->coffee->transactionJournals()->first();
        $body    = ['attachable_type' => 'TransactionJournal', 'attachable_id' => $journal->id, 'filename' => 'receipt-4021.txt', 'title' => 'Receipt', 'content_base64' => base64_encode("Bean Cart\nTotal 4.50\n")];

        $this->enableWrites();
        $plan  = $this->envelope($this->machine('POST', '/attachments', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertFalse($plan['data']['file']['written']);
        $this->assertSame('text/plain', $plan['data']['file']['mime']);
        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame([], Storage::disk('upload')->allFiles(), 'a dry run writes no file');
        $apply = $this->envelope($this->machine('POST', '/attachments', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $id    = (int) $apply['data']['attachment']['id'];
        $this->assertTrue($apply['data']['attachment']['uploaded']);

        $list  = $this->envelope($this->machine('GET', '/attachments', ['attachable_type' => 'TransactionJournal', 'attachable_id' => $journal->id]));
        $this->assertSame(['receipt-4021.txt'], array_column($list['data']['attachments'], 'filename'));
        $this->assertSame('/machine/v1/attachments/'.$id.'/download', $list['data']['attachments'][0]['download_url']);
        $this->assertArrayNotHasKey('upload_url', $list['data']['attachments'][0]);
        $this->assertSame('Receipt', $this->envelope($this->machine('GET', '/attachments/'.$id))['data']['attachment']['title']);

        $file  = $this->machine('GET', '/attachments/'.$id.'/download');
        $file->assertStatus(200);
        $this->assertSame("Bean Cart\nTotal 4.50\n", $file->getContent());
        $this->assertStringContainsString('attachment; filename="receipt-4021.txt"', (string) $file->headers->get('Content-Disposition'));

        $this->assertPlaneError($this->machine('POST', '/attachments', ['content_base64' => '%%%'] + $body), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/attachments', ['attachable_id' => 999999] + $body), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/attachments', ['attachable_type' => 'Spaceship'] + $body), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/attachments/424242'), 404, 'not_found');

        $this->enableAdmin();
        $plan  = $this->envelope($this->machine('DELETE', '/attachments/'.$id));
        $this->assertCount(1, Storage::disk('upload')->allFiles(), 'the dry run of a delete kept the file');
        $apply = $this->envelope($this->machine('DELETE', '/attachments/'.$id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertNull(Attachment::query()->find($id));
        $this->assertSame([], Storage::disk('upload')->allFiles());
    }

    // ------------------------------------------------------------ preferences ---

    public function testPreferencesReadWithoutWritingAndSetAllowlistedOnly(): void
    {
        $before = Preference::query()->count();
        $env    = $this->envelope($this->machine('GET', '/preferences'));
        $byName = array_column($env['data']['preferences'], null, 'name');
        $this->assertSame('1M', $byName['viewRange']['value']);
        $this->assertFalse($byName['viewRange']['stored']);
        $this->assertSame($before, Preference::query()->count(), 'reading a default does not store it (R8)');

        $this->enableWrites();
        $this->assertPlaneError($this->machine('PUT', '/preferences/twoFactorAuthEnabled', ['value' => false]), 403, 'forbidden');
        $this->assertPlaneError($this->machine('PUT', '/preferences/viewRange', ['value' => '2Y']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/preferences/fiscalYearStart', ['value' => '02-30']), 400, 'invalid_input');

        $plan   = $this->envelope($this->machine('PUT', '/preferences/viewRange', ['value' => '3M']));
        $this->assertSame(['created' => 1], $plan['data']['changes']);
        $this->assertSame(0, Preference::query()->where('name', 'viewRange')->count());
        $apply  = $this->envelope($this->machine('PUT', '/preferences/viewRange', ['value' => '3M', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame('3M', Preference::query()->where('user_id', $this->user->id)->where('name', 'viewRange')->firstOrFail()->data);

        $plan   = $this->envelope($this->machine('PUT', '/preferences/frontpageAccounts', ['value' => ['Northbank Checking 4021']]));
        $this->assertSame([$this->checking->id], $plan['data']['preference']['value']);
        $plan   = $this->envelope($this->machine('PUT', '/preferences/customFiscalYear', ['value' => true]));
        $this->assertTrue($plan['ok']);

        // undo the viewRange write through the operation log
        $this->bindForUndo();
        DB::transaction(static fn () => OperationLog::reverse($apply['data']['operation_id']));
        $this->assertSame(0, Preference::query()->where('name', 'viewRange')->count());
    }

    // --------------------------------------------------------------- webhooks ---

    public function testWebhooksAreReadOnlyAndNeverShowTheSecret(): void
    {
        $webhook = Webhook::forceCreate(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'active' => true, 'title' => 'Ledger mirror', 'url' => 'https://hooks.example.test/ledger', 'secret' => str_repeat('s', 24), 'trigger' => 1, 'response' => 1, 'delivery' => 1]);
        $message = WebhookMessage::forceCreate(['webhook_id' => $webhook->id, 'sent' => false, 'errored' => true, 'uuid' => 'a2b1c3d4-0000-4000-8000-000000000001', 'message' => ['content' => ['id' => 1]]]);
        $message->webhookAttempts()->forceCreate(['status_code' => 500, 'logs' => 'timeout', 'response' => 'gateway error']);

        $env = $this->envelope($this->machine('GET', '/webhooks'));
        $this->assertCount(1, $env['data']['webhooks']);
        $this->assertSame('Ledger mirror', $env['data']['webhooks'][0]['title']);
        $this->assertStringNotContainsString(str_repeat('s', 24), (string) json_encode($env));
        $this->assertArrayNotHasKey('secret', $env['data']['webhooks'][0]);

        $messages = $this->envelope($this->machine('GET', '/webhooks/'.$webhook->id.'/messages'));
        $this->assertCount(1, $messages['data']['messages']);
        $this->assertTrue($messages['data']['messages'][0]['errored']);
        $attempts = $this->envelope($this->machine('GET', '/webhooks/'.$webhook->id.'/messages/'.$message->id.'/attempts'));
        $this->assertSame(500, $attempts['data']['attempts'][0]['status_code']);
        $this->assertPlaneError($this->machine('GET', '/webhooks/'.$webhook->id.'/messages/999/attempts'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/webhooks/31337/messages'), 404, 'not_found');
    }

    public function testEveryReferenceRouteIsLive(): void
    {
        $caps     = $this->envelope($this->machine('GET', '/capabilities'));
        $prefixes = ['/tags', '/object-groups', '/currencies', '/exchange-rates', '/link-types', '/attachments', '/preferences', '/webhooks'];
        $seen     = 0;
        foreach ($caps['data']['routes'] as $route) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($route['path'], $prefix)) {
                    foreach ($route['status'] as $method => $status) {
                        if ('/webhooks' === $prefix && 'GET' !== $method) {
                            continue; // webhook writes are admin and belong to AdminRoutes
                        }
                        ++$seen;
                        $this->assertSame('live', $status, $method.' '.$route['path']);
                    }
                }
            }
        }
        $this->assertGreaterThanOrEqual(29, $seen);
    }

    private function bindForUndo(): void
    {
        $request = request();
        $request->attributes->set('machine.operator', $this->user);
        $request->attributes->set('machine.administration', $this->user->userGroup);
        auth()->setUser($this->user);
    }
}
