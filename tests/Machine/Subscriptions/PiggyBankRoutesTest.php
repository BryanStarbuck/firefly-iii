<?php

/*
 * PiggyBankRoutesTest.php
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

namespace Tests\Machine\Subscriptions;

use FireflyIII\Machine\Http\Controllers\PiggyBankController;
use FireflyIII\Models\Account;
use FireflyIII\Models\PiggyBank;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.6 — /piggy-banks: reads, create/edit, and add/remove money with Firefly's own
 * "can the account spare it" check, refused WITH THE FIGURE.
 *
 * @internal
 *
 * @coversNothing
 */
final class PiggyBankRoutesTest extends MachineTestCase
{
    use SeedsSavings;

    private const array TABLES = ['piggy_banks', 'account_piggy_bank', 'piggy_bank_events', 'notes'];

    private User $user;
    private Account $checking;
    private PiggyBank $vacation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = $this->operatorUser();
        $this->usd($this->user);
        $this->checking = $this->checking($this->user);
        $this->payroll($this->user, $this->checking, '500.00', '2026-09-01');
        $this->vacation = $this->piggy($this->user, $this->checking, 'Vacation', '1000.00', '100.00');
    }

    public function testListAndShow(): void
    {
        $env = $this->envelope($this->machine('GET', '/piggy-banks'));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $row = $env['data']['piggy_banks'][0];
        $this->assertSame('Vacation', $row['name']);
        $this->assertSame('USD', $row['currency_code']);
        $this->assertSame('1000.00', $row['target_amount']);
        $this->assertSame('100.00', $row['current_amount']);
        $this->assertSame('900.00', $row['left_to_save']);
        $this->assertSame('2027-06-30', $row['target_date']);
        $this->assertIsString($row['save_per_month']);
        $this->assertSame([['account_id' => $this->checking->id, 'name' => 'Northbank Checking 4021', 'current_amount' => '100.00']], $row['accounts']);

        $env = $this->envelope($this->machine('GET', '/piggy-banks/vacation'));
        $this->assertSame($this->vacation->id, $env['data']['piggy_bank']['id']);
        $this->assertSame('400.00', $env['data']['piggy_bank']['accounts'][0]['available_to_add'], '500 on the account, 100 already set aside');

        $this->assertPlaneError($this->machine('GET', '/piggy-banks/99999'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/piggy-banks', ['colour' => 'pink']), 400, 'invalid_input');
    }

    public function testNoTargetIsNullNotZero(): void
    {
        $this->piggy($this->user, $this->checking, 'Rainy day', '0');
        $env = $this->envelope($this->machine('GET', '/piggy-banks/Rainy%20day'));
        $this->assertNull($env['data']['piggy_bank']['target_amount'], '§14.2 absent is not zero');
        $this->assertNull($env['data']['piggy_bank']['left_to_save']);
    }

    public function testAddMoneyDryRunThenApplyAndTheEventIsListed(): void
    {
        $this->enableWrites();
        $before = $this->hashTables(self::TABLES);
        $plan   = $this->envelope($this->machine('POST', '/piggy-banks/'.$this->vacation->id.'/add', ['amount' => '200.00']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertTrue($plan['data']['dry_run']);
        $this->assertSame(['added' => 1], $plan['data']['changes']);
        $this->assertSame('300.00', $plan['data']['piggy_bank']['current_amount'], 'the preview ran the real code');
        $this->assertSame($before, $this->hashTables(self::TABLES), 'the dry run changed nothing');

        $done   = $this->envelope($this->machine('POST', '/piggy-banks/'.$this->vacation->id.'/add', ['amount' => '200.00', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertSame('300.00', $done['data']['piggy_bank']['current_amount']);
        $this->assertSame($this->checking->id, $done['data']['account_id']);
        $this->assertIsInt($done['data']['operation_id']);
        $op     = DB::table('machine_operations')->where('id', $done['data']['operation_id'])->first();
        $this->assertContains(PiggyBankController::PIVOT_RECORD, array_column(json_decode((string) $op->touched, true), 'class'), 'the saved amount per account is in the undo log');

        $events = $this->envelope($this->machine('GET', '/piggy-banks/'.$this->vacation->id.'/events'));
        $this->assertTrue($events['ok'], (string) json_encode($events));
        $this->assertSame('200.00', $events['data']['events'][0]['amount']);
        $this->assertSame('add', $events['data']['events'][0]['direction']);
        $this->assertSame('Northbank Checking 4021', $events['data']['events'][0]['account_name']);
    }

    public function testAddRefusesBeyondWhatTheAccountCanSpareWithTheFigure(): void
    {
        $this->enableWrites();
        $env = $this->assertPlaneError($this->machine('POST', '/piggy-banks/Vacation/add', ['amount' => '450.00']), 400, 'invalid_input');
        $this->assertSame('400.00', $env['error']['details']['max_amount']);
        $this->assertSame('400.00', $env['error']['details']['left_on_account']);
        $this->assertSame('900.00', $env['error']['details']['left_to_save']);
        $this->assertStringContainsString('400.00', $env['error']['hint']);

        $this->assertPlaneError($this->machine('POST', '/piggy-banks/Vacation/add', ['amount' => 50]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks/Vacation/add', ['amount' => '-5.00']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks/Vacation/add', ['amount' => '5.001']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks/99999/add', ['amount' => '5.00']), 404, 'not_found');
        $other = $this->checking($this->user, 'Meridian Savings 7734');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks/Vacation/add', ['amount' => '5.00', 'account_id' => (string) $other->id]), 400, 'invalid_input');
    }

    public function testRemoveMoneyAndItsLimit(): void
    {
        $this->enableWrites();
        $env  = $this->assertPlaneError($this->machine('POST', '/piggy-banks/Vacation/remove', ['amount' => '150.00']), 400, 'invalid_input');
        $this->assertSame('100.00', $env['error']['details']['max_amount']);

        $plan = $this->envelope($this->machine('POST', '/piggy-banks/Vacation/remove', ['amount' => '40.00', 'account_name' => 'Northbank Checking 4021']));
        $this->assertSame(['removed' => 1], $plan['data']['changes']);
        $done = $this->envelope($this->machine('POST', '/piggy-banks/Vacation/remove', ['amount' => '40.00', 'account_name' => 'Northbank Checking 4021', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertSame('60.00', $done['data']['piggy_bank']['current_amount']);
    }

    public function testAStaleTokenIsRefusedWhenTheSavedAmountMoved(): void
    {
        $this->enableWrites();
        $plan = $this->envelope($this->machine('POST', '/piggy-banks/Vacation/add', ['amount' => '50.00']));
        DB::table('account_piggy_bank')->where('piggy_bank_id', $this->vacation->id)->update(['current_amount' => '120.00']);
        $this->assertPlaneError($this->machine('POST', '/piggy-banks/Vacation/add', ['amount' => '50.00', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertSame(0, bccomp('120', (string) DB::table('account_piggy_bank')->where('piggy_bank_id', $this->vacation->id)->value('current_amount'), 12), 'nothing was written');
    }

    public function testCreateAndEdit(): void
    {
        $this->enableWrites();
        $body   = ['name' => 'New laptop', 'accounts' => ['Northbank Checking 4021'], 'target_amount' => '1500.00', 'target_date' => '2027-03-01'];
        $before = $this->hashTables(self::TABLES);
        $plan   = $this->envelope($this->machine('POST', '/piggy-banks', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['created' => 1], $plan['data']['changes']);
        $this->assertSame('1500.00', $plan['data']['piggy_bank']['target_amount']);
        $this->assertSame($before, $this->hashTables(self::TABLES));

        $done   = $this->envelope($this->machine('POST', '/piggy-banks', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $id     = $done['data']['piggy_bank']['id'];
        $this->assertSame('New laptop', PiggyBank::query()->find($id)?->name);
        $this->assertSame('USD', $done['data']['piggy_bank']['currency_code'], 'the account\'s currency by default');

        $plan   = $this->envelope($this->machine('PUT', '/piggy-banks/'.$id, ['name' => 'New laptop 14in', 'target_amount' => '1800.00']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['updated' => 1], $plan['data']['changes']);
        $done   = $this->envelope($this->machine('PUT', '/piggy-banks/'.$id, ['name' => 'New laptop 14in', 'target_amount' => '1800.00', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertSame('1800.00', $done['data']['piggy_bank']['target_amount']);
        $this->assertSame([['account_id' => $this->checking->id, 'name' => 'Northbank Checking 4021', 'current_amount' => '0.00']], $done['data']['piggy_bank']['accounts'], 'the linked accounts are kept');

        $this->assertPlaneError($this->machine('POST', '/piggy-banks', ['name' => 'Vacation', 'accounts' => [(string) $this->checking->id]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks', ['name' => 'Car', 'accounts' => ['No Such Bank 0000']]), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks', ['name' => 'Car', 'accounts' => []]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/piggy-banks/'.$id, []), 400, 'invalid_input');
    }

    public function testEditLinksASecondAccountAndKeepsWhatIsSaved(): void
    {
        $this->enableWrites();
        $savings = $this->checking($this->user, 'Meridian Savings 7734');
        $body    = ['accounts' => [(string) $this->checking->id, ['account_name' => 'Meridian Savings 7734']]];
        $plan    = $this->envelope($this->machine('PUT', '/piggy-banks/Vacation', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $done    = $this->envelope($this->machine('PUT', '/piggy-banks/Vacation', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $linked  = array_column($done['data']['piggy_bank']['accounts'], 'current_amount', 'account_id');
        $this->assertSame([$this->checking->id => '100.00', $savings->id => '0.00'], $linked);
        $this->assertSame('100.00', $done['data']['piggy_bank']['current_amount']);

        // with two accounts, add money must say which
        $this->assertPlaneError($this->machine('POST', '/piggy-banks/Vacation/add', ['amount' => '5.00']), 400, 'invalid_input');
    }

    public function testDeleteIsAdmin(): void
    {
        $this->enableWrites();
        $this->assertPlaneError($this->machine('DELETE', '/piggy-banks/Vacation'), 403, 'forbidden');
        $this->enableAdmin();
        $plan = $this->envelope($this->machine('DELETE', '/piggy-banks/Vacation'));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame('100.00', $plan['data']['piggy_bank']['current_amount']);
        $done = $this->envelope($this->machine('DELETE', '/piggy-banks/Vacation', ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertNull(PiggyBank::query()->find($this->vacation->id));
        $again = $this->envelope($this->machine('DELETE', '/piggy-banks/'.$this->vacation->id));
        $this->assertSame(0, $again['data']['deleted']);
    }
}
