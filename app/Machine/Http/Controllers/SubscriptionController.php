<?php

/*
 * SubscriptionController.php
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

namespace FireflyIII\Machine\Http\Controllers;

use Carbon\Carbon;
use Closure;
use FireflyIII\Api\V1\Requests\Models\Bill\StoreRequest as BillStoreRequest;
use FireflyIII\Api\V1\Requests\Models\Bill\UpdateRequest as BillUpdateRequest;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Machine\Envelope;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Bill;
use FireflyIII\Models\Note;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleTrigger;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Support\Facades\Navigation;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\JsonApi\Enrichments\SubscriptionEnrichment;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * pm/apis.mdx §8.6 — subscriptions (Firefly's "bills").
 *
 * Reads go through BillRepository + SubscriptionEnrichment (the same pair upstream's
 * /api/v1/bills uses) so paid_dates, pay_dates and next_expected_match are Firefly's own
 * BillDateCalculator figures. /subscriptions/status classifies them per period: paid, unpaid
 * (a pay date has passed and nothing matched), expected (a pay date is still ahead), and
 * not_expected (nothing due in the period).
 *
 * Writes go through upstream's own FormRequest validation (Bill\StoreRequest / UpdateRequest)
 * and BillRepository::store() / update() / destroy() — one service call per route (R1) — inside
 * the write protocol, with a row-level before/after diff feeding the undo log.
 *
 * This class also carries the family's shared helpers (snapshot/diff, upstream form validation,
 * period resolution, transaction-group presentation) as public statics, used by
 * PiggyBankController and RecurrenceController.
 */
final class SubscriptionController extends MachineController
{
    public const array FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'half-year', 'yearly'];

    private const array RANGE_RULES = [
        'start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        'end'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
    ];

    // ------------------------------------------------------------------ reads ---

    /** GET /subscriptions — every subscription with its amounts, frequency, paid and expected dates in the range. */
    public function index(Request $request): JsonResponse
    {
        $args   = $this->input($request, self::RANGE_RULES + ['active' => ['sometimes', 'boolean']] + self::LIST_RULES, true);
        $params = $this->listParams($request, ['id', 'name', 'order', 'next_expected_match', 'amount_min', 'amount_max', 'repeat_freq', 'active'], 'name');
        [$start, $end, $resolved] = self::range($args);

        $bills  = $this->bills();
        if (array_key_exists('active', $args)) {
            $bills = $bills->filter(static fn (Bill $bill): bool => (bool) $bill->active === (bool) $args['active'])->values();
        }
        $rows   = $this->applyList($this->present($bills, $start, $end), $params);

        return $this->ok(['range' => self::rangeOut($start, $end, $resolved), 'subscriptions' => $rows]);
    }

    /** GET /subscriptions/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $args = $this->input($request, self::RANGE_RULES, true);
        [$start, $end, $resolved] = self::range($args);
        $bill = $this->findBill($id);

        return $this->ok(['range' => self::rangeOut($start, $end, $resolved), 'subscription' => $this->present(new Collection([$bill]), $start, $end)[0]]);
    }

    /** GET /subscriptions/{id}/transactions — the transactions linked to the subscription, newest first. */
    public function transactions(Request $request, string $id): JsonResponse
    {
        $args   = $this->input($request, self::RANGE_RULES + self::LIST_RULES, true);
        $params = $this->listParams($request, ['date', 'id'], '-date');
        [$start, $end] = self::optionalRange($args);
        $bill   = $this->findBill($id);

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->operator())->setUserGroup($this->administration())->setBill($bill)->withAPIInformation(); // the bound books, not the operator's own rows only
        if (null !== $start && null !== $end) {
            $collector->setRange($start, $end);
        }
        $groups = $this->applyList(self::presentGroups($collector->getGroups()), $params);

        return $this->ok([
            'subscription' => ['id' => (int) $bill->id, 'name' => (string) $bill->name],
            'range'        => null === $start ? null : ['start' => $start->format('Y-m-d'), 'end' => $end?->format('Y-m-d')],
            'transactions' => $groups,
        ]);
    }

    /**
     * GET /subscriptions/status — for the period, each active subscription is one of:
     *   paid          a matching transaction was seen and no pay date is left overdue
     *   unpaid        a pay date in the period has passed and nothing matched it (expected but not seen)
     *   expected      a pay date in the period is still ahead
     *   not_expected  nothing is due in the period
     * with per-currency totals (never summed across currencies, §14.1).
     */
    public function status(Request $request): JsonResponse
    {
        $args  = $this->input($request, self::RANGE_RULES, true);
        [$start, $end, $resolved] = self::range($args);
        $today = self::today()->format('Y-m-d');
        $all   = $this->bills();
        $bills = $all->filter(static fn (Bill $bill): bool => (bool) $bill->active)->values();
        $rows  = [];
        $totals = [];

        foreach ($this->present($bills, $start, $end) as $row) {
            $inRange   = array_values(array_filter($row['pay_dates'], static fn (string $d): bool => $d >= $start->format('Y-m-d') && $d <= $end->format('Y-m-d')));
            $due       = array_values(array_filter($inRange, static fn (string $d): bool => $d <= $today));
            $upcoming  = array_values(array_filter($inRange, static fn (string $d): bool => $d > $today));
            $paid      = $row['paid_dates'];
            $places    = (int) $row['currency_decimal_places'];
            $paidSum   = '0';
            $paidDate  = null;
            foreach ($paid as $entry) {
                if ($entry['currency_code'] === $row['currency_code']) {
                    $paidSum = Money::add($paidSum, $entry['amount']);
                }
                $paidDate = null === $paidDate || $entry['date'] > $paidDate ? $entry['date'] : $paidDate;
            }
            $status    = match (true) {
                [] !== $due      => 'unpaid',
                [] !== $paid     => 'paid',
                [] !== $upcoming => 'expected',
                default          => 'not_expected',
            };
            $expectedN = count($paid) + count($inRange);
            $avg       = $row['amount_avg'];
            $out       = [
                'id'              => $row['id'],
                'name'            => $row['name'],
                'status'          => $status,
                'currency_code'   => $row['currency_code'],
                'repeat_freq'     => $row['repeat_freq'],
                'amount_min'      => $row['amount_min'],
                'amount_max'      => $row['amount_max'],
                'amount_avg'      => $avg,
                'expected_count'  => $expectedN,
                'expected_amount' => Money::format(Money::mul($avg, (string) $expectedN), $places),
                'paid_count'      => count($paid),
                'paid_amount'     => Money::format($paidSum, $places),
                'paid_date'       => $paidDate,
                'paid'            => $paid,
                'unpaid_count'    => count($due),
                'unpaid_amount'   => Money::format(Money::mul($avg, (string) count($due)), $places),
                'unpaid_dates'    => $due,
                'upcoming_dates'  => $upcoming,
                'expected_date'   => $inRange[0] ?? null,
            ];
            $rows[]    = $out;

            $code      = $row['currency_code'];
            $totals[$code] ??= ['currency_code' => $code, 'places' => $places, 'subscriptions' => 0, 'paid' => 0, 'unpaid' => 0, 'expected' => 0, 'not_expected' => 0, 'expected_amount' => '0', 'paid_amount' => '0', 'unpaid_amount' => '0'];
            ++$totals[$code]['subscriptions'];
            ++$totals[$code][$status];
            $totals[$code]['expected_amount'] = Money::add($totals[$code]['expected_amount'], $out['expected_amount']);
            $totals[$code]['paid_amount']     = Money::add($totals[$code]['paid_amount'], $out['paid_amount']);
            $totals[$code]['unpaid_amount']   = Money::add($totals[$code]['unpaid_amount'], $out['unpaid_amount']);
        }
        $order = ['unpaid' => 0, 'expected' => 1, 'paid' => 2, 'not_expected' => 3];
        usort($rows, static fn (array $a, array $b): int => [$order[$a['status']], mb_strtolower($a['name']), $a['id']] <=> [$order[$b['status']], mb_strtolower($b['name']), $b['id']]);
        $totalRows = [];
        foreach ($totals as $t) {
            foreach (['expected_amount', 'paid_amount', 'unpaid_amount'] as $k) {
                $t[$k] = Money::format($t[$k], $t['places']);
            }
            unset($t['places']);
            $totalRows[] = $t;
        }

        return $this->ok([
            'range'             => self::rangeOut($start, $end, $resolved),
            'today'             => $today,
            'subscriptions'     => $rows,
            'totals'            => $totalRows,
            'counts'            => [
                'paid'         => count(array_filter($rows, static fn (array $r): bool => 'paid' === $r['status'])),
                'unpaid'       => count(array_filter($rows, static fn (array $r): bool => 'unpaid' === $r['status'])),
                'expected'     => count(array_filter($rows, static fn (array $r): bool => 'expected' === $r['status'])),
                'not_expected' => count(array_filter($rows, static fn (array $r): bool => 'not_expected' === $r['status'])),
                'inactive'     => $all->count() - $bills->count(),
            ],
        ]);
    }

    // ----------------------------------------------------------------- writes ---

    /** POST /subscriptions */
    public function store(Request $request): JsonResponse
    {
        $args = $this->input($request, [
            'name'           => ['required', 'string', 'min:1', 'max:255'],
            'amount_min'     => ['required'],
            'amount_max'     => ['required'],
            'date'           => ['required', 'date_format:Y-m-d'],
            'repeat_freq'    => ['required', 'string', 'in:'.implode(',', self::FREQUENCIES)],
            'skip'           => ['sometimes', 'integer', 'min:0', 'max:31'],
            'currency_code'  => ['sometimes', 'nullable', 'string', 'min:3', 'max:51'],
            'end_date'       => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'extension_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'active'         => ['sometimes', 'boolean'],
            'notes'          => ['sometimes', 'nullable', 'string', 'max:32768'],
        ]);
        $currency = $this->currencyOr($args['currency_code'] ?? null, $this->primaryCurrency());
        $data     = $this->billData($args, $currency, true);

        return $this->write($request, $args, function (bool $dryRun) use ($data): WriteResult {
            $form   = self::upstreamForm(BillStoreRequest::class, $data);
            $groupId = (int) $this->administration()->id;
            $specs  = $this->billSpecs($groupId, null);
            $before = self::snapshot($specs);
            /** @var Bill $bill */
            $bill   = $this->repository()->store($form->getAll());
            $result = new WriteResult();
            self::diffInto($result, $before, self::snapshot($specs));
            $result->count('created');
            [$start, $end] = self::range([]);

            return $result->with(['subscription' => $this->present(new Collection([$bill->refresh()]), $start, $end)[0]]);
        });
    }

    /** PUT /subscriptions/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $args = $this->input($request, [
            'name'           => ['sometimes', 'string', 'min:1', 'max:255'],
            'amount_min'     => ['sometimes'],
            'amount_max'     => ['sometimes'],
            'date'           => ['sometimes', 'date_format:Y-m-d'],
            'repeat_freq'    => ['sometimes', 'string', 'in:'.implode(',', self::FREQUENCIES)],
            'skip'           => ['sometimes', 'integer', 'min:0', 'max:31'],
            'currency_code'  => ['sometimes', 'string', 'min:3', 'max:51'],
            'end_date'       => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'extension_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'active'         => ['sometimes', 'boolean'],
            'notes'          => ['sometimes', 'nullable', 'string', 'max:32768'],
        ]);
        $control = array_keys(self::CONTROL_RULES);
        if ([] === array_diff(array_keys($args), $control)) {
            throw MachineException::invalid('Nothing to change.', 'Pass at least one of: name, amount_min, amount_max, date, repeat_freq, skip, currency_code, end_date, extension_date, active, notes');
        }
        $bill     = $this->findBill($id);
        $current  = $bill->transactionCurrency;
        $currency = $this->currencyOr($args['currency_code'] ?? null, $current);
        $data     = $this->billData($args, $currency, false);
        // Only send the currency when it changes: upstream's BillUpdateService touches the currency row whenever one is sent.
        if ((int) $currency->id === (int) $current->id) {
            unset($data['currency_code']);
        }
        if (array_key_exists('amount_min', $data) xor array_key_exists('amount_max', $data)) {
            // upstream compares min and max only when both are sent; send the stored partner so min > max is refused
            $data['amount_min'] ??= Money::format((string) $bill->amount_min, (int) $currency->decimal_places);
            $data['amount_max'] ??= Money::format((string) $bill->amount_max, (int) $currency->decimal_places);
        }
        $billId   = (int) $bill->id;

        return $this->write($request, $args, function (bool $dryRun) use ($data, $billId): WriteResult {
            /** @var Bill $bill */
            $bill    = Bill::query()->findOrFail($billId);
            $form    = self::upstreamForm(BillUpdateRequest::class, $data, ['bill' => $bill]);
            $specs   = $this->billSpecs((int) $this->administration()->id, $bill, $data['currency_code'] ?? null);
            $before  = self::snapshot($specs);
            $updated = $this->repository()->update($bill, $form->getAll());
            $result  = new WriteResult();
            self::diffInto($result, $before, self::snapshot($specs));
            $result->count([] === $result->touched ? 'unchanged' : 'updated');
            [$start, $end] = self::range([]);

            return $result->with(['subscription' => $this->present(new Collection([$updated->refresh()]), $start, $end)[0]]);
        });
    }

    /** DELETE /subscriptions/{id} — admin tier. Linked transactions stay; they simply lose the link. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $args = $this->input($request, []);
        $bill = $this->findBillOrGone($id);
        if (null === $bill) {
            return self::alreadyGone($args, ['subscription' => null], sprintf('Subscription #%s is already deleted — nothing to do.', trim(urldecode($id))));
        }
        $attachments = $bill->attachments()->count();
        if ($attachments > 0) {
            throw MachineException::conflict(
                sprintf('Subscription "%s" has %d attachment(s); deleting it would delete their files, which no dry run or undo can hold.', $bill->name, $attachments),
                'Remove the attachments in the Firefly III web interface first (or delete the subscription there)',
                ['subscription_id' => (int) $bill->id, 'attachments' => $attachments],
            );
        }
        $billId = (int) $bill->id;

        return $this->write($request, $args, function (bool $dryRun) use ($billId): WriteResult {
            /** @var Bill $bill */
            $bill    = Bill::query()->findOrFail($billId);
            $linked  = (int) DB::table('transaction_journals')->where('bill_id', $billId)->whereNull('deleted_at')->count();
            $summary = ['id' => $billId, 'name' => (string) $bill->name];
            $specs   = $this->billSpecs((int) $this->administration()->id, $bill);
            $before  = self::snapshot($specs);
            $this->repository()->destroy($bill);
            $result  = new WriteResult();
            self::diffInto($result, $before, self::snapshot($specs));

            return $result->count('deleted')->with(['subscription' => $summary, 'deleted' => 1, 'linked_transactions' => $linked]);
        });
    }

    // ------------------------------------------------------------- internals ---

    private function repository(): BillRepositoryInterface
    {
        /** @var BillRepositoryInterface $repository */
        $repository = app(BillRepositoryInterface::class);
        $repository->setUser($this->operator());

        return $repository;
    }

    /**
     * The administration's subscriptions (§4.9: the bound set of books, whoever entered them —
     * BillRepository::getBills() would return the operator's own rows only), in upstream's order.
     *
     * @return Collection<int, Bill>
     */
    private function bills(): Collection
    {
        return Bill::query()
            ->where('user_group_id', (int) $this->administration()->id)
            ->orderBy('order')
            ->orderBy('active', 'DESC')
            ->orderBy('name')
            ->get();
    }

    private function findBill(string $id): Bill
    {
        try {
            /** @var Bill */
            return $this->resolve(Bill::class, urldecode($id));
        } catch (MachineException $e) {
            // Firefly's model is "Bill"; the plane and the UI say "subscription". The message is
            // rebuilt from the details rather than rewritten, so a name like "water bill" is quoted as given.
            $details = $e->details;
            $name    = (string) ($details['name'] ?? trim(urldecode($id)));
            if ('not_found' === $e->errorCode) {
                throw MachineException::notFound(
                    isset($details['id']) ? sprintf('No subscription with id %s.', $details['id']) : sprintf('No subscription named "%s".', $name),
                    'GET /machine/v1/subscriptions lists them — pass an id or the exact name',
                    $details,
                );
            }
            if ('invalid_input' === $e->errorCode) {
                $candidates = (array) ($details['candidates'] ?? []);
                if ([] === $candidates) {
                    throw MachineException::invalid('An empty subscription name was given.', 'Pass the subscription\'s id or its exact name', $details);
                }

                throw MachineException::invalid(
                    sprintf('"%s" matches %d subscriptions%s.', $name, count($candidates), count($candidates) >= 10 ? ' or more' : ''),
                    'Pass the subscription\'s id instead — the candidates are in details.candidates',
                    $details,
                );
            }

            throw $e;
        }
    }

    /** For DELETE: null when a numeric id names a subscription that is already deleted (§5.6: deleted: 0, not 404). */
    private function findBillOrGone(string $id): ?Bill
    {
        $value = trim(urldecode($id));
        if (1 === preg_match('/^\d{1,19}$/', $value)) {
            $trashed = Bill::withTrashed()->where('id', $value)->where('user_group_id', $this->administration()->id)->first();
            if (null !== $trashed && $trashed->trashed()) {
                return null;
            }
        }

        return $this->findBill($value);
    }

    private function currencyOr(?string $code, TransactionCurrency $default): TransactionCurrency
    {
        if (null === $code || '' === trim($code)) {
            return $default;
        }

        return self::currencyByCode($code);
    }

    public static function currencyByCode(string $code): TransactionCurrency
    {
        /** @var null|TransactionCurrency $currency */
        $currency = TransactionCurrency::query()->where('code', strtoupper(trim($code)))->first();
        if (null === $currency) {
            throw MachineException::notFound(sprintf('No currency with code "%s".', $code), 'GET /machine/v1/currencies lists the codes this install knows', ['currency_code' => $code]);
        }

        return $currency;
    }

    /**
     * The plane's arguments as upstream's Bill request fields; amounts validated at the currency's places.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function billData(array $args, TransactionCurrency $currency, bool $creating): array
    {
        $places = (int) $currency->decimal_places;
        $data   = [];
        foreach (['name', 'date', 'repeat_freq', 'end_date', 'extension_date', 'notes'] as $field) {
            if (array_key_exists($field, $args)) {
                $data[$field] = $args[$field];
            }
        }
        foreach (['amount_min', 'amount_max'] as $field) {
            if (array_key_exists($field, $args)) {
                $data[$field] = Money::positive($args[$field], $places, $field, (string) $currency->code);
            }
        }
        if (array_key_exists('amount_min', $data) && array_key_exists('amount_max', $data) && 1 === Money::compare($data['amount_min'], $data['amount_max'])) {
            throw MachineException::invalid('amount_min is larger than amount_max.', sprintf('Swap them: amount_min "%s", amount_max "%s"', $data['amount_max'], $data['amount_min']), ['field' => 'amount_min']);
        }
        if (array_key_exists('skip', $args)) {
            $data['skip'] = (int) $args['skip'];
        }
        if (array_key_exists('active', $args)) {
            $data['active'] = (bool) $args['active'];
        }
        if ($creating) {
            $data['skip'] ??= 0;
            $data['active'] ??= true;
        }
        $data['currency_code'] = (string) $currency->code;
        foreach (['end_date', 'extension_date'] as $field) {
            if (array_key_exists($field, $data) && null === $data[$field]) {
                unset($data[$field]);
            }
        }
        if (array_key_exists('notes', $data) && null === $data['notes']) {
            $data['notes'] = '';
        }

        return $data;
    }

    /**
     * The rows a subscription write can change: the bill(s), their notes, and — when a name changes —
     * the rule triggers/actions upstream renames with it (UpdatesRulesForChangedBill).
     *
     * @return list<array{0: string, 1: string, 2: Closure(QueryBuilder): void}>
     */
    private function billSpecs(int $groupId, ?Bill $bill, ?string $currencyCode = null): array
    {
        $billScope = null === $bill
            ? static function (QueryBuilder $q) use ($groupId): void { $q->where('user_group_id', $groupId); }
            : static function (QueryBuilder $q) use ($bill): void { $q->where('id', $bill->id); };
        $specs     = [
            [Bill::class, 'bills', $billScope],
            [Note::class, 'notes', static function (QueryBuilder $q) use ($groupId, $bill): void {
                $q->where('noteable_type', Bill::class);
                if (null !== $bill) {
                    $q->where('noteable_id', $bill->id);

                    return;
                }
                $q->whereIn('noteable_id', DB::table('bills')->where('user_group_id', $groupId)->select('id'));
            }],
        ];
        if (null !== $bill) {
            // the rows are pinned by id NOW, so after upstream renames them (UpdatesRulesForChangedBill)
            // the after-image still finds them and records them as updated, with the old name to restore
            $name       = (string) $bill->name;
            $ruleIds    = DB::table('rules')->where('user_id', (int) $bill->user_id)->select('id');
            $triggerIds = DB::table('rule_triggers')->whereIn('trigger_type', ['bill_is', 'bill_ends', 'bill_starts', 'bill_contains'])->where('trigger_value', $name)->whereIn('rule_id', $ruleIds)->pluck('id')->all();
            $actionIds  = DB::table('rule_actions')->where('action_type', 'link_to_bill')->where('action_value', $name)->whereIn('rule_id', $ruleIds)->pluck('id')->all();
            $specs[]    = [RuleTrigger::class, 'rule_triggers', static function (QueryBuilder $q) use ($triggerIds): void { $q->whereIn('id', $triggerIds); }];
            $specs[]    = [RuleAction::class, 'rule_actions', static function (QueryBuilder $q) use ($actionIds): void { $q->whereIn('id', $actionIds); }];
        }
        if (null !== $currencyCode) {
            $specs[] = [TransactionCurrency::class, 'transaction_currencies', static function (QueryBuilder $q) use ($currencyCode): void { $q->where('code', $currencyCode); }];
        }

        return $specs;
    }

    /**
     * Firefly's enrichment, then the plane's shape: YYYY-MM-DD dates, amounts at the currency's places.
     *
     * @param Collection<int, Bill> $bills
     *
     * @return list<array<string, mixed>>
     */
    private function present(Collection $bills, Carbon $start, Carbon $end): array
    {
        if (0 === $bills->count()) {
            return [];
        }
        $enrichment = new SubscriptionEnrichment();
        $enrichment->setUser($this->operator());
        $enrichment->setStart(clone $start);
        $enrichment->setEnd(clone $end);
        $bills      = $enrichment->enrich($bills);
        $primary    = $this->primaryCurrency();
        $rows       = [];

        /** @var Bill $bill */
        foreach ($bills as $bill) {
            $currency = $bill->transactionCurrency;
            $places   = (int) $currency->decimal_places;
            $meta     = (array) $bill->meta;
            $min      = (string) $bill->amount_min;
            $max      = (string) $bill->amount_max;
            $avg      = (string) Money::div(Money::add($min, $max), '2');
            $pc       = (int) $currency->id === (int) $primary->id;
            $paid     = [];
            foreach ((array) ($meta['paid_dates'] ?? []) as $entry) {
                $paid[] = [
                    'date'                   => substr((string) $entry['date'], 0, 10),
                    'amount'                 => Money::format(Money::abs((string) $entry['amount']), (int) $entry['currency_decimal_places']),
                    'currency_code'          => (string) $entry['currency_code'],
                    'foreign_amount'         => null === ($entry['foreign_amount'] ?? null) ? null : Money::format(Money::abs((string) $entry['foreign_amount']), (int) ($entry['foreign_currency_decimal_places'] ?? 2)),
                    'foreign_currency_code'  => $entry['foreign_currency_code'] ?? null,
                    'transaction_group_id'   => (int) $entry['transaction_group_id'],
                    'transaction_journal_id' => (int) $entry['transaction_journal_id'],
                ];
            }
            usort($paid, static fn (array $a, array $b): int => [$a['date'], $a['transaction_journal_id']] <=> [$b['date'], $b['transaction_journal_id']]);
            $rows[]   = [
                'id'                       => (int) $bill->id,
                'name'                     => (string) $bill->name,
                'active'                   => (bool) $bill->active,
                'currency_code'            => (string) $currency->code,
                'currency_decimal_places'  => $places,
                'amount_min'               => Money::format($min, $places),
                'amount_max'               => Money::format($max, $places),
                'amount_avg'               => Money::format($avg, $places),
                'primary_currency_code'    => (string) $primary->code,
                'pc_amount_min'            => $pc ? Money::format($min, $places) : self::formatOrNull($bill->native_amount_min, (int) $primary->decimal_places),
                'pc_amount_max'            => $pc ? Money::format($max, $places) : self::formatOrNull($bill->native_amount_max, (int) $primary->decimal_places),
                'date'                     => $bill->date?->format('Y-m-d'),
                'end_date'                 => $bill->end_date?->format('Y-m-d'),
                'extension_date'           => $bill->extension_date?->format('Y-m-d'),
                'repeat_freq'              => (string) $bill->repeat_freq,
                'skip'                     => (int) $bill->skip,
                'order'                    => (int) $bill->order,
                'notes'                    => $meta['notes'] ?? null,
                'object_group_id'          => null === ($meta['object_group_id'] ?? null) ? null : (int) $meta['object_group_id'],
                'object_group_title'       => $meta['object_group_title'] ?? null,
                'paid_dates'               => $paid,
                'pay_dates'                => array_values(array_map(static fn ($d): string => substr((string) $d, 0, 10), (array) ($meta['pay_dates'] ?? []))),
                'next_expected_match'      => ($meta['nem'] ?? null) instanceof Carbon ? $meta['nem']->format('Y-m-d') : null,
                'next_expected_match_diff' => isset($meta['nem_diff']) ? (string) $meta['nem_diff'] : null,
                'updated_at'               => $bill->updated_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    private static function formatOrNull(mixed $amount, int $places): ?string
    {
        if (null === $amount || '' === (string) $amount) {
            return null;
        }

        return Money::format((string) $amount, $places);
    }

    // ------------------------------------------------ shared family helpers ---

    /**
     * §5.6: a DELETE of something already gone is ok with deleted: 0 — answered directly, with no
     * plan to confirm and no token to redeem, so the retry that re-sends a consumed token ends the
     * loop instead of conflicting. Nothing changes, so there is no operation-log row either.
     *
     * @param array<string, mixed> $args
     * @param array<string, null>  $entity the route's entity key, null
     */
    public static function alreadyGone(array $args, array $entity, string $note): JsonResponse
    {
        return Envelope::ok(array_merge($entity, [
            'deleted'      => 0,
            'dry_run'      => (bool) ($args['dry_run'] ?? true),
            'changes'      => ['deleted' => 0],
            'change_count' => 0,
            'note'         => $note,
        ]), self::standardMeta(request()));
    }

    public static function today(): Carbon
    {
        return today(config('app.timezone'));
    }

    /**
     * start/end from the arguments; both absent = the operator's current viewRange period (§14.3),
     * echoed with resolved: "viewRange:1M". One without the other is refused.
     *
     * @param array<string, mixed> $args
     *
     * @return array{0: Carbon, 1: Carbon, 2: null|string}
     */
    public static function range(array $args): array
    {
        $start = $args['start'] ?? null;
        $end   = $args['end'] ?? null;
        if (null === $start && null === $end) {
            $viewRange = (string) (Preferences::get('viewRange', '1M')->data ?? '1M');
            $today     = self::today();

            $start     = Navigation::startOfPeriod(clone $today, $viewRange)->startOfDay();

            return [$start, Navigation::endOfPeriod(clone $start, $viewRange)->startOfDay(), 'viewRange:'.$viewRange];
        }
        [$s, $e] = self::optionalRange($args);
        if (null === $s || null === $e) {
            throw MachineException::invalid('start and end go together.', 'Pass both start and end (YYYY-MM-DD), or neither for the current period');
        }

        return [$s, $e, null];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: null|Carbon, 1: null|Carbon}
     */
    public static function optionalRange(array $args): array
    {
        $tz    = (string) config('app.timezone');
        $start = isset($args['start']) ? Carbon::createFromFormat('!Y-m-d', (string) $args['start'], $tz) : null;
        $end   = isset($args['end']) ? Carbon::createFromFormat('!Y-m-d', (string) $args['end'], $tz) : null;
        if (($start instanceof Carbon) xor ($end instanceof Carbon)) {
            throw MachineException::invalid('start and end go together.', 'Pass both start and end (YYYY-MM-DD), or neither');
        }
        if ($start instanceof Carbon && $end instanceof Carbon && $end->lt($start)) {
            throw MachineException::invalid('end is before start.', sprintf('Swap them: start=%s end=%s', $end->format('Y-m-d'), $start->format('Y-m-d')), ['field' => 'end']);
        }

        return [$start ?: null, $end ?: null];
    }

    /** @return array<string, null|string> */
    public static function rangeOut(Carbon $start, Carbon $end, ?string $resolved): array
    {
        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'resolved_from' => $resolved];
    }

    /**
     * Run upstream's own /api/v1 FormRequest over $data — its rules, its after-validators and its
     * getAll() conversion — so the plane validates exactly as Firefly does (R1). A failure is
     * Laravel's ValidationException, which the plane renders as invalid_input with details.fields.
     *
     * @template T of FormRequest
     *
     * @param class-string<T>      $formClass
     * @param array<string, mixed> $data
     * @param array<string, mixed> $routeParams route-model parameters the request reads (bill, piggyBank, recurrence)
     *
     * @return T
     */
    public static function upstreamForm(string $formClass, array $data, array $routeParams = []): FormRequest
    {
        $base  = Request::create('/machine/v1/_upstream', 'POST', $data);
        /** @var T $form */
        $form  = $formClass::createFrom($base);
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $route = new Route('POST', '_upstream', []);
        $route->bind($base);
        foreach ($routeParams as $name => $value) {
            $route->setParameter($name, $value);
        }
        $form->setRouteResolver(static fn (): Route => $route);
        $form->setUserResolver(static fn () => auth()->user());
        $form->validateResolved();

        return $form;
    }

    /**
     * The raw rows of the given tables, keyed "class#id" — the before and after images of a write.
     *
     * @param list<array{0: string, 1: string, 2: Closure(QueryBuilder): void}> $specs [record class, table, scope]
     *
     * @return array<string, array{class: string, id: int|string, row: array<string, mixed>}>
     */
    public static function snapshot(array $specs): array
    {
        $out = [];
        foreach ($specs as [$class, $table, $scope]) {
            $query = DB::table($table);
            $scope($query);
            foreach ($query->orderBy('id')->get() as $row) {
                $row = (array) $row;
                $out[$class.'#'.$row['id']] = ['class' => $class, 'id' => $row['id'], 'row' => $row];
            }
        }

        return $out;
    }

    /**
     * Turn two snapshots into the WriteResult's touched list (the undo log's before-images):
     * appeared → created; vanished → deleted (before-image); changed → updated, or deleted when
     * deleted_at went from null to a value (a soft delete). Parents come first in $specs, so
     * undo — which walks the list backwards — removes children before their parents.
     *
     * @param array<string, array{class: string, id: int|string, row: array<string, mixed>}> $before
     * @param array<string, array{class: string, id: int|string, row: array<string, mixed>}> $after
     */
    public static function diffInto(WriteResult $result, array $before, array $after): void
    {
        foreach ($before as $key => $b) {
            if (!array_key_exists($key, $after)) {
                $result->touched[] = ['class' => $b['class'], 'id' => $b['id'], 'op' => 'deleted', 'before' => $b['row']];

                continue;
            }
            $a = $after[$key]['row'];
            if (!self::rowChanged($b['row'], $a)) {
                continue;
            }
            $softDeleted       = array_key_exists('deleted_at', $b['row']) && null === $b['row']['deleted_at'] && null !== ($a['deleted_at'] ?? null);
            $result->touched[] = ['class' => $b['class'], 'id' => $b['id'], 'op' => $softDeleted ? 'deleted' : 'updated', 'before' => $b['row']];
        }
        foreach ($after as $key => $a) {
            if (!array_key_exists($key, $before)) {
                $result->touched[] = ['class' => $a['class'], 'id' => $a['id'], 'op' => 'created', 'before' => null];
            }
        }
    }

    /**
     * Strict, column by column: PHP's loose `==` calls null and "" equal, null and 0 equal, and
     * "1.0" and "1" equal, so a real change could go unrecorded. Both rows come from the same
     * driver in the same request, so their types agree.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private static function rowChanged(array $before, array $after): bool
    {
        if (array_keys($before) !== array_keys($after)) {
            return true;
        }
        foreach ($before as $column => $value) {
            if ($value !== $after[$column]) {
                return true;
            }
        }

        return false;
    }

    /**
     * GroupCollector groups in the plane's transaction shape: group id + title, splits with
     * positive amounts at their currency's places (§14.1 — direction is the type).
     *
     * @param Collection<int, array<string, mixed>>|array<int, array<string, mixed>> $groups
     *
     * @return list<array<string, mixed>>
     */
    public static function presentGroups(array|Collection $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $splits = [];
            $date   = null;
            foreach ((array) $group['transactions'] as $t) {
                $d        = $t['date'] instanceof Carbon ? $t['date']->format('Y-m-d') : substr((string) $t['date'], 0, 10);
                $date     = null === $date || $d > $date ? $d : $date;
                $splits[] = [
                    'transaction_journal_id' => (int) $t['transaction_journal_id'],
                    'type'                   => strtolower((string) ($t['transaction_type_type'] ?? '')),
                    'date'                   => $d,
                    'description'            => (string) ($t['description'] ?? ''),
                    'amount'                 => Money::format(Money::abs((string) $t['amount']), (int) $t['currency_decimal_places']),
                    'currency_code'          => (string) $t['currency_code'],
                    'foreign_amount'         => null === ($t['foreign_amount'] ?? null) ? null : Money::format(Money::abs((string) $t['foreign_amount']), (int) ($t['foreign_currency_decimal_places'] ?? 2)),
                    'foreign_currency_code'  => $t['foreign_currency_code'] ?? null,
                    'source_id'              => isset($t['source_account_id']) ? (int) $t['source_account_id'] : null,
                    'source_name'            => $t['source_account_name'] ?? null,
                    'destination_id'         => isset($t['destination_account_id']) ? (int) $t['destination_account_id'] : null,
                    'destination_name'       => $t['destination_account_name'] ?? null,
                    'category_name'          => $t['category_name'] ?? null,
                    'budget_name'            => $t['budget_name'] ?? null,
                    'bill_id'                => isset($t['bill_id']) ? (int) $t['bill_id'] : null,
                    'bill_name'              => $t['bill_name'] ?? null,
                ];
            }
            usort($splits, static fn (array $a, array $b): int => $a['transaction_journal_id'] <=> $b['transaction_journal_id']);
            $out[]  = [
                'id'           => (int) $group['id'],
                'group_title'  => $group['title'] ?? null,
                'date'         => $date,
                'transactions' => $splits,
            ];
        }

        return $out;
    }
}
