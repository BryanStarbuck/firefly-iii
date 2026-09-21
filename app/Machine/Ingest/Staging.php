<?php

/*
 * Staging.php
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

namespace FireflyIII\Machine\Ingest;

use Carbon\CarbonImmutable;
use FireflyIII\Machine\MachineException;

/**
 * The staging directory — apis.mdx §11.3, cli.mdx §10.7. The ONLY place under {ROOT} the plane
 * ever writes.
 *
 *   {ROOT}/.firefly-staging/            never {ROOT}/import/ — that name belongs to the archive (§11.2)
 *   ├── .firefly-staging.json           our ownership marker
 *   ├── .gitignore                      "*" — derived files full of real financial data
 *   ├── _manifest.csv _rows.csv _dupes.csv _conflicts.csv _run.log _map.json
 *   ├── _runs/{id}.json                 one run's full report
 *   ├── _text/{sha256}.txt              cached PDF text (raw mode)
 *   └── {ENTITY}/{INSTITUTION}/{ACCOUNT}/{YYYY}-{MM}.csv
 *
 * Every file is written atomically (temp + rename). A directory holding files we did not write
 * is refused `conflict`, naming both paths — losing somebody's audit evidence to a name
 * collision is not a bug we are willing to have once.
 */
final class Staging
{
    public const string DIR    = '.firefly-staging';
    public const string MARKER = '.firefly-staging.json';

    public function __construct(public readonly string $root) {}

    public function dir(): string
    {
        return $this->root.'/'.self::DIR;
    }

    /** The staging directory as the caller sees it (relative to the root). */
    public function display(): string
    {
        return self::DIR;
    }

    public function exists(): bool
    {
        return is_dir($this->dir()) && is_file($this->dir().'/'.self::MARKER);
    }

    /**
     * Create (or verify) the staging directory. Refuses `conflict` when the directory exists and
     * was not written by us, or is a symlink.
     */
    public function ensure(): void
    {
        $dir = $this->dir();
        if (is_link($dir)) {
            throw $this->foreign('is a symbolic link');
        }
        if (is_dir($dir)) {
            if (!is_file($dir.'/'.self::MARKER)) {
                $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
                if ([] !== $entries) {
                    throw $this->foreign('holds files this plane did not write');
                }
            }
        } elseif (file_exists($dir)) {
            throw $this->foreign('exists and is not a directory');
        } elseif (!@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw MachineException::internal('Could not create the staging directory.', 'Check that the statements root is writable by the app');
        }
        $real = realpath($dir);
        if (false === $real || !StatementsRoot::isInside($this->root, $real)) {
            throw $this->foreign('resolves outside the statements root');
        }
        if (!is_file($dir.'/'.self::MARKER)) {
            $this->atomic($dir.'/'.self::MARKER, (string) json_encode(['created_by' => 'firefly-iii machine plane', 'created' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), 'purpose' => 'derived, rebuildable staging for /machine/v1/ingest — safe to delete'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }
        if (!is_file($dir.'/.gitignore')) {
            $this->atomic($dir.'/.gitignore', "*\n");
        }
    }

    /** Write one file atomically, creating its directories. $relative is inside the staging dir. */
    public function write(string $relative, string $content): string
    {
        $this->ensure();
        $path = $this->path($relative);
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw MachineException::internal('Could not create a staging subdirectory.');
        }
        $this->atomic($path, $content);

        return $path;
    }

    /**
     * @param list<string>                     $header
     * @param iterable<array<string, mixed>>   $rows
     */
    public function writeCsv(string $relative, array $header, iterable $rows): string
    {
        return $this->write($relative, Csv::encode($header, $rows));
    }

    /** @return list<array<string, string>> the rows of a staged CSV (empty when absent) */
    public function readCsv(string $relative): array
    {
        $text = $this->read($relative);

        return null === $text ? [] : Csv::decodeAssoc($text);
    }

    public function read(string $relative): ?string
    {
        if (!is_dir($this->dir())) {
            return null;
        }
        $path = $this->path($relative);
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $text = file_get_contents($path);

        return false === $text ? null : $text;
    }

    /** Append one line to an append-only file (_run.log). */
    public function append(string $relative, string $line): void
    {
        $this->ensure();
        $path = $this->path($relative);
        $fh   = fopen($path, 'ab');
        if (false === $fh) {
            throw MachineException::internal('Could not append to the staging run log.');
        }

        try {
            flock($fh, LOCK_EX);
            fwrite($fh, rtrim($line, "\n")."\n");
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
        @chmod($path, 0o600);
    }

    public function path(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        foreach (explode('/', $relative) as $segment) {
            if ('..' === $segment || '' === $segment) {
                throw MachineException::internal('A staging path escaped the staging directory.');
            }
        }

        return $this->dir().'/'.$relative;
    }

    /** A file name segment safe for the staging tree ("Checking x4021" → "Checking_x4021"). */
    public static function segment(string $value): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value));
        $clean = trim($clean, '._');

        return '' === $clean ? '_' : $clean;
    }

    private function atomic(string $path, string $content): void
    {
        $tmp = sprintf('%s.tmp-%s', $path, bin2hex(random_bytes(4)));
        if (false === @file_put_contents($tmp, $content)) {
            throw MachineException::internal('Could not write to the staging directory.', 'Check that the statements root is writable by the app');
        }
        @chmod($tmp, 0o600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw MachineException::internal('Could not move a staged file into place.');
        }
    }

    private function foreign(string $why): MachineException
    {
        $import = is_dir($this->root.'/import') ? 'import' : null;

        return MachineException::conflict(
            sprintf('Refusing to stage: %s %s.', self::DIR, $why),
            sprintf('Nothing was written. Move that directory aside (it is not ours%s), then retry — the plane never stages into a directory it did not create', null === $import ? '' : ', and the archive\'s own import/ is never used either'),
            ['staging' => self::DIR, 'root' => $this->root, 'archive_import' => $import],
        );
    }
}
