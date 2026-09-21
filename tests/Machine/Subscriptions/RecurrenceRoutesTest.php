<?php

/*
 * RecurrenceRoutesTest.php
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

use Carbon\Carbon;
use FireflyIII\Models\Account;
use FireflyIII\Models\Recurrence;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.6 — /recurrences: create/edit through the write protocol, the next N dates,
 * and trigger (no dry run; refuses with the reason where upstream's job would create nothing).
 * Dates are relative to the real clock (write tests cannot pin "now": tokens expire on it).
 *
 * @internal
 *
 * @coversNothing
 */
final class RecurrenceRoutesTest extends MachineTestCase
{
    use SeedsSavings;

    private const array TABLES = ['recurrences', 'recurrences_repetitions', 'recurrences_transactions', 'recurrences_meta', 'rt_meta', 'notes'];

    private User $user;
    private Account $checking;
    private Carbon $first;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = $this->operatorUser();
        $this->usd($this->user);
        $this->checking = $this->checking($this->user);
        $this->payroll($this->user, $this->checking, '5000.00', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $this->first    = Carbon::now(config('app.timezone'))->startOfMonth()->addMonth()->startOfDay(); // the 1st of next month
    }

    public function testCreateDryRunApplyListAndShowTheNextDates(): void
    {
        $this->enableWrites();
        $body   = ['recurrence' => $this->rent()];
        $before = $this->hashTables(self::TABLES);
        $plan   = $this->envelope($this->machine('POST', '/recurrences', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertTrue($plan['data']['dry_run']);
        $this->assertSame(['created' => 1], $plan['data']['changes']);
        $this->assertSame('Rent', $plan['data']['recurrence']['title']);
        $this->assertSame($before, $this->hashTables(self::TABLES), 'the dry run changed nothing');

        $done   = $this->envelope($this->machine('POST', '/recurrences', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $id     = $done['data']['recurrence']['id'];
        $this->assertSame('withdrawal', $done['data']['recurrence']['type']);
        $this->assertSame('1200.00', $done['data']['recurrence']['transactions'][0]['amount']);
        $this->assertSame('USD', $done['data']['recurrence']['transactions'][0]['currency_code']);
        $this->assertNull($done['data']['recurrence']['transactions'][0]['foreign_amount'], 'no foreign currency: no foreign amount (§14.2), not upstream\'s "0.00"');
        $this->assertSame('Housing', $done['data']['recurrence']['transactions'][0]['category_name'], 'category_name is stored (upstream validates it, then drops it)');
        $this->assertSame('Meridian Property', $done['data']['recurrence']['transactions'][0]['destination_name']);

        $list   = $this->envelope($this->machine('GET', '/recurrences'));
        $this->assertSame($id, $list['data']['recurrences'][0]['id']);
        $this->assertSame($this->first->format('Y-m-d'), $list['data']['recurrences'][0]['next_date']);

        $show   = $this->envelope($this->machine('GET', '/recurrences/'.$id, ['count' => 3]));
        $this->assertSame([
            $this->first->format('Y-m-d'),
            (clone $this->first)->addMonth()->format('Y-m-d'),
            (clone $this->first)->addMonths(2)->format('Y-m-d'),
        ], $show['data']['recurrence']['next_dates']);
        $this->assertSame($id, $this->envelope($this->machine('GET', '/recurrences/rent'))['data']['recurrence']['id']);
    }

    public function testCreateRefusesBadShapes(): void
    {
        $this->enableWrites();
        $this->assertPlaneError($this->machine('POST', '/recurrences', ['recurrence' => ['colour' => 'red'] + $this->rent()]), 400, 'invalid_input');
        $bad = $this->rent();
        $bad['transactions'][0]['amount'] = 1200.00;
        $this->assertPlaneError($this->machine('POST', '/recurrences', ['recurrence' => $bad]), 400, 'invalid_input');
        $bad = $this->rent();
        unset($bad['nr_of_repetitions']);
        $this->assertPlaneError($this->machine('POST', '/recurrences', ['recurrence' => $bad]), 400, 'invalid_input'); // upstream: repeat_until or nr_of_repetitions
        $this->assertPlaneError($this->machine('POST', '/recurrences', ['title' => 'Rent']), 400, 'invalid_input');
        $this->assertSame(0, Recurrence::query()->count());
    }

    public function testUpdateAndAStaleToken(): void
    {
        $this->enableWrites();
        $id    = $this->storeRent();
        $plan  = $this->envelope($this->machine('PUT', '/recurrences/'.$id, ['recurrence' => ['title' => 'Rent (Meridian)']]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['updated' => 1], $plan['data']['changes']);
        DB::table('recurrences')->where('id', $id)->update(['description' => 'edited in the browser']);
        $this->assertPlaneError($this->machine('PUT', '/recurrences/'.$id, ['recurrence' => ['title' => 'Rent (Meridian)'], 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');

        $plan  = $this->envelope($this->machine('PUT', '/recurrences/'.$id, ['recurrence' => ['title' => 'Rent (Meridian)']]));
        $done  = $this->envelope($this->machine('PUT', '/recurrences/'.$id, ['recurrence' => ['title' => 'Rent (Meridian)'], 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertSame('Rent (Meridian)', Recurrence::query()->find($id)?->title);
        $this->assertPlaneError($this->machine('PUT', '/recurrences/99999', ['recurrence' => ['title' => 'x']]), 404, 'not_found');
    }

    public function testTriggerCreatesTheOccurrenceWithoutADryRunAndRefusesRepeats(): void
    {
        $this->enableWrites();
        $id     = $this->storeRent();
        $groups = TransactionGroup::query()->count();

        // there is no dry_run on this route: the flag is an unknown argument, not silently ignored
        $this->assertPlaneError($this->machine('POST', '/recurrences/'.$id.'/trigger', ['dry_run' => true]), 400, 'invalid_input');
        $this->assertSame($groups, TransactionGroup::query()->count());

        // not an occurrence date: refused with the next dates
        $bad    = $this->assertPlaneError($this->machine('POST', '/recurrences/'.$id.'/trigger', ['date' => (clone $this->first)->addDay()->format('Y-m-d')]), 400, 'invalid_input');
        $this->assertSame($this->first->format('Y-m-d'), $bad['error']['details']['next_dates'][0]);

        $env    = $this->envelope($this->machine('POST', '/recurrences/'.$id.'/trigger'));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertFalse($env['data']['dry_run']);
        $this->assertSame(['created' => 1], $env['data']['changes']);
        $this->assertSame($this->first->format('Y-m-d'), $env['data']['occurrence_date']);
        $this->assertCount(1, $env['data']['transactions']);
        $this->assertSame('1200.00', $env['data']['transactions'][0]['transactions'][0]['amount']);
        $this->assertIsInt($env['data']['operation_id']);
        $this->assertSame($groups + 1, TransactionGroup::query()->count());
        $op     = DB::table('machine_operations')->where('id', $env['data']['operation_id'])->first();
        $this->assertContains(TransactionGroup::class, array_column(json_decode((string) $op->touched, true), 'class'), 'undo can delete what trigger created');

        $made   = $this->envelope($this->machine('GET', '/recurrences/'.$id.'/transactions'));
        $this->assertCount(1, $made['data']['transactions']);

        $again  = $this->machine('POST', '/recurrences/'.$id.'/trigger', ['date' => $this->first->format('Y-m-d')]);
        $this->assertPlaneError($again, 409, 'conflict');
        $this->assertSame($groups + 1, TransactionGroup::query()->count());
    }

    public function testALinkNameThatDoesNotExistIsRefusedNotDropped(): void
    {
        $this->enableWrites();
        $bad = $this->rent();
        $bad['transactions'][0]['budget_name'] = 'No such budget';
        $env = $this->assertPlaneError($this->machine('POST', '/recurrences', ['recurrence' => $bad]), 404, 'not_found');
        $this->assertSame('recurrence.transactions.0.budget_name', $env['error']['details']['field']);
        $this->assertSame(0, Recurrence::query()->count());
    }

    public function testUpdateWithAnEmptyShapeSaysWhatItAccepts(): void
    {
        $this->enableWrites();
        $id  = $this->storeRent();
        $env = $this->assertPlaneError($this->machine('PUT', '/recurrences/'.$id, ['recurrence' => []]), 400, 'invalid_input');
        $this->assertStringContainsString('Nothing to change', $env['error']['message']);
        $this->assertStringContainsString('repetitions', $env['error']['hint']);
        $this->assertPlaneError($this->machine('PUT', '/recurrences/'.$id, ['recurrence' => 'Rent']), 400, 'invalid_input');
    }

    public function testTriggerTakesAnIdempotencyKeyAndReplaysTheOriginal(): void
    {
        $this->enableWrites();
        $id     = $this->storeRent();
        $groups = TransactionGroup::query()->count();
        $first  = $this->envelope($this->machine('POST', '/recurrences/'.$id.'/trigger', ['idempotency_key' => 'rent-oct']));
        $this->assertTrue($first['ok'], (string) json_encode($first));
        $again  = $this->envelope($this->machine('POST', '/recurrences/'.$id.'/trigger', ['idempotency_key' => 'rent-oct']));
        $this->assertTrue($again['ok'], (string) json_encode($again));
        $this->assertTrue($again['meta']['replayed'] ?? false, 'the repeat returns the original answer (§5.6)');
        $this->assertSame($first['data']['operation_id'], $again['data']['operation_id']);
        $this->assertSame($groups + 1, TransactionGroup::query()->count(), 'created once');
    }

    public function testTriggerNeedsTheWriteTierAndRefusesAnInactiveRecurrence(): void
    {
        $this->enableWrites();
        $id = $this->storeRent();
        config(['machine.allow_write' => false]);
        $this->assertPlaneError($this->machine('POST', '/recurrences/'.$id.'/trigger'), 403, 'write_disabled');
        $this->enableWrites();
        DB::table('recurrences')->where('id', $id)->update(['active' => false]);
        $this->assertPlaneError($this->machine('POST', '/recurrences/'.$id.'/trigger'), 400, 'invalid_input');
    }

    public function testDeleteIsAdmin(): void
    {
        $this->enableWrites();
        $id   = $this->storeRent();
        $this->assertPlaneError($this->machine('DELETE', '/recurrences/'.$id), 403, 'forbidden');
        $this->enableAdmin();
        $plan = $this->envelope($this->machine('DELETE', '/recurrences/'.$id));
        $this->assertSame(['deleted' => 1], $plan['data']['changes']);
        $this->assertNotNull(Recurrence::query()->find($id));
        $done = $this->envelope($this->machine('DELETE', '/recurrences/'.$id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertNull(Recurrence::query()->find($id));
        $this->assertSame(0, $this->envelope($this->machine('DELETE', '/recurrences/'.$id))['data']['deleted']);
    }

    /** @return array<string, mixed> Firefly's recurrence shape */
    private function rent(): array
    {
        return [
            'type'              => 'withdrawal',
            'title'             => 'Rent',
            'first_date'        => $this->first->format('Y-m-d'),
            'nr_of_repetitions' => 12,
            'apply_rules'       => true,
            'active'            => true,
            'repetitions'       => [['type' => 'monthly', 'moment' => '1', 'skip' => 0, 'weekend' => 1]],
            'transactions'      => [[
                'description'      => 'Rent',
                'amount'           => '1200.00',
                'currency_code'    => 'USD',
                'source_id'        => (string) $this->checking->id,
                'destination_name' => 'Meridian Property',
                'category_name'    => 'Housing',
            ]],
        ];
    }

    private function storeRent(): int
    {
        $plan = $this->envelope($this->machine('POST', '/recurrences', ['recurrence' => $this->rent()]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $done = $this->envelope($this->machine('POST', '/recurrences', ['recurrence' => $this->rent(), 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));

        return (int) $done['data']['recurrence']['id'];
    }
}
