<?php

/*
 * ReferenceController.php
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
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Events\Model\CurrencyExchangeRate\CreatedCurrencyExchangeRate;
use FireflyIII\Events\Model\CurrencyExchangeRate\DestroyedCurrencyExchangeRate;
use FireflyIII\Events\Model\CurrencyExchangeRate\UpdatedCurrencyExchangeRate;
use FireflyIII\Helpers\Attachments\AttachmentHelperInterface;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Account;
use FireflyIII\Models\Attachment;
use FireflyIII\Models\CurrencyExchangeRate;
use FireflyIII\Models\LinkType;
use FireflyIII\Models\Note;
use FireflyIII\Models\ObjectGroup;
use FireflyIII\Models\Preference;
use FireflyIII\Models\Tag;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\Webhook;
use FireflyIII\Models\WebhookMessage;
use FireflyIII\Repositories\Attachment\AttachmentRepositoryInterface;
use FireflyIII\Repositories\Currency\CurrencyRepositoryInterface;
use FireflyIII\Repositories\ExchangeRate\ExchangeRateRepositoryInterface;
use FireflyIII\Repositories\LinkType\LinkTypeRepositoryInterface;
use FireflyIII\Repositories\ObjectGroup\ObjectGroupRepositoryInterface;
use FireflyIII\Repositories\Tag\TagRepositoryInterface;
use FireflyIII\Repositories\Webhook\WebhookRepositoryInterface;
use FireflyIII\Rules\IsValidAttachmentModel;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\JsonApi\Enrichments\WebhookEnrichment;
use FireflyIII\Transformers\AttachmentTransformer;
use FireflyIII\Transformers\CurrencyTransformer;
use FireflyIII\Transformers\ExchangeRateTransformer;
use FireflyIII\Transformers\LinkTypeTransformer;
use FireflyIII\Transformers\ObjectGroupTransformer;
use FireflyIII\Transformers\TagTransformer;
use FireflyIII\Transformers\WebhookAttemptTransformer;
use FireflyIII\Transformers\WebhookMessageTransformer;
use FireflyIII\Transformers\WebhookTransformer;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * pm/apis.mdx §8.8 (reference data) and §8.10 (webhooks, read-only).
 *
 * Each route calls the Firefly repository the matching /api/v1 controller calls and renders with
 * Firefly's transformer (JSON:API wrapping and upstream-only links removed). Writes go through the
 * plane's write protocol; the rows they touch are recorded for POST /undo where Firefly keeps
 * them in a model (pivot tables — tag links, enabled currencies — are not, and the response says
 * when an undo cannot restore something).
 */
final class ReferenceController extends MachineController
{
    /** The preferences a program may set (apis.mdx §8.8) — never 2FA, e-mail or password ones. */
    public const array PREFERENCES = ['viewRange', 'listPageSize', 'language', 'locale', 'frontpageAccounts', 'fiscalYearStart', 'customFiscalYear'];

    // ------------------------------------------------------------------- tags ---

    /** GET /tags — search (tag contains). */
    public function tags(Request $request): JsonResponse
    {
        $args   = $this->input($request, ['search' => ['sometimes', 'nullable', 'string', 'max:1024']] + self::LIST_RULES, true);
        $params = $this->listParams($request, ['tag' => 'tag', 'date' => 'date', 'id' => 'id', 'created_at' => 'created_at'], 'tag');
        $query  = Tag::query()->where('user_group_id', $this->administration()->id);
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $query->whereRaw('LOWER(tag) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }
        $rows   = $this->applyList($query, $params)->map(fn (Tag $tag): array => $this->renderTag($tag))->all();
        $this->addMeta(['untrusted' => ['tag', 'description']]);

        return $this->ok(['tags' => $rows]);
    }

    /** GET /tags/{tag} — one tag, with what was spent and earned under it (start, end optional). */
    public function tag(Request $request, string $tag): JsonResponse
    {
        $args  = $this->input($request, ['start' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'end' => ['sometimes', 'nullable', 'date_format:Y-m-d']], true);
        [$start, $end] = $this->range($args);
        $model = $this->findTag($tag);
        $repo  = $this->tagRepository();
        $sums  = [];
        foreach ($repo->sumsOfTag($model, $start, null === $end ? null : $end->copy()->endOfDay()) as $currencyId => $row) {
            $currency = TransactionCurrency::withTrashed()->find((int) $currencyId);
            $places   = (int) ($row['currency_decimal_places'] ?? $currency?->decimal_places ?? 2);
            $fmt      = static fn (mixed $v): string => Money::format((string) $v, $places);
            $sums[]   = [
                'currency_code'           => $currency?->code,
                'currency_decimal_places' => $places,
                'spent'                   => $fmt($row['Withdrawal'] ?? '0'),
                'earned'                  => $fmt($row['Deposit'] ?? '0'),
                'transferred'             => $fmt($row['Transfer'] ?? '0'),
                'reconciliation'          => $fmt($row['Reconciliation'] ?? '0'),
                'opening_balance'         => $fmt($row['Opening balance'] ?? '0'),
            ];
        }
        usort($sums, static fn (array $a, array $b): int => strcmp((string) $a['currency_code'], (string) $b['currency_code']));
        $this->addMeta(['untrusted' => ['tag', 'description']]);

        return $this->ok([
            'tag'       => $this->renderTag($model),
            'range'     => ['start' => $start?->format('Y-m-d'), 'end' => $end?->format('Y-m-d')],
            'sums'      => $sums,
            'signed'    => ['spent'],
            'journals'  => count($repo->getJournalIds($model)),
            'first_use' => $repo->firstUseDate($model)?->format('Y-m-d'),
            'last_use'  => $repo->lastUseDate($model)?->format('Y-m-d'),
        ]);
    }

    /** POST /tags — tag, date, description. */
    public function storeTag(Request $request): JsonResponse
    {
        $args = $this->input($request, [
            'tag'         => ['required', 'string', 'min:1', 'max:1024', 'uniqueObjectForUser:tags,tag'],
            'date'        => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string', 'max:32768'],
        ]);

        return $this->write($request, $args, function (bool $dryRun) use ($args): WriteResult {
            $result = new WriteResult();
            $specs  = $this->tagSpecs();
            $before = RuleController::snapshotRows($specs);
            $tag    = $this->tagRepository()->store([
                'tag'         => $args['tag'],
                'date'        => isset($args['date']) ? Carbon::createFromFormat('Y-m-d', $args['date'])->startOfDay() : null,
                'description' => $args['description'] ?? null,
                'latitude'    => null,
                'longitude'   => null,
                'zoom_level'  => null,
            ]);
            RuleController::recordRowChanges($result, $specs, $before);

            return $result->count('created')->with(['tag' => $this->renderTag($tag->refresh())]);
        });
    }

    /** PUT /tags/{tag} — a rename is a rename: every journal keeps the tag. */
    public function updateTag(Request $request, string $tag): JsonResponse
    {
        $model = $this->findTag($tag);
        $args  = $this->input($request, [
            'tag'         => ['sometimes', 'string', 'min:1', 'max:1024', 'uniqueObjectForUser:tags,tag,'.$model->id],
            'date'        => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string', 'max:32768'],
        ]);
        if ([] === array_diff_key($args, self::CONTROL_RULES)) {
            throw MachineException::invalid('Nothing to change.', 'Pass at least one of tag, date, description');
        }

        return $this->write($request, $args, function (bool $dryRun) use ($model, $args): WriteResult {
            $result = new WriteResult();
            $specs  = $this->tagSpecs();
            $before = RuleController::snapshotRows($specs);
            $fresh  = Tag::query()->findOrFail($model->id);
            $data   = array_intersect_key($args, array_flip(['tag', 'description']));
            if (array_key_exists('date', $args)) {
                $data['date'] = null === $args['date'] ? null : Carbon::createFromFormat('Y-m-d', $args['date'])->startOfDay();
            }
            $this->tagRepository()->update($fresh, $data);
            RuleController::recordRowChanges($result, $specs, $before);

            return $result->count('updated', [] === $result->touched ? 0 : 1)->count('unchanged', [] === $result->touched ? 1 : 0)
                ->with(['tag' => $this->renderTag($fresh->refresh()), 'journals' => count($this->tagRepository()->getJournalIds($fresh))]);
        });
    }

    /** DELETE /tags/{tag} — admin. Firefly unlinks the tag from every journal and deletes its attachments. */
    public function destroyTag(Request $request, string $tag): JsonResponse
    {
        $args  = $this->input($request, []);
        $model = $this->findOrGone(fn (): Tag => $this->findTag($tag), $tag);

        return $this->write($request, $args, function (bool $dryRun) use ($model, $tag): WriteResult {
            $result = new WriteResult();
            $fresh  = null === $model ? null : Tag::query()->find($model->id);
            if (null === $fresh) {
                return $result->count('deleted', 0)->with(['deleted' => 0, 'tag' => $tag]);
            }
            $links       = DB::table('tag_transaction_journal')->where('tag_id', $fresh->id)->count();
            $attachments = $fresh->attachments()->count();
            $specs       = $this->tagSpecs();
            $before      = RuleController::snapshotRows($specs);
            $this->holdingFiles($dryRun, fn () => $this->tagRepository()->destroy($fresh));
            RuleController::recordRowChanges($result, $specs, $before);

            return $result->count('deleted')->count('journals_untagged', $links)->count('attachments_deleted', $attachments)->with([
                'deleted'             => 1,
                'tag'                 => $fresh->tag,
                'journals_untagged'   => $links,
                'attachments_deleted' => $attachments,
                'undo_note'           => 'POST /undo restores the tag itself, but not its links to journals or its attachment files — Firefly keeps those outside a model.',
            ]);
        });
    }

    // ---------------------------------------------------------- object groups ---

    /** GET /object-groups — the grouping for piggy banks and subscriptions. */
    public function objectGroups(Request $request): JsonResponse
    {
        $this->input($request, self::LIST_RULES, true);
        $params      = $this->listParams($request, ['order' => 'order', 'title' => 'title', 'id' => 'id'], 'order');
        $query       = ObjectGroup::query()->where('user_group_id', $this->administration()->id)->withCount(['piggyBanks', 'bills']);

        /** @var ObjectGroupTransformer $transformer */
        $transformer = app(ObjectGroupTransformer::class);
        $rows        = $this->applyList($query, $params)->map(static function (ObjectGroup $group) use ($transformer): array {
            $row                     = $transformer->transform($group);
            unset($row['links']);
            $row['piggy_bank_count'] = (int) $group->piggy_banks_count;
            $row['bill_count']       = (int) $group->bills_count;

            return $row;
        })->all();

        return $this->ok(['object_groups' => $rows]);
    }

    /** PUT /object-groups/{id} — title, order. */
    public function updateObjectGroup(Request $request, string $id): JsonResponse
    {
        $group = $this->findObjectGroup($id);
        $args  = $this->input($request, [
            'title' => ['sometimes', 'string', 'min:1', 'max:1024', 'uniqueObjectGroup:'.$group->id],
            'order' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        if ([] === array_diff_key($args, self::CONTROL_RULES)) {
            throw MachineException::invalid('Nothing to change.', 'Pass title, order, or both');
        }

        return $this->write($request, $args, function (bool $dryRun) use ($group, $args): WriteResult {
            $result = new WriteResult();
            $admin  = $this->administration()->id;
            $specs  = [[ObjectGroup::class, static fn (): EloquentBuilder => ObjectGroup::query()->where('user_group_id', $admin)]];
            $before = RuleController::snapshotRows($specs);
            $fresh  = ObjectGroup::query()->findOrFail($group->id);
            $repo   = $this->objectGroupRepository();
            $data   = array_intersect_key($args, array_flip(['title', 'order']));
            $repo->update($fresh, $data);
            $repo->resetOrder();
            RuleController::recordRowChanges($result, $specs, $before);
            $self   = 0;
            foreach ($result->touched as $t) {
                $self += (int) ((int) $t['id'] === $group->id);
            }

            return $result->count('updated', $self)->count('reordered', count($result->touched) - $self)->with(['object_group' => $this->renderObjectGroup($fresh->refresh())]);
        });
    }

    /** DELETE /object-groups/{id} — admin; the piggy banks and subscriptions in it are kept, ungrouped. */
    public function destroyObjectGroup(Request $request, string $id): JsonResponse
    {
        $args  = $this->input($request, []);
        $group = $this->findOrGone(fn (): ObjectGroup => $this->findObjectGroup($id), $id);

        return $this->write($request, $args, function (bool $dryRun) use ($group, $id): WriteResult {
            $result = new WriteResult();
            $fresh  = null === $group ? null : ObjectGroup::query()->find($group->id);
            if (null === $fresh) {
                return $result->count('deleted', 0)->with(['deleted' => 0, 'object_group_id' => $id]);
            }
            $members = $fresh->piggyBanks()->count() + $fresh->bills()->count();
            $admin   = $this->administration()->id;
            $specs   = [[ObjectGroup::class, static fn (): EloquentBuilder => ObjectGroup::query()->where('user_group_id', $admin)]];
            $before  = RuleController::snapshotRows($specs);
            $this->objectGroupRepository()->destroy($fresh);
            RuleController::recordRowChanges($result, $specs, $before);

            return $result->count('deleted')->with(['deleted' => 1, 'object_group_id' => (string) $fresh->id, 'title' => $fresh->title, 'members_ungrouped' => $members, 'undo_note' => 'POST /undo restores the group, not its membership.']);
        });
    }

    // ------------------------------------------------------------- currencies ---

    /** GET /currencies — every currency, enabled ones first, the primary one marked; enabled=true|false filters. */
    public function currencies(Request $request): JsonResponse
    {
        $args   = $this->input($request, ['enabled' => ['sometimes', 'nullable', 'boolean']] + self::LIST_RULES, true);
        $params = $this->listParams($request, ['default' => '_rank', 'code' => 'code', 'name' => 'name', 'id' => 'id'], 'default');
        $rows   = [];

        /** @var CurrencyTransformer $transformer */
        $transformer = app(CurrencyTransformer::class);
        $primaryId   = $this->primaryCurrency()->id;
        foreach ($this->currencyRepository()->getAll() as $currency) {
            // "primary" is Firefly's own answer (Amount::getPrimaryCurrencyByUserGroup), the same one
            // GET /currencies/primary and meta.primaryCurrency give — not only the pivot flag
            $currency->userGroupNative = $currency->id === $primaryId;
            if (array_key_exists('enabled', $args) && null !== $args['enabled'] && (bool) $args['enabled'] !== (bool) $currency->userGroupEnabled) {
                continue;
            }
            $row          = $transformer->transform($currency);
            unset($row['links']);
            $row['_rank'] = sprintf('%d-%d-%s', $currency->userGroupNative ? 0 : 1, $currency->userGroupEnabled ? 0 : 1, $currency->code);
            $rows[]       = $row;
        }
        $page   = $this->applyList($rows, $params);
        foreach ($page as $i => $row) {
            unset($page[$i]['_rank']);
        }

        return $this->ok(['currencies' => array_values($page)]);
    }

    /** GET /currencies/primary — the administration's primary currency. */
    public function primaryCurrencyRoute(Request $request): JsonResponse
    {
        $this->input($request, [], true);

        return $this->ok(['currency' => $this->renderCurrency($this->primaryCurrency())]);
    }

    /** POST /currencies/{code}/enable */
    public function enableCurrency(Request $request, string $code): JsonResponse
    {
        return $this->toggleCurrency($request, $code, true);
    }

    /** POST /currencies/{code}/disable — refused while the currency is in use or primary. */
    public function disableCurrency(Request $request, string $code): JsonResponse
    {
        return $this->toggleCurrency($request, $code, false);
    }

    private function toggleCurrency(Request $request, string $code, bool $enable): JsonResponse
    {
        $args     = $this->input($request, []);
        $currency = $this->findCurrency($code);

        return $this->write($request, $args, function (bool $dryRun) use ($currency, $enable): WriteResult {
            $result  = new WriteResult();
            $repo    = $this->currencyRepository();
            $fresh   = TransactionCurrency::query()->findOrFail($currency->id);
            $fresh->refreshForUser($this->operator());
            $enabled = (bool) $fresh->userGroupEnabled;
            if ($enabled === $enable) {
                return $result->count('unchanged')->with(['currency' => $this->renderCurrency($fresh)]);
            }
            if (!$enable) {
                if ($fresh->userGroupNative) {
                    throw MachineException::conflict(sprintf('%s is the primary currency and cannot be disabled.', $fresh->code), 'Change the primary currency first (PUT /machine/v1/administrations/{id} with primary_currency_code)', ['code' => $fresh->code]);
                }
                $where = $repo->currencyInUseAt($fresh);
                if (null !== $where) {
                    throw MachineException::conflict(sprintf('%s is in use and cannot be disabled.', $fresh->code), 'Firefly refuses to disable a currency that is still used — move or delete what uses it first', ['code' => $fresh->code, 'in_use_by' => $where]);
                }
                if (1 === $repo->get()->count()) {
                    throw MachineException::conflict('This is the last enabled currency.', 'Enable another currency first', ['code' => $fresh->code]);
                }
                $repo->disable($fresh);
            }
            if ($enable) {
                $repo->enable($fresh);
            }
            $fresh->refreshForUser($this->operator());
            $result->count($enable ? 'enabled' : 'disabled');
            $result->basis = [$fresh->code, $enable];

            return $result->with([
                'currency'   => $this->renderCurrency($fresh),
                'reversible' => false,
                'undo_note'  => sprintf('POST /machine/v1/currencies/%s/%s reverses this — Firefly keeps enabled currencies outside a model, so POST /undo cannot.', $fresh->code, $enable ? 'disable' : 'enable'),
            ]);
        });
    }

    // --------------------------------------------------------- exchange rates ---

    /** GET /exchange-rates — from, to (codes), start, end. */
    public function exchangeRates(Request $request): JsonResponse
    {
        $args   = $this->input($request, [
            'from'  => ['sometimes', 'nullable', 'string', 'max:51'],
            'to'    => ['sometimes', 'nullable', 'string', 'max:51'],
            'start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ] + self::LIST_RULES, true);
        [$start, $end] = $this->range($args);
        $params = $this->listParams($request, ['date' => 'date', 'id' => 'id', 'rate' => 'rate'], '-date');
        $query  = CurrencyExchangeRate::query()->where('user_group_id', $this->administration()->id)->with(['fromCurrency', 'toCurrency']);
        if (isset($args['from']) && '' !== $args['from']) {
            $query->where('from_currency_id', $this->findCurrency((string) $args['from'])->id);
        }
        if (isset($args['to']) && '' !== $args['to']) {
            $query->where('to_currency_id', $this->findCurrency((string) $args['to'])->id);
        }
        if (null !== $start) {
            $query->where('date', '>=', $start->format('Y-m-d 00:00:00'));
        }
        if (null !== $end) {
            $query->where('date', '<=', $end->format('Y-m-d 23:59:59'));
        }
        $rows   = $this->applyList($query, $params)->map(fn (CurrencyExchangeRate $rate): array => $this->renderRate($rate))->all();

        return $this->ok(['exchange_rates' => $rows, 'range' => ['start' => $start?->format('Y-m-d'), 'end' => $end?->format('Y-m-d')]]);
    }

    /** POST /exchange-rates — from, to, date, rate (a decimal STRING). An existing rate on that date is updated, as upstream does. */
    public function storeExchangeRate(Request $request): JsonResponse
    {
        $args = $this->input($request, [
            'from' => ['required', 'string', 'max:51'],
            'to'   => ['required', 'string', 'max:51'],
            'date' => ['required', 'date_format:Y-m-d'],
            'rate' => ['required'],
        ]);
        if (!is_string($args['rate']) || !Money::isDecimal($args['rate']) || !Money::isPositive($args['rate'])) {
            throw MachineException::invalid('rate must be a positive decimal STRING, like "1.0842".', 'Send "rate": "1.0842" — a JSON number would be a float to the parser (apis.mdx §14.1)', ['field' => 'rate']);
        }
        if (Money::places($args['rate']) > 12) {
            throw MachineException::invalid('rate has more than 12 decimal places.', 'Firefly stores 12 places; trim the rate rather than have it rounded silently', ['field' => 'rate']);
        }
        $from = $this->findCurrency($args['from']);
        $to   = $this->findCurrency($args['to']);
        if ($from->id === $to->id) {
            throw MachineException::invalid('from and to are the same currency.', 'An exchange rate converts between two different currencies');
        }
        $date = Carbon::createFromFormat('Y-m-d', $args['date'])->startOfDay();

        return $this->write($request, $args, function (bool $dryRun) use ($from, $to, $date, $args): WriteResult {
            $result   = new WriteResult();
            $admin    = $this->administration()->id;
            $specs    = [[CurrencyExchangeRate::class, static fn (): EloquentBuilder => CurrencyExchangeRate::query()->where('user_group_id', $admin)->where('from_currency_id', $from->id)->where('to_currency_id', $to->id)]];
            $before   = RuleController::snapshotRows($specs);
            $repo     = $this->exchangeRateRepository();
            // Firefly's lookup compares the stored datetime to a bare date, which never matches on
            // SQLite (the date column holds "Y-m-d H:i:s" there); whereDate() finds it on every driver.
            $existing = $repo->getSpecificRateOnDate($from, $to, $date->copy())
                ?? CurrencyExchangeRate::query()->where('user_group_id', $admin)->where('from_currency_id', $from->id)->where('to_currency_id', $to->id)->whereDate('date', $date->format('Y-m-d'))->orderBy('id')->first();
            if (null !== $existing && 0 === Money::compare((string) $existing->rate, (string) $args['rate'])) {
                return $result->count('unchanged')->with(['exchange_rate' => $this->renderRate($existing)]);
            }
            if (null !== $existing) {
                $rate = $repo->updateExchangeRate($existing, (string) $args['rate'], $date->copy());
                event(new UpdatedCurrencyExchangeRate($rate));
                $result->count('updated');
            }
            if (null === $existing) {
                $rate = $repo->storeExchangeRate($from, $to, (string) $args['rate'], $date->copy());
                event(new CreatedCurrencyExchangeRate($rate));
                $result->count('created');
            }
            RuleController::recordRowChanges($result, $specs, $before);

            return $result->with(['exchange_rate' => $this->renderRate($rate->refresh())]);
        });
    }

    /** DELETE /exchange-rates/{id} — admin. */
    public function destroyExchangeRate(Request $request, string $id): JsonResponse
    {
        $args = $this->input($request, []);
        if (1 !== preg_match('/^\d{1,19}$/', $id)) {
            throw MachineException::invalid('An exchange rate is addressed by its id.', 'GET /machine/v1/exchange-rates lists them with their ids');
        }

        return $this->write($request, $args, function (bool $dryRun) use ($id): WriteResult {
            $result = new WriteResult();
            $rate   = CurrencyExchangeRate::query()->where('user_group_id', $this->administration()->id)->find((int) $id);
            if (null === $rate) {
                return $result->count('deleted', 0)->with(['deleted' => 0, 'exchange_rate_id' => $id]);
            }
            $admin  = $this->administration();
            $specs  = [[CurrencyExchangeRate::class, static fn (): EloquentBuilder => CurrencyExchangeRate::query()->where('id', (int) $id)]];
            $before = RuleController::snapshotRows($specs);
            $render = $this->renderRate($rate);
            $from   = $rate->fromCurrency;
            $to     = $rate->toCurrency;
            $date   = $rate->date;
            $this->exchangeRateRepository()->deleteRate($rate);
            event(new DestroyedCurrencyExchangeRate($from, $to, $admin, $date));
            RuleController::recordRowChanges($result, $specs, $before);

            return $result->count('deleted')->with(['deleted' => 1, 'exchange_rate' => $render]);
        });
    }

    // ------------------------------------------------------------- link types ---

    /** GET /link-types */
    public function linkTypes(Request $request): JsonResponse
    {
        $this->input($request, self::LIST_RULES, true);
        $params = $this->listParams($request, ['name' => 'name', 'id' => 'id'], 'name');
        $repo   = $this->linkTypeRepository();
        $rows   = $this->applyList(LinkType::query(), $params)->map(function (LinkType $type) use ($repo): array {
            $row                  = $this->renderLinkType($type);
            $row['journal_count'] = $repo->countJournals($type);

            return $row;
        })->all();

        return $this->ok(['link_types' => $rows]);
    }

    /** POST /link-types — name, inward, outward. */
    public function storeLinkType(Request $request): JsonResponse
    {
        $args = $this->input($request, [
            'name'    => ['required', 'string', 'min:1', 'max:1024', 'unique:link_types,name'],
            'inward'  => ['required', 'string', 'min:1', 'max:1024', 'unique:link_types,inward', 'different:outward'],
            'outward' => ['required', 'string', 'min:1', 'max:1024', 'unique:link_types,outward', 'different:inward'],
        ]);

        return $this->write($request, $args, function (bool $dryRun) use ($args): WriteResult {
            $result = new WriteResult();
            $type   = $this->linkTypeRepository()->store(['name' => $args['name'], 'inward' => $args['inward'], 'outward' => $args['outward']]);
            $result->created($type);

            return $result->count('created')->with(['link_type' => $this->renderLinkType($type->refresh())]);
        });
    }

    /** PUT /link-types/{id} — name, inward, outward; Firefly's own link types are not editable. */
    public function updateLinkType(Request $request, string $id): JsonResponse
    {
        $type = $this->findLinkType($id);
        $args = $this->input($request, [
            'name'    => ['sometimes', 'string', 'min:1', 'max:1024', 'unique:link_types,name,'.$type->id],
            'inward'  => ['sometimes', 'string', 'min:1', 'max:1024', 'unique:link_types,inward,'.$type->id],
            'outward' => ['sometimes', 'string', 'min:1', 'max:1024', 'unique:link_types,outward,'.$type->id],
        ]);
        $this->assertEditable($type);
        if ([] === array_diff_key($args, self::CONTROL_RULES)) {
            throw MachineException::invalid('Nothing to change.', 'Pass at least one of name, inward, outward');
        }

        return $this->write($request, $args, function (bool $dryRun) use ($type, $args): WriteResult {
            $result = new WriteResult();
            $fresh  = LinkType::query()->findOrFail($type->id);
            $result->updating($fresh);
            $this->linkTypeRepository()->update($fresh, array_intersect_key($args, array_flip(['name', 'inward', 'outward'])));

            return $result->count('updated')->with(['link_type' => $this->renderLinkType($fresh->refresh())]);
        });
    }

    /** DELETE /link-types/{id} — admin. */
    public function destroyLinkType(Request $request, string $id): JsonResponse
    {
        $args = $this->input($request, []);
        $type = $this->findOrGone(fn (): LinkType => $this->findLinkType($id), $id);
        if (null !== $type) {
            $this->assertEditable($type);
        }

        return $this->write($request, $args, function (bool $dryRun) use ($type, $id): WriteResult {
            $result = new WriteResult();
            $fresh  = null === $type ? null : LinkType::query()->find($type->id);
            if (null === $fresh) {
                return $result->count('deleted', 0)->with(['deleted' => 0, 'link_type_id' => $id]);
            }
            $links  = $this->linkTypeRepository()->countJournals($fresh);
            $result->deleting($fresh);
            $this->linkTypeRepository()->destroy($fresh);

            return $result->count('deleted')->with(['deleted' => 1, 'link_type' => $this->renderLinkType($fresh), 'links_using_it' => $links]);
        });
    }

    // ------------------------------------------------------------ attachments ---

    /** GET /attachments — attachable_type, attachable_id. */
    public function attachments(Request $request): JsonResponse
    {
        $args   = $this->input($request, [
            'attachable_type' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', $this->attachableTypes())],
            'attachable_id'   => ['sometimes', 'nullable', 'integer', 'min:1'],
        ] + self::LIST_RULES, true);
        if (isset($args['attachable_id']) && !isset($args['attachable_type'])) {
            throw MachineException::invalid('attachable_id needs attachable_type.', sprintf('Pass attachable_type too — one of %s', implode(', ', $this->attachableTypes())));
        }
        $params = $this->listParams($request, ['id' => 'id', 'created_at' => 'created_at', 'filename' => 'filename', 'size' => 'size'], '-created_at');
        $query  = $this->attachmentQuery();
        if (isset($args['attachable_type'])) {
            $query->where('attachable_type', 'FireflyIII\Models\\'.$args['attachable_type']);
        }
        if (isset($args['attachable_id'])) {
            $query->where('attachable_id', (int) $args['attachable_id']);
        }
        $rows   = $this->applyList($query, $params)->map(fn (Attachment $a): array => $this->renderAttachment($a))->all();
        $this->addMeta(['untrusted' => ['filename', 'title', 'notes']]);

        return $this->ok(['attachments' => $rows]);
    }

    /** GET /attachments/{id} — metadata only. */
    public function attachment(Request $request, string $id): JsonResponse
    {
        $this->input($request, [], true);
        $this->addMeta(['untrusted' => ['filename', 'title', 'notes']]);

        return $this->ok(['attachment' => $this->renderAttachment($this->findAttachment($id))]);
    }

    /**
     * GET /attachments/{id}/download — the one read route whose body is NOT the envelope: the
     * file itself (decrypted by Firefly's repository), with Content-Disposition. Errors before
     * the stream starts are still the envelope.
     */
    public function download(Request $request, string $id): Response
    {
        $this->input($request, [], true);
        $attachment = $this->findAttachment($id);
        $repo       = $this->attachmentRepository();
        if (false === (bool) $attachment->uploaded || 0 === (int) $attachment->size || !$repo->exists($attachment)) {
            throw MachineException::notFound(sprintf('Attachment #%d has no file (it was never uploaded, or the file is gone).', $attachment->id), 'GET /machine/v1/attachments/'.$attachment->id.' shows its metadata', ['id' => $attachment->id]);
        }
        $content    = $repo->getContent($attachment);
        $name       = str_replace(['"', '\\', "\r", "\n"], '_', basename((string) $attachment->filename));

        return response($content, 200, [
            'Content-Type'             => '' === (string) $attachment->mime ? 'application/octet-stream' : (string) $attachment->mime,
            'Content-Disposition'      => sprintf('attachment; filename="%s"', $name),
            'Content-Length'           => (string) strlen($content),
            'X-Content-Type-Options'   => 'nosniff',
            'X-Firefly-Machine-Body'   => 'file',
        ]);
    }

    /**
     * POST /attachments — attachable_type, attachable_id, filename, title, notes and the file as
     * content_base64. The dry run creates the metadata row (rolled back) and inspects the bytes;
     * only the real write puts a file on the attachment disk.
     */
    public function storeAttachment(Request $request): JsonResponse
    {
        $type = (string) ($this->requestBody($request)['attachable_type'] ?? '');
        $type = in_array($type, $this->attachableTypes(), true) ? $type : 'TransactionJournal'; // a bad type is refused by its own rule
        $args = $this->input($request, [
            'attachable_type' => ['required', 'string', 'in:'.implode(',', $this->attachableTypes())],
            'attachable_id'   => ['required', 'bail', 'integer', 'min:1', new IsValidAttachmentModel($type)],
            'filename'        => ['required', 'string', 'min:1', 'max:255'],
            'title'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes'           => ['sometimes', 'nullable', 'string', 'max:32768'],
            'content_base64'  => ['required', 'string'],
        ]);
        $bytes = base64_decode((string) $args['content_base64'], true);
        if (false === $bytes || '' === $bytes) {
            throw MachineException::invalid('content_base64 is not valid base64, or it is empty.', 'Send the file\'s bytes base64-encoded (the whole body is capped at 8 MiB)', ['field' => 'content_base64']);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = false === $finfo ? '' : (string) finfo_buffer($finfo, $bytes);
        if (!in_array($mime, (array) config('firefly.allowedMimes'), true)) {
            throw MachineException::invalid(sprintf('Firefly does not accept files of type %s.', '' === $mime ? 'unknown' : $mime), 'Upload a PDF, an image, a text or CSV file, or an office document', ['mime' => $mime]);
        }

        return $this->write($request, $args, function (bool $dryRun) use ($args, $bytes, $mime): WriteResult {
            $result     = new WriteResult();
            $userId     = $this->operator()->id;
            $specs      = [
                [Attachment::class, static fn (): EloquentBuilder => Attachment::query()->where('user_id', $userId)],
                [Note::class, static fn (): EloquentBuilder => Note::query()->where('noteable_type', Attachment::class)->whereIn('noteable_id', Attachment::withTrashed()->where('user_id', $userId)->select('id'))],
            ];
            $before     = RuleController::snapshotRows($specs);
            $attachment = $this->attachmentRepository()->store([
                'filename'        => $args['filename'],
                'title'           => (string) ($args['title'] ?? ''),
                'notes'           => (string) ($args['notes'] ?? ''),
                'attachable_type' => $args['attachable_type'],
                'attachable_id'   => (int) $args['attachable_id'],
            ]);
            if (!$dryRun) {
                /** @var AttachmentHelperInterface $helper */
                $helper = app(AttachmentHelperInterface::class);
                if (false === $helper->saveAttachmentFromApi($attachment, $bytes)) {
                    throw MachineException::upstream('Firefly could not store the file.', 'Check the attachment disk (storage/upload) is writable; the app log has the reason');
                }
            }
            RuleController::recordRowChanges($result, $specs, $before);

            return $result->count('created')->with([
                'attachment' => $this->renderAttachment($attachment->refresh()),
                'file'       => ['bytes' => strlen($bytes), 'mime' => $mime, 'md5' => md5($bytes), 'written' => !$dryRun],
                'undo_note'  => 'POST /undo removes the attachment record; the stored file stays on the attachment disk.',
            ]);
        });
    }

    /** DELETE /attachments/{id} — admin; Firefly deletes the stored file too. */
    public function destroyAttachment(Request $request, string $id): JsonResponse
    {
        $args       = $this->input($request, []);
        $attachment = $this->findOrGone(fn (): Attachment => $this->findAttachment($id), $id);

        return $this->write($request, $args, function (bool $dryRun) use ($attachment, $id): WriteResult {
            $result = new WriteResult();
            $fresh  = null === $attachment ? null : Attachment::query()->find($attachment->id);
            if (null === $fresh) {
                return $result->count('deleted', 0)->with(['deleted' => 0, 'attachment_id' => $id]);
            }
            $render = $this->renderAttachment($fresh);
            $specs  = [
                [Attachment::class, static fn (): EloquentBuilder => Attachment::query()->where('id', $fresh->id)],
                [Note::class, static fn (): EloquentBuilder => Note::query()->where('noteable_type', Attachment::class)->where('noteable_id', $fresh->id)],
            ];
            $before = RuleController::snapshotRows($specs);
            $this->holdingFiles($dryRun, fn () => $this->attachmentRepository()->destroy($fresh));
            RuleController::recordRowChanges($result, $specs, $before);

            return $result->count('deleted')->with(['deleted' => 1, 'attachment' => $render, 'file_removed' => true, 'undo_note' => 'POST /undo restores the attachment record, not the deleted file.']);
        });
    }

    // ------------------------------------------------------------ preferences ---

    /** GET /preferences — the allowlisted preferences, stored value or Firefly's default. */
    public function preferences(Request $request): JsonResponse
    {
        $this->input($request, [], true);
        $user = $this->operator();
        $rows = [];
        foreach (self::PREFERENCES as $name) {
            // no default passed: Firefly's getForUser() would otherwise STORE the default (R8)
            $pref   = Preference::query()->where('user_id', $user->id)->where('name', $name)->first();
            $rows[] = [
                'name'       => $name,
                'value'      => null === $pref || null === $pref->data ? $this->preferenceDefault($name) : $pref->data,
                'stored'     => null !== $pref && null !== $pref->data,
                'writable'   => true,
                'updated_at' => $pref?->updated_at?->toAtomString(),
            ];
        }

        return $this->ok(['preferences' => $rows, 'writable' => self::PREFERENCES]);
    }

    /** PUT /preferences/{name} — value; allowlisted names only. */
    public function setPreference(Request $request, string $name): JsonResponse
    {
        if (!in_array($name, self::PREFERENCES, true)) {
            throw MachineException::forbidden(sprintf('The preference "%s" cannot be set through the plane.', mb_substr($name, 0, 64)), sprintf('Settable preferences: %s', implode(', ', self::PREFERENCES)), ['allowed' => self::PREFERENCES]);
        }
        $args  = $this->input($request, ['value' => ['present']]);
        $value = $this->preferenceValue($name, $args['value']);

        return $this->write($request, $args, function (bool $dryRun) use ($name, $value): WriteResult {
            $result  = new WriteResult();
            $user    = $this->operator();
            $specs   = [[Preference::class, static fn (): EloquentBuilder => Preference::query()->where('user_id', $user->id)->where('name', $name)]];
            $before  = RuleController::snapshotRows($specs);
            $current = Preference::query()->where('user_id', $user->id)->where('name', $name)->first();
            if (null !== $current && $current->data === $value) {
                return $result->count('unchanged')->with(['preference' => ['name' => $name, 'value' => $value]]);
            }
            Preferences::setForUser($user, $name, $value);
            Cache::forget(sprintf('preference%s%s', $user->id, $name));
            RuleController::recordRowChanges($result, $specs, $before);
            $result->count(null === $current ? 'created' : 'updated');

            return $result->with(['preference' => ['name' => $name, 'value' => $value, 'previous' => $current?->data]]);
        });
    }

    // --------------------------------------------------------------- webhooks ---

    /** GET /webhooks — read-only; the signing secret is never returned (§16.2). */
    public function webhooks(Request $request): JsonResponse
    {
        $this->input($request, self::LIST_RULES, true);
        $params     = $this->listParams($request, ['id' => 'id', 'title' => 'title'], 'id');
        $repo       = $this->webhookRepository();
        $enrichment = new WebhookEnrichment();
        $enrichment->setUser($this->operator());
        $webhooks   = $enrichment->enrich($repo->all());

        /** @var WebhookTransformer $transformer */
        $transformer = app(WebhookTransformer::class);
        $rows        = [];
        foreach ($webhooks as $webhook) {
            $row = $transformer->transform($webhook);
            unset($row['secret'], $row['links']);
            $rows[] = $row;
        }

        return $this->ok(['webhooks' => $this->applyList($rows, $params), 'webhooks_enabled' => $this->webhooksEnabled()]);
    }

    /** GET /webhooks/{id}/messages */
    public function webhookMessages(Request $request, string $id): JsonResponse
    {
        $this->input($request, self::LIST_RULES, true);
        $params  = $this->listParams($request, ['id' => 'id', 'created_at' => 'created_at'], '-id');
        $webhook = $this->findWebhook($id);

        /** @var WebhookMessageTransformer $transformer */
        $transformer = app(WebhookMessageTransformer::class);
        $rows        = $this->applyList(WebhookMessage::query()->where('webhook_id', $webhook->id), $params)
            ->map(static function (WebhookMessage $m) use ($transformer): array {
                $row = $transformer->transform($m);
                unset($row['links']);

                return $row;
            })->all();
        $this->addMeta(['untrusted' => ['message']]);

        return $this->ok(['webhook_id' => (string) $webhook->id, 'messages' => $rows, 'webhooks_enabled' => $this->webhooksEnabled()]);
    }

    /** GET /webhooks/{id}/messages/{message_id}/attempts */
    public function webhookAttempts(Request $request, string $id, string $message_id): JsonResponse
    {
        $this->input($request, self::LIST_RULES, true);
        $params  = $this->listParams($request, ['id' => 'id', 'created_at' => 'created_at'], '-id');
        $webhook = $this->findWebhook($id);
        $message = 1 === preg_match('/^\d{1,19}$/', $message_id) ? WebhookMessage::query()->where('webhook_id', $webhook->id)->find((int) $message_id) : null;
        if (null === $message) {
            throw MachineException::notFound(sprintf('Webhook #%d has no message %s.', $webhook->id, mb_substr($message_id, 0, 32)), sprintf('GET /machine/v1/webhooks/%d/messages lists its messages', $webhook->id), ['webhook_id' => $webhook->id, 'message_id' => $message_id]);
        }

        /** @var WebhookAttemptTransformer $transformer */
        $transformer = app(WebhookAttemptTransformer::class);
        $rows        = $this->applyList($message->webhookAttempts(), $params)->map(static function ($a) use ($transformer): array {
            $row = $transformer->transform($a);
            unset($row['links']);

            return $row;
        })->all();
        $this->addMeta(['untrusted' => ['logs', 'response']]);

        return $this->ok(['webhook_id' => (string) $webhook->id, 'message_id' => (string) $message->id, 'attempts' => $rows]);
    }

    // ---------------------------------------------------------------- helpers ---

    /**
     * Run $fn with the attachment disk swapped for a throwaway one during a DRY RUN, so a delete
     * that Firefly performs on the file (attachment and tag deletes) cannot escape the rollback.
     */
    private function holdingFiles(bool $dryRun, Closure $fn): mixed
    {
        if (!$dryRun) {
            return $fn();
        }
        $original = Storage::disk('upload');
        $root     = sprintf('%s/ffmachine-held-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        Storage::set('upload', Storage::build(['driver' => 'local', 'root' => $root]));

        try {
            return $fn();
        } finally {
            Storage::set('upload', $original);
            if (is_dir($root)) {
                @rmdir($root);
            }
        }
    }

    /**
     * @template T of object
     *
     * @param Closure(): T $find
     *
     * @return null|T  null when a numeric id is already gone (a DELETE answers deleted: 0, §5.6)
     */
    private function findOrGone(Closure $find, string $ref): ?object
    {
        try {
            return $find();
        } catch (MachineException $e) {
            if ('not_found' === $e->code() && 1 === preg_match('/^\d{1,19}$/', trim($ref))) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function range(array $args): array
    {
        $start = isset($args['start']) ? Carbon::createFromFormat('Y-m-d', (string) $args['start'])->startOfDay() : null;
        $end   = isset($args['end']) ? Carbon::createFromFormat('Y-m-d', (string) $args['end'])->startOfDay() : null;
        if (null !== $start && null !== $end && $end->lt($start)) {
            throw MachineException::invalid('end is before start.', sprintf('Swap them: start %s, end %s', $end->format('Y-m-d'), $start->format('Y-m-d')));
        }

        return [$start, $end];
    }

    /** @return list<array{0: class-string, 1: Closure}> */
    private function tagSpecs(): array
    {
        $admin = $this->administration()->id;

        return [[Tag::class, static fn (): EloquentBuilder => Tag::query()->where('user_group_id', $admin)]];
    }

    private function findTag(string $ref): Tag
    {
        /** @var Tag */
        return $this->resolve(Tag::class, urldecode($ref), 'tag');
    }

    private function findObjectGroup(string $ref): ObjectGroup
    {
        /** @var ObjectGroup */
        return $this->resolve(ObjectGroup::class, urldecode($ref), 'title');
    }

    private function findCurrency(string $code): TransactionCurrency
    {
        $value = trim(urldecode($code));
        if (1 === preg_match('/^\d{1,19}$/', $value)) {
            $found = TransactionCurrency::query()->find((int) $value);
        }
        if (1 !== preg_match('/^\d{1,19}$/', $value)) {
            $found = TransactionCurrency::query()->where('code', mb_strtoupper($value))->first();
        }
        if (null === $found) {
            throw MachineException::notFound(sprintf('No currency "%s".', mb_substr($value, 0, 51)), 'GET /machine/v1/currencies lists every currency by its code (USD, EUR…)', ['code' => mb_substr($value, 0, 51)]);
        }

        return $found;
    }

    private function findLinkType(string $ref): LinkType
    {
        /** @var LinkType */
        return $this->resolve(LinkType::class, urldecode($ref), 'name', static fn ($q) => $q);
    }

    private function assertEditable(LinkType $type): void
    {
        if (false === (bool) $type->editable) {
            throw MachineException::forbidden(sprintf('"%s" is one of Firefly\'s own link types and cannot be changed or deleted.', $type->name), 'Create a new link type with POST /machine/v1/link-types instead', ['id' => $type->id]);
        }
    }

    private function findAttachment(string $ref): Attachment
    {
        $userId = $this->operator()->id;

        /** @var Attachment */
        return $this->resolve(Attachment::class, urldecode($ref), 'filename', static fn ($q) => $q->where('user_id', $userId));
    }

    private function findWebhook(string $ref): Webhook
    {
        /** @var Webhook */
        return $this->resolve(Webhook::class, urldecode($ref), 'title');
    }

    /** @return EloquentBuilder<Attachment> */
    private function attachmentQuery(): EloquentBuilder
    {
        return Attachment::query()->where('user_id', $this->operator()->id);
    }

    /** @return list<string> */
    private function attachableTypes(): array
    {
        return array_values(array_map(static fn (string $c): string => str_replace('FireflyIII\Models\\', '', $c), (array) config('firefly.valid_attachment_models')));
    }

    private function webhooksEnabled(): bool
    {
        return (bool) AppConfiguration::get('allow_webhooks', config('firefly.allow_webhooks'))->data;
    }

    /** Firefly's default for a preference, as PreferencesController shows it. */
    private function preferenceDefault(string $name): mixed
    {
        return match ($name) {
            'viewRange'         => '1M',
            'listPageSize'      => 50,
            'language'          => config('firefly.default_language', 'en_US'),
            'locale'            => config('firefly.default_locale', 'equal'),
            'frontpageAccounts' => [],
            'fiscalYearStart'   => '01-01',
            'customFiscalYear'  => false,
            default             => null,
        };
    }

    /** Validate a preference value the way Firefly's preferences form does, and shape it for storage. */
    private function preferenceValue(string $name, mixed $value): mixed
    {
        $bad = static fn (string $hint): MachineException => MachineException::invalid(sprintf('That is not a valid value for %s.', $name), $hint, ['field' => 'value', 'name' => $name]);

        switch ($name) {
            case 'viewRange':
                $ranges = (array) config('firefly.valid_view_ranges');
                if (!is_string($value) || !in_array($value, $ranges, true)) {
                    throw $bad(sprintf('viewRange is one of %s', implode(', ', $ranges)));
                }

                return $value;

            case 'listPageSize':
                if (!(is_int($value) || (is_string($value) && ctype_digit($value))) || (int) $value < 1 || (int) $value > 1336) {
                    throw $bad('listPageSize is a whole number from 1 to 1336');
                }

                return (int) $value;

            case 'language':
                $languages = array_keys((array) config('firefly.languages'));
                if (!is_string($value) || !in_array($value, $languages, true)) {
                    throw $bad('language is a code such as en_US, de_DE, nl_NL');
                }

                return $value;

            case 'locale':
                $languages = array_keys((array) config('firefly.languages'));
                if (!is_string($value) || ('equal' !== $value && !in_array($value, $languages, true))) {
                    throw $bad('locale is "equal" (follow the language) or a code such as en_US');
                }

                return $value;

            case 'frontpageAccounts':
                if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
                    throw $bad('frontpageAccounts is a list of asset account ids, e.g. ["1", "4"]');
                }
                $ids = [];
                foreach ($value as $ref) {
                    if (!is_scalar($ref)) {
                        throw $bad('frontpageAccounts is a list of asset account ids or names');
                    }
                    /** @var Account $account */
                    $account = $this->resolve(Account::class, (string) $ref);
                    if (AccountTypeEnum::ASSET->value !== $account->accountType?->type) {
                        throw $bad(sprintf('Account #%d is not an asset account; the front page shows asset accounts', $account->id));
                    }
                    $ids[]   = (int) $account->id;
                }

                return array_values(array_unique($ids));

            case 'fiscalYearStart':
                if (!is_string($value) || 1 !== preg_match('/^(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[1], (int) $m[2], 2001)) {
                    throw $bad('fiscalYearStart is MM-DD, e.g. "04-01" for a year starting on 1 April');
                }

                return $value;

            case 'customFiscalYear':
                if (!is_bool($value)) {
                    throw $bad('customFiscalYear is true or false');
                }

                return $value;
        }

        throw $bad('Unknown preference');
    }

    // --------------------------------------------------------------- renderers ---

    /** @return array<string, mixed> */
    private function renderTag(Tag $tag): array
    {
        /** @var TagTransformer $transformer */
        $transformer = app(TagTransformer::class);
        $row         = $transformer->transform($tag);
        unset($row['links']);
        $row['date'] = null === $tag->date ? null : Carbon::parse($tag->date)->format('Y-m-d');

        return $row;
    }

    /** @return array<string, mixed> */
    private function renderObjectGroup(ObjectGroup $group): array
    {
        /** @var ObjectGroupTransformer $transformer */
        $transformer = app(ObjectGroupTransformer::class);
        $row         = $transformer->transform($group);
        unset($row['links']);

        return $row;
    }

    /** @return array<string, mixed> */
    private function renderCurrency(TransactionCurrency $currency): array
    {
        $currency->refreshForUser($this->operator());

        /** @var CurrencyTransformer $transformer */
        $transformer = app(CurrencyTransformer::class);
        $row         = $transformer->transform($currency);
        unset($row['links']);

        return $row;
    }

    /** @return array<string, mixed> */
    private function renderRate(CurrencyExchangeRate $rate): array
    {
        $row         = (new ExchangeRateTransformer())->transform($rate);
        unset($row['links']);
        $row['rate'] = Money::strip((string) $rate->rate);
        $row['date'] = Carbon::parse($rate->date)->format('Y-m-d');

        return $row;
    }

    /** @return array<string, mixed> */
    private function renderLinkType(LinkType $type): array
    {
        /** @var LinkTypeTransformer $transformer */
        $transformer = app(LinkTypeTransformer::class);
        $row         = $transformer->transform($type);
        unset($row['links']);

        return $row;
    }

    /** @return array<string, mixed> */
    private function renderAttachment(Attachment $attachment): array
    {
        /** @var AttachmentTransformer $transformer */
        $transformer         = app(AttachmentTransformer::class);
        $row                 = $transformer->transform($attachment);
        unset($row['links'], $row['upload_url']);
        $row['download_url'] = sprintf('/machine/v1/attachments/%d/download', $attachment->id);
        $row['uploaded']     = (bool) $attachment->uploaded;

        return $row;
    }

    // ------------------------------------------------------------ repositories ---

    private function tagRepository(): TagRepositoryInterface
    {
        /** @var TagRepositoryInterface $repo */
        $repo = app(TagRepositoryInterface::class);
        $repo->setUser($this->operator());

        return $repo;
    }

    private function objectGroupRepository(): ObjectGroupRepositoryInterface
    {
        /** @var ObjectGroupRepositoryInterface $repo */
        $repo = app(ObjectGroupRepositoryInterface::class);
        $repo->setUser($this->operator());

        return $repo;
    }

    private function currencyRepository(): CurrencyRepositoryInterface
    {
        /** @var CurrencyRepositoryInterface $repo */
        $repo = app(CurrencyRepositoryInterface::class);
        $repo->setUser($this->operator());
        $repo->setUserGroup($this->administration());

        return $repo;
    }

    private function exchangeRateRepository(): ExchangeRateRepositoryInterface
    {
        /** @var ExchangeRateRepositoryInterface $repo */
        $repo = app(ExchangeRateRepositoryInterface::class);
        $repo->setUserGroup($this->administration());

        return $repo;
    }

    private function linkTypeRepository(): LinkTypeRepositoryInterface
    {
        /** @var LinkTypeRepositoryInterface $repo */
        $repo = app(LinkTypeRepositoryInterface::class);
        $repo->setUser($this->operator());

        return $repo;
    }

    private function attachmentRepository(): AttachmentRepositoryInterface
    {
        /** @var AttachmentRepositoryInterface $repo */
        $repo = app(AttachmentRepositoryInterface::class);
        $repo->setUser($this->operator());

        return $repo;
    }

    private function webhookRepository(): WebhookRepositoryInterface
    {
        /** @var WebhookRepositoryInterface $repo */
        $repo = app(WebhookRepositoryInterface::class);
        $repo->setUser($this->operator());

        return $repo;
    }
}
