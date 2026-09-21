<?php

/*
 * ProbeController.php
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

namespace Tests\Machine\Core\Fixtures;

use FireflyIII\Exceptions\DuplicateTransactionException;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Machine\Http\Controllers\MachineController;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Undo\OperationLog;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Category;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The test-only controller behind ProbeRoutes. Every write goes through the real
 * MachineController::write() — the protocol under test.
 */
final class ProbeController extends MachineController
{
    public function throw(Request $request, string $kind): JsonResponse
    {
        throw match ($kind) {
            'firefly'    => new FireflyException('Firefly refused this for a reason it can name.'),
            'duplicate'  => new DuplicateTransactionException('Duplicate of transaction #42.'),
            'model'      => (new ModelNotFoundException())->setModel(Category::class, [7]),
            'validation' => ValidationException::withMessages(['amount' => ['The amount must be positive.']]),
            'authz'      => new AuthorizationException('nope'),
            'machine'    => MachineException::conflict('A named conflict.', 'Do the thing', ['n' => 1]),
            default      => new RuntimeException('Boom in /Users/somebody/firefly-iii/app/Secret.php:12 via Illuminate\Database\Connection SQLSTATE[HY000]: select * from users'),
        };
    }

    public function list(Request $request): JsonResponse
    {
        $args   = $this->input($request, ['flag' => ['sometimes', 'boolean'], 'start' => ['sometimes', 'date_format:Y-m-d']] + self::LIST_RULES, true);
        $params = $this->listParams($request, ['name', 'amount', 'id'], 'name');
        $rows   = [];
        foreach (['delta', 'alpha', 'charlie', 'bravo', 'echo', 'alpha', 'foxtrot'] as $i => $name) {
            $rows[] = ['id' => $i + 1, 'name' => $name, 'amount' => ['10.00', '9.50', '100.00', '-3.25', '0.00', '9.50', '1000.01'][$i]];
        }

        return $this->ok(['rows' => $this->applyList($rows, $params), 'args' => $args]);
    }

    public function body(Request $request): JsonResponse
    {
        $args = $this->input($request, ['name' => ['required', 'string'], 'tags' => ['sometimes', 'array'], 'tags.*' => ['string']]);

        return $this->ok(['args' => $args]);
    }

    public function auth(Request $request): JsonResponse
    {
        return $this->ok(['auth_id' => auth()->id(), 'api_id' => auth('api')->id(), 'administration' => $this->administration()->id, 'primary' => $this->primaryCurrency()->code]);
    }

    public function resolveCategory(Request $request, string $name): JsonResponse
    {
        $category = $this->resolve(Category::class, urldecode($name));

        return $this->ok(['id' => (int) $category->id, 'name' => (string) $category->name]);
    }

    public function createCategories(Request $request): JsonResponse
    {
        $args = $this->input($request, ['names' => ['required', 'array', 'min:1'], 'names.*' => ['required', 'string', 'max:100']]);

        return $this->write($request, $args, function (bool $dryRun) use ($args): WriteResult {
            $result = new WriteResult();
            $ids    = [];
            foreach ($args['names'] as $name) {
                $category = Category::create(['name' => $name, 'user_id' => $this->operator()->id, 'user_group_id' => $this->administration()->id]);
                $result->created($category)->count('created');
                $ids[]    = (int) $category->id;
            }

            return $result->with(['category_ids' => $ids]);
        });
    }

    public function suffixCategories(Request $request): JsonResponse
    {
        $args = $this->input($request, ['suffix' => ['required', 'string', 'max:20']]);

        return $this->write($request, $args, function (bool $dryRun) use ($args): WriteResult {
            $result = new WriteResult();
            foreach (Category::query()->where('user_group_id', $this->administration()->id)->orderBy('id')->get() as $category) {
                $result->updating($category)->count('updated');
                $category->name .= $args['suffix'];
                $category->save();
            }

            return $result;
        });
    }

    public function deleteCategories(Request $request): JsonResponse
    {
        $args = $this->input($request, []);

        return $this->write($request, $args, function (bool $dryRun): WriteResult {
            $result = new WriteResult();
            foreach (Category::query()->where('user_group_id', $this->administration()->id)->orderBy('id')->get() as $category) {
                $result->deleting($category)->count('deleted');
                $category->delete();
            }

            return $result;
        });
    }

    public function boomWrite(Request $request): JsonResponse
    {
        $args = $this->input($request, []);

        return $this->write($request, $args, function (bool $dryRun): WriteResult {
            Category::create(['name' => 'half-way', 'user_id' => $this->operator()->id, 'user_group_id' => $this->administration()->id]);

            throw new RuntimeException('failed half way');
        });
    }

    public function direct(Request $request): JsonResponse
    {
        $args = $this->input($request, ['name' => ['required', 'string']]);

        return $this->write($request, $args, function (bool $dryRun) use ($args): WriteResult {
            $category = Category::create(['name' => $args['name'], 'user_id' => $this->operator()->id, 'user_group_id' => $this->administration()->id]);

            return (new WriteResult())->created($category)->count('created')->with(['id' => (int) $category->id, 'dry_run_flag' => $dryRun]);
        });
    }

    /** The plan of an undo: what the last operation did, plus a token for exactly that one. */
    public function undoLast(Request $request): JsonResponse
    {
        $this->input($request, [], true);
        $last = OperationLog::last();
        if (null === $last) {
            throw MachineException::notFound('No plane operation to undo.', 'Nothing has been written through the plane yet');
        }
        $token = $this->planToken($request, 'POST /_probe/undo', [], 'op:'.$last['operation_id'], ['operation_id' => $last['operation_id']]);

        return $this->ok($last + ($last['reversible'] ? $token : ['confirm_token' => null]));
    }

    /** Reverse exactly the operation the plan named (never "whatever is last now"). */
    public function undo(Request $request): JsonResponse
    {
        $args = $this->input($request, ['confirm_token' => ['required', 'string']]);
        unset($args['confirm_token']);

        return $this->write($request, $args, static function (bool $dryRun) use ($request): WriteResult {
            $entry = (array) $request->attributes->get('machine.confirm', []);
            $id    = (int) ($entry['payload']['operation_id'] ?? 0);

            return new WriteResult(['reversed' => 1], OperationLog::reverse($id));
        }, null, ['record' => false, 'require_token' => true]);
    }
}
