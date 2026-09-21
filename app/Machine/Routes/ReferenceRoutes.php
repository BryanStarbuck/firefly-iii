<?php

/*
 * ReferenceRoutes.php
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

namespace FireflyIII\Machine\Routes;

use FireflyIII\Machine\Http\Controllers\ReferenceController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §8.8 and §8.10 — tags, object groups, currencies, exchange rates, link types, attachments, preferences, and the read-only webhook surface (creating or editing a webhook is admin — see AdminRoutes).
 *
 * Every route is live; the handler is ReferenceController.
 */
final class ReferenceRoutes implements RouteFamily
{
    public static function routes(): array
    {
        $c = ReferenceController::class;

        return [
            RouteDef::live('GET', '/tags', 'read', 'Tags (search).', [$c, 'tags'], ['phase' => 'P2']),
            RouteDef::live('GET', '/tags/{tag}', 'read', 'One tag, with spent and earned in a range.', [$c, 'tag'], ['phase' => 'P2']),
            RouteDef::live('POST', '/tags', 'write', 'Create a tag.', [$c, 'storeTag'], ['phase' => 'P8']),
            RouteDef::live('PUT', '/tags/{tag}', 'write', 'Rename a tag (every journal keeps it).', [$c, 'updateTag'], ['phase' => 'P8']),
            RouteDef::live('DELETE', '/tags/{tag}', 'admin', 'Delete a tag.', [$c, 'destroyTag'], ['phase' => 'P10']),
            RouteDef::live('GET', '/object-groups', 'read', 'Object groups (the grouping for piggy banks and subscriptions).', [$c, 'objectGroups'], ['phase' => 'P2']),
            RouteDef::live('PUT', '/object-groups/{id}', 'write', 'Rename or reorder an object group.', [$c, 'updateObjectGroup'], ['phase' => 'P8']),
            RouteDef::live('DELETE', '/object-groups/{id}', 'admin', 'Delete an object group.', [$c, 'destroyObjectGroup'], ['phase' => 'P10']),
            RouteDef::live('GET', '/currencies', 'read', 'Currencies (enabled), the primary one marked.', [$c, 'currencies'], ['phase' => 'P2']),
            RouteDef::live('GET', '/currencies/primary', 'read', 'The administration\'s primary currency.', [$c, 'primaryCurrencyRoute'], ['phase' => 'P2']),
            RouteDef::live('POST', '/currencies/{code}/enable', 'write', 'Enable a currency.', [$c, 'enableCurrency'], ['phase' => 'P8']),
            RouteDef::live('POST', '/currencies/{code}/disable', 'write', 'Disable a currency.', [$c, 'disableCurrency'], ['phase' => 'P8']),
            RouteDef::live('GET', '/exchange-rates', 'read', 'Exchange rates (from, to, start, end).', [$c, 'exchangeRates'], ['phase' => 'P2']),
            RouteDef::live('POST', '/exchange-rates', 'write', 'Add an exchange rate (the rate is a decimal string).', [$c, 'storeExchangeRate'], ['phase' => 'P8']),
            RouteDef::live('DELETE', '/exchange-rates/{id}', 'admin', 'Delete an exchange rate.', [$c, 'destroyExchangeRate'], ['phase' => 'P10']),
            RouteDef::live('GET', '/link-types', 'read', 'Transaction link types.', [$c, 'linkTypes'], ['phase' => 'P2']),
            RouteDef::live('POST', '/link-types', 'write', 'Create a link type.', [$c, 'storeLinkType'], ['phase' => 'P8']),
            RouteDef::live('PUT', '/link-types/{id}', 'write', 'Edit a link type.', [$c, 'updateLinkType'], ['phase' => 'P8']),
            RouteDef::live('DELETE', '/link-types/{id}', 'admin', 'Delete a link type.', [$c, 'destroyLinkType'], ['phase' => 'P10']),
            RouteDef::live('GET', '/attachments', 'read', 'Attachments, optionally of one object.', [$c, 'attachments'], ['phase' => 'P2']),
            RouteDef::live('GET', '/attachments/{id}', 'read', 'An attachment\'s metadata.', [$c, 'attachment'], ['phase' => 'P2']),
            RouteDef::live('GET', '/attachments/{id}/download', 'read', 'Download an attachment (streamed: the one read route whose body is not the envelope).', [$c, 'download'], ['phase' => 'P2']),
            RouteDef::live('POST', '/attachments', 'write', 'Attach a file to an object (content_base64; the dry run stores no file).', [$c, 'storeAttachment'], ['phase' => 'P8']),
            RouteDef::live('DELETE', '/attachments/{id}', 'admin', 'Delete an attachment.', [$c, 'destroyAttachment'], ['phase' => 'P10']),
            RouteDef::live('GET', '/preferences', 'read', 'The operator\'s preferences (the allowlisted ones).', [$c, 'preferences'], ['phase' => 'P2']),
            RouteDef::live('PUT', '/preferences/{name}', 'write', 'Set an allowlisted preference (viewRange, listPageSize, language, locale, frontpageAccounts, fiscalYearStart, customFiscalYear).', [$c, 'setPreference'], ['phase' => 'P8']),
            RouteDef::live('GET', '/webhooks', 'read', 'Webhooks (never their secret).', [$c, 'webhooks'], ['phase' => 'P2']),
            RouteDef::live('GET', '/webhooks/{id}/messages', 'read', 'A webhook\'s messages.', [$c, 'webhookMessages'], ['phase' => 'P2']),
            RouteDef::live('GET', '/webhooks/{id}/messages/{message_id}/attempts', 'read', 'A webhook message\'s delivery attempts.', [$c, 'webhookAttempts'], ['phase' => 'P2']),
        ];
    }
}
