<?php

/*
 * RouteDef.php
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

namespace Tests\Machine\Admin;

use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Models\Category;
use FireflyIII\Models\Webhook;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;
use Tests\Machine\Search\SearchLedger;

/**
 * pm/apis.mdx §8.11 (admin) and §8.10 (webhook authoring): the admin tier switch, the MCP
 * refusal, and every admin route — reads, the key rotation, cron, the corrections, destroy and
 * purge (dry run first, token, and an operation log undo refuses), and webhooks.
 *
 * @internal
 *
 * @coversNothing
 */
final class AdminTest extends MachineTestCase
{
    use SearchLedger;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $this->enableAdmin();
    }

    public function testTheAdminTierIsASeparateSwitchAndNeverTheMcps(): void
    {
        config(['machine.allow_admin' => false, 'machine.allow_write' => true]);
        $env = $this->assertPlaneError($this->machine('GET', '/admin/users'), 403, 'forbidden');
        $this->assertStringContainsString('FIREFLY_MACHINE_ALLOW_ADMIN', $env['error']['hint']);
        $this->enableAdmin();
        $env = $this->assertPlaneError($this->machine('GET', '/admin/users', [], ['X-Firefly-Client' => 'mcp']), 403, 'forbidden');
        $this->assertStringContainsString('never available to the MCP', $env['error']['message']);
        $this->assertTrue($this->envelope($this->machine('GET', '/admin/users'))['ok']);
    }

    public function testUsers(): void
    {
        $env = $this->envelope($this->machine('GET', '/admin/users'));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame('test@email.com', $env['data']['users'][0]['email']);
        $this->assertTrue($env['data']['users'][0]['is_operator']);
        $this->assertArrayNotHasKey('password', $env['data']['users'][0]);
        $this->assertStringNotContainsString('password', (string) $this->machine('GET', '/admin/users')->getContent());
        $this->assertSame('admin', $env['meta']['tier']);
        $this->assertPlaneError($this->machine('GET', '/admin/users', ['order' => 'password']), 400, 'invalid_input');
    }

    public function testConfiguration(): void
    {
        $env   = $this->envelope($this->machine('GET', '/admin/configuration'));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $names = array_column($env['data']['configuration'], 'name');
        $this->assertContains('single_user_mode', $names);
        $this->assertContains('allow_webhooks', $names);
        $this->assertPlaneError($this->machine('GET', '/admin/configuration', ['x' => 1]), 400, 'invalid_input');
    }

    public function testKeyRotationAnswersTheFingerprintOnlyAndTheOldKeyDies(): void
    {
        $old = CredentialsFile::fingerprint($this->machineKey);
        $env = $this->envelope($this->machine('POST', '/admin/key/rotate'));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame($old, $env['data']['previous_fingerprint']);
        $this->assertNotSame($old, $env['data']['fingerprint']);
        $this->assertStringNotContainsString($this->machineKey, (string) json_encode($env));
        $newKey = (string) json_decode((string) file_get_contents($this->credentialsPath()), true)['firefly_iii']['machine']['api_key'];
        $this->assertStringNotContainsString($newKey, (string) json_encode($env), 'the new key never travels over the wire');
        $this->assertSame(CredentialsFile::fingerprint($newKey), $env['data']['fingerprint']);

        CredentialsFile::forget();
        $this->assertFalse($this->envelope($this->machine('GET', '/ping'))['ok'], 'the old key stopped working');
        $this->machineKey = $newKey;
        $this->assertTrue($this->envelope($this->machine('GET', '/ping'))['ok']);
        $this->assertPlaneError($this->machine('POST', '/admin/key/rotate', ['label' => 'x']), 400, 'invalid_input');
    }

    public function testKeyRotationRefusesAKeyFromTheEnvironment(): void
    {
        $key = bin2hex(random_bytes(32));
        putenv('FIREFLY_MACHINE_KEY='.$key);
        $_ENV['FIREFLY_MACHINE_KEY'] = $key;
        CredentialsFile::forget();

        try {
            $this->machineKey = $key;
            $env              = $this->assertPlaneError($this->machine('POST', '/admin/key/rotate'), 409, 'conflict');
            $this->assertStringContainsString('FIREFLY_MACHINE_KEY', $env['error']['message']);
        } finally {
            putenv('FIREFLY_MACHINE_KEY');
            unset($_ENV['FIREFLY_MACHINE_KEY']);
            CredentialsFile::forget();
        }
    }

    public function testCronIsADryRunFirstAndUndoRefusesIt(): void
    {
        // §7.1 (LOCKED): cron creates ledger rows (recurring transactions, budget limits), so it
        // previews first and needs the token — like every write that changes a transaction
        $plan = $this->envelope($this->machine('POST', '/admin/cron', ['date' => '2026-09-21']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertTrue($plan['data']['dry_run'], 'the dry run is the default');
        $this->assertArrayHasKey('recurring_transactions', $plan['data']['jobs']);
        $this->assertArrayHasKey('auto_budgets', $plan['data']['jobs']);
        $this->assertTrue($plan['data']['jobs']['recurring_transactions']['fired'], 'the plan ran the job for real, rolled back');
        $this->assertSame('2026-09-21', $plan['data']['date']);
        $this->assertMatchesRegularExpression('/^cf_[0-9a-f]{32}$/', $plan['data']['confirm_token']);
        $this->assertSame(0, DB::table('machine_operations')->count(), 'a plan is not an operation');
        $this->assertNull(AppConfiguration::getFresh('last_rt_job_'.$this->user->id), 'the dry run\'s "last run" stamp was rolled back');
        $this->assertPlaneError($this->machine('POST', '/admin/cron', ['date' => '2026-09-21', 'dry_run' => false]), 403, 'forbidden');

        $env = $this->envelope($this->machine('POST', '/admin/cron', ['date' => '2026-09-21', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertFalse($env['data']['dry_run']);
        $this->assertTrue($env['data']['jobs']['recurring_transactions']['fired'], 'the rolled-back plan did not stop the apply from firing');
        $this->assertIsInt($env['data']['operation_id']);
        $this->assertNotNull(AppConfiguration::getFresh('last_rt_job_'.$this->user->id), 'the apply stamped the run');
        $this->assertPlaneError($this->machine('POST', '/admin/cron', ['date' => 'tomorrow']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/admin/cron', ['force' => 'maybe']), 400, 'invalid_input');

        $undo = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertSame('POST /admin/cron', $undo['data']['route']);
        $this->assertFalse($undo['data']['reversible']);
        $this->assertNull($undo['data']['confirm_token']);
        $this->assertSame('impossible', $undo['data']['effects'][0]['undo']);
    }

    public function testCorrectDatabaseIsADryRunFirst(): void
    {
        $plan = $this->envelope($this->machine('POST', '/admin/correct-database'));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertTrue($plan['data']['dry_run']);
        $commands = array_column($plan['data']['correctors'], 'command');
        $this->assertContains('correction:amounts', $commands);
        $this->assertContains('correction:restore-oauth-keys', $commands, 'skipped correctors are listed, with why');
        $skipped = array_values(array_filter($plan['data']['correctors'], static fn (array $c): bool => 'correction:access-tokens' === $c['command']))[0];
        $this->assertFalse($skipped['ran']);
        $this->assertSame(0, DB::table('machine_operations')->count());

        $apply = $this->envelope($this->machine('POST', '/admin/correct-database', ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertFalse($apply['data']['dry_run']);
    }

    /**
     * Regression: "changed" was counted from the correctors' chatter, so "[i] Done recalculating…"
     * on a clean ledger counted as a correction (and marked the operation irreversible). What a
     * corrector changed is measured on the tables it touched.
     */
    public function testCorrectDatabaseCountsTablesThatMovedNotChatter(): void
    {
        $plan  = $this->envelope($this->machine('POST', '/admin/correct-database'));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $byCommand = array_column($plan['data']['correctors'], null, 'command');
        $talker    = $byCommand['correction:recalculate-pc-amounts'];
        $this->assertNotEmpty($talker['lines'], 'this corrector always says something');
        $this->assertSame(0, $talker['changed'], 'saying something is not changing something');
        $this->assertSame([], $talker['changed_tables']);
        $sum = 0;
        foreach ($plan['data']['correctors'] as $corrector) {
            $this->assertSame(count($corrector['changed_tables']), $corrector['changed']);
            $sum += $corrector['changed'];
        }
        $this->assertSame($sum, $plan['data']['corrected']);
        $this->assertSame($sum, $plan['data']['change_count'], 'the ceiling counts tables that moved');
        $this->assertSame(['corrected' => $sum], $plan['data']['changes']);
        $this->assertIsArray($plan['data']['changed_tables']);
    }

    /** §7.2: a cron plan holds every outside effect — no mail, no notification, no queued job, no exchange-rate download. */
    public function testACronPlanSendsNothing(): void
    {
        AppConfiguration::set('enable_external_rates', true);
        $plan = $this->envelope($this->machine('POST', '/admin/cron', ['force' => true]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertTrue($plan['data']['dry_run']);
        $this->assertFalse($plan['data']['jobs']['exchange_rates']['fired'], 'the download is skipped in the plan');
        $this->assertStringContainsString('Skipped in the dry run', $plan['data']['jobs']['exchange_rates']['message']);
        $this->assertSame(0, $plan['data']['would_fire_webhooks']);
        $this->assertSame(0, $plan['data']['held']['mail']);
        $this->assertSame(0, $plan['data']['held']['notifications']);
        $this->assertIsInt($plan['data']['held']['jobs'], 'the queued jobs the recorder caught are reported, not run');
        $this->assertSame(0, DB::table('machine_operations')->count());
        $this->assertSame(0, $plan['data']['change_count'], 'nothing is due on an empty ledger');
        $this->assertSame([], (array) $plan['data']['created']);
    }

    public function testDestroyCountsFirstThenDestroysAndCannotBeUndone(): void
    {
        foreach (['Groceries', 'Dining'] as $name) {
            Category::create(['name' => $name, 'user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id]);
        }
        $plan = $this->envelope($this->machine('POST', '/admin/data/destroy', ['objects' => 'categories']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['categories' => 2], $plan['data']['changes']);
        $this->assertSame(2, Category::query()->count(), 'the dry run destroyed nothing');

        $this->assertPlaneError($this->machine('POST', '/admin/data/destroy', ['objects' => 'categories', 'dry_run' => false]), 403, 'forbidden');
        $apply = $this->envelope($this->machine('POST', '/admin/data/destroy', ['objects' => 'categories', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(0, Category::query()->count());
        $this->assertFalse($this->envelope($this->machine('GET', '/undo/last'))['data']['reversible']);

        $this->assertPlaneError($this->machine('POST', '/admin/data/destroy', ['objects' => 'everything']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/admin/data/destroy'), 400, 'invalid_input');
    }

    public function testPurgeRemovesSoftDeletedRowsAndSaysUndoCannotComeBack(): void
    {
        $gone = Category::create(['name' => 'Old', 'user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id]);
        Category::create(['name' => 'Live', 'user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id]);
        $gone->delete();
        $plan  = $this->envelope($this->machine('POST', '/admin/data/purge'));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['categories' => 1], $plan['data']['changes']);
        $this->assertStringContainsString('/undo', $plan['data']['warning']);
        $this->assertSame(2, Category::withTrashed()->count());
        $apply = $this->envelope($this->machine('POST', '/admin/data/purge', ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(['Live'], Category::withTrashed()->pluck('name')->all());
    }

    public function testWebhookAuthoring(): void
    {
        $body = ['title' => 'Household feed', 'url' => 'http://127.0.0.1:9/hook', 'triggers' => ['STORE_TRANSACTION'], 'responses' => ['TRANSACTIONS'], 'deliveries' => ['JSON']];
        AppConfiguration::set('allow_webhooks', false);
        $off  = $this->assertPlaneError($this->machine('POST', '/webhooks', $body), 403, 'forbidden');
        $this->assertStringContainsString('Allow webhooks', $off['error']['hint']);
        AppConfiguration::set('allow_webhooks', true);

        $plan = $this->envelope($this->machine('POST', '/webhooks', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['created' => 1], $plan['data']['changes']);
        $this->assertSame(0, Webhook::query()->count());
        $made = $this->envelope($this->machine('POST', '/webhooks', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($made['ok'], (string) json_encode($made));
        $this->assertSame('Household feed', $made['data']['webhook']['title']);
        $this->assertArrayNotHasKey('secret', $made['data']['webhook'], 'the signing secret never travels');
        $id   = (string) $made['data']['webhook']['id'];

        $this->assertPlaneError($this->machine('POST', '/webhooks', ['title' => 'x']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/webhooks', $body + ['title' => 'Other', 'triggers' => ['NOT_A_TRIGGER']]), 400, 'invalid_input');

        $edit = $this->envelope($this->machine('PUT', '/webhooks/'.$id, ['title' => 'Household feed v2']));
        $this->assertTrue($edit['ok'], (string) json_encode($edit));
        $this->assertSame(['updated' => 1], $edit['data']['changes']);
        $this->envelope($this->machine('PUT', '/webhooks/'.$id, ['title' => 'Household feed v2', 'dry_run' => false, 'confirm_token' => $edit['data']['confirm_token']]));
        $this->assertSame('Household feed v2', Webhook::query()->value('title'));
        $this->assertPlaneError($this->machine('PUT', '/webhooks/9999', ['title' => 'x']), 404, 'not_found');

        $submit = $this->envelope($this->machine('POST', '/webhooks/'.$id.'/submit'));
        $this->assertTrue($submit['ok'], (string) json_encode($submit));
        $this->assertTrue($submit['data']['dry_run'], 'a submit is an outbound call: it previews first');
        $this->assertSame(0, $submit['data']['queued']);
        $this->assertSame(0, $submit['data']['would_fire_webhooks']);
        $this->assertPlaneError($this->machine('POST', '/webhooks/'.$id.'/submit', ['dry_run' => false]), 403, 'forbidden');
        $sent = $this->envelope($this->machine('POST', '/webhooks/'.$id.'/submit', ['dry_run' => false, 'confirm_token' => $submit['data']['confirm_token']]));
        $this->assertTrue($sent['ok'], (string) json_encode($sent));
        $this->assertFalse($sent['data']['dry_run']);

        $del  = $this->envelope($this->machine('DELETE', '/webhooks/'.$id));
        $this->assertTrue($del['data']['dry_run']);
        $this->assertSame(1, Webhook::query()->count());
        $this->envelope($this->machine('DELETE', '/webhooks/'.$id, ['dry_run' => false, 'confirm_token' => $del['data']['confirm_token']]));
        $this->assertSame(0, Webhook::query()->count());
        $again = $this->envelope($this->machine('DELETE', '/webhooks/'.$id));
        $this->assertSame(0, $again['data']['deleted'], 'a DELETE of something already gone is ok with deleted: 0');
    }

    /**
     * Regression: a webhook URL without a host ("javascript:alert(1)", "http://") reached Firefly's
     * IsValidWebhookUrl, whose host lookup threw a TypeError — answered as `internal` (500)
     * instead of invalid_input naming the field. The url rule now bails before it.
     */
    public function testAMalformedWebhookUrlIsInvalidInputNotInternal(): void
    {
        AppConfiguration::set('allow_webhooks', true);
        $body = ['title' => 'Bad', 'triggers' => ['STORE_TRANSACTION'], 'responses' => ['TRANSACTIONS'], 'deliveries' => ['JSON']];
        foreach (['javascript:alert(1)', 'http://', 'not a url', 'gopher://127.0.0.1/x', ''] as $url) {
            $env = $this->assertPlaneError($this->machine('POST', '/webhooks', $body + ['url' => $url]), 400, 'invalid_input');
            $this->assertArrayHasKey('url', $env['error']['details']['fields'], $url);
        }
        $this->assertPlaneError($this->machine('POST', '/webhooks', $body + ['url' => 'http://127.0.0.1:9/x', 'active' => 'maybe']), 400, 'invalid_input');
        $this->assertSame(0, Webhook::query()->count());

        $plan = $this->envelope($this->machine('POST', '/webhooks', $body + ['url' => 'http://127.0.0.1:9/x']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $made = $this->envelope($this->machine('POST', '/webhooks', $body + ['url' => 'http://127.0.0.1:9/x', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($made['ok']);
        $env  = $this->assertPlaneError($this->machine('PUT', '/webhooks/'.$made['data']['webhook']['id'], ['url' => 'javascript:alert(1)']), 400, 'invalid_input');
        $this->assertArrayHasKey('url', $env['error']['details']['fields']);
    }
}
