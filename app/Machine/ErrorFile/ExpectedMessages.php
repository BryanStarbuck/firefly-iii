<?php

/*
 * ExpectedMessages.php
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

namespace FireflyIII\Machine\ErrorFile;

use Throwable;

/**
 * The upstream log lines that are answers, not faults — pm/error_err.mdx §4.6.
 *
 * PREFIXES is a list of [anchored regex run against the RAW message, list of needles]. A needle is
 * the literal format-string fragment as it appears in upstream source under app/;
 * ExpectedListDriftTest asserts every needle still occurs there. When upstream rewords a string the
 * test fails and the line becomes a WARN until the entry is updated — the failure is safe (R15).
 *
 * Deliberately NOT listed (they point at a data or configuration fault, so they stay WARN):
 * `Could not find currency with code "`, `Found no end balance for account #`,
 * `Could not find role "owner"`, `Could not set locale.`, `Cannot find journal #`,
 * `Journal # does not exist`, `Cannot add `, and the operator's own
 * `This is a test message at the ERROR level.`
 */
final class ExpectedMessages
{
    /** @var list<array{0: string, 1: list<string>}> */
    public const array PREFIXES = [
        /* 1 */ ['/^Duplicate of transaction #\d+\./', ['Duplicate of transaction #%d.']],
        /* 2 */ ['/^TransactionJournalFactory::create\(\) caught a duplicate/', ['TransactionJournalFactory::create() caught a duplicate']],
        /* 3 */ ['/^Could not validate source or destination\./', ['Could not validate source or destination.']],
        /* 4 */ ['/^Both values are NULL, cant create /', ['Both values are NULL, cant create ']],
        /* 5 */ ['/^Source is NULL, always FALSE\./', ['Source is NULL, always FALSE.']],
        /* 6 */ ['/^Destination ID is null, but destination name is also NULL\./', ['Destination ID is null, but destination name is also NULL.']],
        /* 7 */ ['/^(Source|Destination) account is not a liability\./', ['account is not a liability.']],
        /* 8 */ ['/^Did not find a liability account, return false\./', ['Did not find a liability account, return false.']],
        /* 9 */ ['/^Array (must have a name, is not the case, return false|has a name, return true)\./', ['Array has a name, return true.']],
        /* 10 */ ['/^Could not parse search: "/', ['Could not parse search: "%s".']],
        /* 11 */ ['/^No such operator: /', ['No such operator: %s']],
        /* 12 */ ['/^Part ".*" does not match regular expression\. Will be skipped\./', ['does not match regular expression. Will be skipped.']],
        /* 13 */ ['/^Request field ".*" contains a non-scalar value\./', ['contains a non-scalar value. Value set to NULL.']],
        /* 14 */ ['/^(Start: |End )could not parse date string "/', ['could not parse date string "%s" so ignore it.']],
        /* 15 */ ['/^Could not parse start or end date in verifyInputDate\(\)\./', ['Could not parse start or end date in verifyInputDate().']],
        /* 16 */ ['/^The cron endpoint has moved to /', ['The cron endpoint has moved to GET /api/v1/cron/[token]']],
        /* 17 */ ['/^Login for user ".*" was locked out\./', ['was locked out.']],
        /* 18 */ ['/^Cowardly refuse to send a password reset message to user #\d+/', ['Cowardly refuse to send a password reset message']],
        /* 19 */ ['/^(Recognized no users by access token "|Invalid access token for user #\d+\.|Token given is "|User #\d+ has no access token|verifyAccessToken\(\): no such user)/', ['Recognized no users by access token "%s"', 'Token given is "%s"']],
        /* 20 */ ['/^No user in header "/', ['No user in header "']],
        /* 21 */ ['/^Cannot access .*\?/', ['Cannot access %s?%s.']],
        /* 22 */ ['/^User \d+ is not admin, but tried to store a currency\./', [' is not admin, but tried to store a currency.']],
        /* 23 */ ['/^(TagList|PreferenceList|TagOrId): user is not logged in\.|^TagOrId: tag not found\.|^Trying to show account list \(/', ['TagList: user is not logged in.']],
        /* 24 */ ['/^User has no valid user group submitted or otherwise\./', ['User has no valid user group submitted or otherwise.']],
        /* 25 */ ['/^No user during validate2faCode/', ['No user during validate2faCode']],
        /* 26 */ ['/^Could not (find (account|attachment|group|journal for group) \d+, so give big fat error|redirect attachment \d+, its linked to a )/', [', so give big fat error.']],
        /* 27 */ ['/^GracefulNotFoundHandler cannot handle route with name "/', ['GracefulNotFoundHandler cannot handle route with name "']],
        /* 28 */ ['/^Journal #\d+ is already a (transfer|withdrawal|deposit)/', ['is already a withdrawal (rule #%d).']],
        /* 29 */ ['/^Group #\d+ has more than one transaction in it, cannot /', ['has more than one transaction in it, cannot']],
        /* 30 */ ['/^Journal #\d+ has already has "/', ['has already has "%s" as a']],
        /* 31 */ ['/^Cant change (source|destination) account of journal #\d+/', ['Cant change destination account of journal #%d']],
        /* 32 */ ['/^No destination account found for name "/', ['No destination account found for name "']],
        /* 33 */ ['/^Transaction must be (withdrawal|deposit)\./', ['Transaction must be withdrawal.']],
        /* 34 */ ['/^(Public|Private) key in DB is unexpectedly an empty string\./', ['Public key in DB is unexpectedly an empty string.']],
        /* 35 */ ['/^User is not set in repository /', ['User is not set in repository ']],
        /* 36 */ ['/^Sent \d+ emails in /', ['Sent %d emails in %s, return true.']],
    ];

    /**
     * Withhold-after (§4.6): everything after one of these prefixes becomes `[withheld]` in a T3
     * message. Needles: Sha3SignatureGenerator.php, StandardWebhookSender.php.
     *
     * @var list<string>
     */
    public const array WITHHOLD_AFTER = ['JSON value: ', 'The body of the error response is: '];

    /** A trace-continuation record: the second half of the `getTraceAsString()` pair. */
    public const string TRACE = '/^#\d+ /';

    /** MailError's request dump, which holds the URL and the sanitised POST body. */
    public const string MAIL_ERROR = 'Exception is: ';

    /** True when the raw message is on the expected-prefix list. Total. */
    public static function matches(string $message): bool
    {
        return null !== self::which($message);
    }

    /** The 1-based row of the first matching prefix, or null. Total. */
    public static function which(string $message): ?int
    {
        try {
            foreach (self::PREFIXES as $i => [$regex]) {
                if (1 === preg_match($regex, $message)) {
                    return $i + 1;
                }
            }
        } catch (Throwable) {
            // a regex failure is a WARN, never a crash
        }

        return null;
    }
}
