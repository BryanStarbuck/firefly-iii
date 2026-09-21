<?php

/*
 * AnalyticsFixture.php
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

namespace Tests\Machine\Analytics;

use Carbon\Carbon;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Bill;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\Category;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\PiggyBankEvent;
use FireflyIII\Models\Tag;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use FireflyIII\User;

/**
 * An INVENTED household ledger (no real data — CLAUDE.md private-data boundary), written row by
 * row so every expected figure in the analytics tests can be computed by hand:
 *
 *   Northbank checking 4021 (EUR)  opening balance 1,000.00 on 2026-06-30
 *   Meridian savings 7734 (EUR)     receives a 500.00 transfer on 2026-07-15
 *   Northbank USD 5512 (USD)        one 42.00 USD dinner on 2026-07-20
 *   Meridian car loan (EUR loan)    opening balance −5,000.00 on 2026-06-30
 *
 *   payroll 3,000.00 on the 1st of Jul/Aug/Sep (Acme LLC payroll)
 *   Iron Gym 30.00 on 07-02 and 08-03, linked to the "Gym" subscription (monthly, from 07-01)
 *   Fresh Grocer 120.40 (07-05), 80.00 (07-19), 110.10 (08-06), 130.95 (09-04) — Groceries, Household budget
 *   Streamly 15.99 on the 10th of Jul/Aug/Sep — Subscriptions (not a Firefly subscription)
 *   Corner Cafe 4.50 (07-12, no category), 250.00 (09-15, no category, tag "vacation")
 *   Household budget limits: July 250.00 EUR, August 100.00 EUR (none in September)
 *   Piggy bank "Holiday fund": target 1,200.00 by 2026-12-31, started 07-01, 300.00 saved (events 200 + 100)
 */
trait AnalyticsFixture
{
    protected User $user;

    /** @var array<string, Account> */
    protected array $acct = [];

    /** @var array<string, int> */
    protected array $ids  = [];

    protected TransactionCurrency $eur;
    protected TransactionCurrency $usd;

    protected function buildLedger(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00', config('app.timezone')));
        $this->user = $this->operatorUser();
        $this->eur  = TransactionCurrency::query()->where('code', 'EUR')->firstOrFail();
        $this->usd  = TransactionCurrency::query()->where('code', 'USD')->firstOrFail();
        $this->eur->enabled = true;
        $this->eur->save();
        $this->usd->enabled = true;
        $this->usd->save();
        $this->user->userGroup->currencies()->syncWithoutDetaching([$this->eur->id => ['group_default' => true], $this->usd->id => ['group_default' => false]]);

        $this->acct['checking'] = $this->account('Northbank checking 4021', 'Asset account', $this->eur);
        $this->acct['savings']  = $this->account('Meridian savings 7734', 'Asset account', $this->eur);
        $this->acct['usd']      = $this->account('Northbank USD 5512', 'Asset account', $this->usd);
        $this->acct['loan']     = $this->account('Meridian car loan', 'Loan', $this->eur);
        $this->acct['ib']       = $this->account('Initial balance for checking', 'Initial balance account', $this->eur);
        $this->acct['ibloan']   = $this->account('Initial balance for loan', 'Initial balance account', $this->eur);
        $this->acct['payroll']  = $this->account('Acme LLC payroll', 'Revenue account', null);
        $this->acct['gym']      = $this->account('Iron Gym', 'Expense account', null);
        $this->acct['grocer']   = $this->account('Fresh Grocer', 'Expense account', null);
        $this->acct['streamly'] = $this->account('Streamly', 'Expense account', null);
        $this->acct['cafe']     = $this->account('Corner Cafe', 'Expense account', null);

        $groceries     = Category::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => 'Groceries']);
        $subscriptions = Category::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => 'Subscriptions']);
        $dining        = Category::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => 'Dining']);
        $household     = Budget::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => 'Household', 'active' => true, 'order' => 1]);
        $vacation      = Tag::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'tag' => 'vacation', 'tag_mode' => 'nothing']);
        $gym           = Bill::create([
            'user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => 'Gym', 'match' => 'gym',
            'amount_min' => '30', 'amount_max' => '30', 'date' => Carbon::parse('2026-07-01'), 'repeat_freq' => 'monthly', 'skip' => 0,
            'automatch' => false, 'active' => true, 'transaction_currency_id' => $this->eur->id,
        ]);
        $this->ids += ['groceries' => $groceries->id, 'subscriptions' => $subscriptions->id, 'dining' => $dining->id, 'household' => $household->id, 'vacation' => $vacation->id, 'gym' => $gym->id];

        BudgetLimit::create(['budget_id' => $household->id, 'start_date' => Carbon::parse('2026-07-01'), 'end_date' => Carbon::parse('2026-07-31'), 'amount' => '250', 'transaction_currency_id' => $this->eur->id]);
        BudgetLimit::create(['budget_id' => $household->id, 'start_date' => Carbon::parse('2026-08-01'), 'end_date' => Carbon::parse('2026-08-31'), 'amount' => '100', 'transaction_currency_id' => $this->eur->id]);

        $a = $this->acct;
        $this->journal('Opening balance', '2026-06-30', '1000', $a['ib'], $a['checking'], $this->eur, 'Opening balance');
        $this->journal('Opening balance', '2026-06-30', '5000', $a['loan'], $a['ibloan'], $this->eur, 'Opening balance loan');
        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $d) {
            $this->journal('Deposit', $d, '3000', $a['payroll'], $a['checking'], $this->eur, 'Payroll');
        }
        $this->journal('Withdrawal', '2026-07-02', '30', $a['checking'], $a['gym'], $this->eur, 'Gym', bill: $gym->id);
        $this->journal('Withdrawal', '2026-08-03', '30', $a['checking'], $a['gym'], $this->eur, 'Gym', bill: $gym->id);
        foreach ([['2026-07-05', '120.40'], ['2026-07-19', '80.00'], ['2026-08-06', '110.10'], ['2026-09-04', '130.95']] as [$d, $amt]) {
            $this->journal('Withdrawal', $d, $amt, $a['checking'], $a['grocer'], $this->eur, 'Groceries', $groceries->id, $household->id);
        }
        foreach (['2026-07-10', '2026-08-10', '2026-09-10'] as $d) {
            $this->journal('Withdrawal', $d, '15.99', $a['checking'], $a['streamly'], $this->eur, 'Streamly', $subscriptions->id);
        }
        $this->journal('Withdrawal', '2026-07-12', '4.50', $a['checking'], $a['cafe'], $this->eur, 'Coffee');
        $this->journal('Transfer', '2026-07-15', '500', $a['checking'], $a['savings'], $this->eur, 'To savings');
        $this->journal('Withdrawal', '2026-07-20', '42', $a['usd'], $a['cafe'], $this->usd, 'Dinner', $dining->id);
        $this->journal('Withdrawal', '2026-09-15', '250', $a['checking'], $a['cafe'], $this->eur, 'Holiday dinners', tags: [$vacation->id]);

        $piggy = PiggyBank::create([
            'name' => 'Holiday fund', 'order' => 1, 'target_amount' => '1200', 'start_date' => Carbon::parse('2026-07-01'),
            'target_date' => Carbon::parse('2026-12-31'), 'transaction_currency_id' => $this->eur->id,
        ]);
        $piggy->accounts()->attach($a['savings']->id, ['current_amount' => '300']);
        PiggyBankEvent::create(['piggy_bank_id' => $piggy->id, 'date' => Carbon::parse('2026-07-16'), 'amount' => '200']);
        PiggyBankEvent::create(['piggy_bank_id' => $piggy->id, 'date' => Carbon::parse('2026-08-16'), 'amount' => '100']);
        $this->ids['piggy'] = $piggy->id;
    }

    protected function tearDownLedger(): void
    {
        Carbon::setTestNow();
    }

    private function account(string $name, string $type, ?TransactionCurrency $currency): Account
    {
        $account = Account::create([
            'user_id'         => $this->user->id,
            'user_group_id'   => $this->user->user_group_id,
            'account_type_id' => AccountType::query()->where('type', $type)->firstOrFail()->id,
            'name'            => $name,
            'active'          => true,
            'virtual_balance' => null,
        ]);
        if (null !== $currency) {
            AccountMeta::create(['account_id' => $account->id, 'name' => 'currency_id', 'data' => (string) $currency->id]);
        }
        if ('Asset account' === $type) {
            AccountMeta::create(['account_id' => $account->id, 'name' => 'account_role', 'data' => 'defaultAsset']);
        }

        return $account;
    }

    /** @param list<int> $tags */
    private function journal(string $type, string $date, string $amount, Account $from, Account $to, TransactionCurrency $currency, string $description, ?int $category = null, ?int $budget = null, ?int $bill = null, array $tags = []): TransactionJournal
    {
        $group   = TransactionGroup::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'title' => null]);
        $journal = TransactionJournal::create([
            'user_id'                 => $this->user->id,
            'user_group_id'           => $this->user->user_group_id,
            'transaction_type_id'     => TransactionType::query()->where('type', $type)->firstOrFail()->id,
            'transaction_group_id'    => $group->id,
            'bill_id'                 => $bill,
            'transaction_currency_id' => $currency->id,
            'description'             => $description,
            'completed'               => true,
            'order'                   => 0,
            'tag_count'               => count($tags),
            'date'                    => Carbon::parse($date.' 12:00:00', config('app.timezone')),
        ]);
        $journal->transaction_group_id = $group->id;
        $journal->save();
        Transaction::create(['account_id' => $from->id, 'transaction_journal_id' => $journal->id, 'amount' => '-'.$amount, 'transaction_currency_id' => $currency->id, 'reconciled' => false]);
        Transaction::create(['account_id' => $to->id, 'transaction_journal_id' => $journal->id, 'amount' => $amount, 'transaction_currency_id' => $currency->id, 'reconciled' => false]);
        if (null !== $category) {
            $journal->categories()->attach($category);
        }
        if (null !== $budget) {
            $journal->budgets()->attach($budget);
        }
        if ([] !== $tags) {
            $journal->tags()->attach($tags);
        }

        return $journal;
    }
}
