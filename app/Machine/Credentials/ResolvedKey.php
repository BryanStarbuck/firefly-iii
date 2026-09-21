<?php

/*
 * ResolvedKey.php
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

namespace FireflyIII\Machine\Credentials;

/**
 * The machine key as resolved for this request — apis.mdx §4.5. The key itself never leaves
 * this object except into hash_equals(); everything printable is fingerprint().
 */
final readonly class ResolvedKey
{
    /**
     * @param string      $source 'env' | 'key-file' | 'credentials-file'
     * @param null|string $file   the file it came from (null for the env source)
     */
    public function __construct(
        private string $key,
        public string $source,
        public ?string $file,
    ) {}

    /** "4f2a…/sha256:9c1b" — the ONLY representation that may be logged or shown (§4.8). */
    public function fingerprint(): string
    {
        return CredentialsFile::fingerprint($this->key);
    }

    /**
     * A stable, non-reversible id for namespacing cache entries (confirm tokens, idempotency
     * keys) by the key, so a rotation invalidates them all at once (§7.3).
     */
    public function id(): string
    {
        return substr(hash('sha256', 'firefly-machine-ns:'.$this->key), 0, 24);
    }

    /** Constant-time: hash_equals over two SHA-256 digests of equal length (§4.6). */
    public function matches(?string $presented): bool
    {
        return hash_equals(hash('sha256', $this->key), hash('sha256', (string) $presented));
    }

    /** Prevent the key from reaching a var_dump / debug page. */
    public function __debugInfo(): array
    {
        return ['source' => $this->source, 'file' => $this->file, 'fingerprint' => $this->fingerprint()];
    }
}
