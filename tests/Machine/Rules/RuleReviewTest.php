<?php

/*
 * RuleReviewTest.php
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

namespace Tests\Machine\Rules;

use FireflyIII\Models\Account;
use FireflyIII\Models\RuleGroup;
use FireflyIII\User;
use Tests\Machine\MachineTestCase;

/**
 * Regressions from the adversarial review of the rules family (apis.mdx §8.7).
 *
 * @internal
 *
 * @coversNothing
 */
final class RuleReviewTest extends MachineTestCase
{
    use SeedsLedger;

    private User $user;
    private Account $checking;
    private RuleGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = $this->operatorUser();
        $this->primaryUsd($this->user);
        $this->checking = $this->asset($this->user);
        $this->withdrawal($this->user, $this->checking, 'Coffee + croissant', '9.50', '2026-08-03', 'Bean Cart');
        $this->group    = $this->ruleGroup($this->user, 'Food + drink');
    }

    /** Route segments arrive already URL-decoded: a title with "+" or "%" must resolve as written, not decoded twice. */
    public function testRuleAndGroupTitlesWithPlusResolveExactly(): void
    {
        $rule = $this->rule($this->user, $this->group, 'Coffee + tea', ['triggers' => [['type' => 'description_contains', 'value' => 'coffee +', 'active' => true, 'stop_processing' => false, 'prohibited' => false]]]);
        $this->rule($this->user, $this->group, 'Coffee   tea'); // what a second urldecode() would produce — never picked

        $env = $this->envelope($this->machine('GET', '/rules/'.rawurlencode('Coffee + tea')));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame((string) $rule->id, (string) $env['data']['rule']['id']);
        $this->assertSame('Coffee + tea', $env['data']['rule']['title']);

        $list = $this->envelope($this->machine('GET', '/rules', ['rule_group_name' => 'Food + drink']));
        $this->assertCount(2, $list['data']['rules']);

        $preview = $this->envelope($this->machine('POST', '/rule-groups/'.rawurlencode('Food + drink').'/preview'));
        $this->assertTrue($preview['ok'], (string) json_encode($preview));
        $this->assertSame((string) $this->group->id, $preview['data']['subject']['rule_group_id']);
        $this->assertSame(1, $preview['data']['match_count'], 'the "coffee +" trigger matched the croissant row');

        $this->enableWrites();
        $plan = $this->envelope($this->machine('PUT', '/rules/'.rawurlencode('Coffee + tea'), ['rule' => ['description' => 'hot drinks']]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame('Coffee + tea', $plan['data']['rule']['title']);
    }
}
