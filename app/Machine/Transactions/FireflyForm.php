<?php

/*
 * FireflyForm.php
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

namespace FireflyIII\Machine\Transactions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;

/**
 * Runs one of upstream's own /api/v1 FormRequests over a payload the plane built — so a plane
 * write passes exactly the validation upstream's API applies (account rules, split invariants,
 * type rules, "a transfer's splits share accounts"…) and is handed to the repository in exactly
 * the shape upstream's controller hands it (the request's getAll()). R7: Firefly decides.
 *
 * A validation failure is Laravel's ValidationException, which the plane renders as
 * invalid_input with details.fields (apis.mdx §5.1).
 */
final class FireflyForm
{
    /**
     * @template T of FormRequest
     *
     * @param class-string<T>      $class       e.g. Api\V1\Requests\Models\Transaction\StoreRequest
     * @param array<string, mixed> $payload     the request body upstream would have received
     * @param array<string, mixed> $routeParams route-model bindings the request reads (transactionGroup…)
     *
     * @return T validated, ready for getAll()
     *
     * @throws ValidationException
     */
    public static function validated(string $class, array $payload, array $routeParams = []): FormRequest
    {
        /** @var T $form */
        $form  = $class::create('/machine-internal', 'POST', $payload);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->setUserResolver(static fn () => auth()->user());

        $route = new Route(['POST'], 'machine-internal', []);
        $route->bind($form);
        foreach ($routeParams as $name => $value) {
            $route->setParameter($name, $value);
        }
        $form->setRouteResolver(static fn (): Route => $route);

        $validator = (function () {
            if (method_exists($this, 'prepareForValidation')) {
                $this->prepareForValidation();
            }

            return $this->getValidatorInstance();
        })->call($form);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $form;
    }
}
