<?php

/*
 * CredentialsFile.php
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

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Env;
use JsonException;
use stdClass;

/**
 * The machine key — apis.mdx §4.2–§4.5, §4.8.
 *
 *   ~/.credentials/firefly_iii.json   mode 0600, directory 0700, unique to this app
 *   {
 *     "firefly_iii": {
 *       "machine":    { "api_key": "<64 hex>", "created": "…Z", "created_by": "firefly-web", "label": "<host>" },
 *       "statements": { "root": "/…/bank_statements" }
 *     },
 *     …other products' top-level keys, preserved
 *   }
 *
 * The on-disk shape and the write discipline are EXACTLY the CLI's (cli/code/src/credentials.ts):
 * merge (never replace), an O_EXCL `.lock` sibling with a 30-second stale break (so ffx, the MCP
 * and this app are mutually exclusive), an O_EXCL temp file created at 0600 under umask 0077,
 * fsync, rename, and a refusal to write through a symlink. Reads refuse a symlink, a non-regular
 * file, a mode looser than 0600, and a file owned by another uid.
 *
 * Resolution order (§4.5): FIREFLY_MACHINE_KEY → FIREFLY_MACHINE_KEY_FILE → the credentials file
 * (FIREFLY_MACHINE_CREDENTIALS_FILE relocates it) → the web app mints one (HTTP boot only).
 * The result is memoised per process and invalidated by the file's stat (mtime/size/inode), so a
 * rotation takes effect on the next request with no restart.
 */
final class CredentialsFile
{
    public const string APP_KEY    = 'firefly_iii';
    public const string CREATED_BY = 'firefly-web';

    private const string KEY_SHAPE     = '/^[0-9a-f]{64}$/';
    private const int LOCK_WAIT_MS     = 5000;
    private const int LOCK_STALE_S     = 30;

    /** @var null|array{sig: string, key: null|ResolvedKey, problem: null|string, fix: null|string} */
    private static ?array $memo = null;

    public function __construct(private readonly ?string $path) {}

    /** The file the configuration points at (null under PHPUnit unless a test set one). */
    public static function fromConfig(): self
    {
        return new self(self::configuredPath());
    }

    public static function configuredPath(): ?string
    {
        $configured = config('machine.credentials_file');
        if (is_string($configured) && '' !== trim($configured)) {
            return self::expandHome(trim($configured));
        }
        // Never fall back to the operator's real home directory under PHPUnit (§18).
        if (function_exists('app') && app()->runningUnitTests()) {
            return null;
        }

        return self::defaultPath();
    }

    public static function defaultPath(): string
    {
        return self::home().'/.credentials/firefly_iii.json';
    }

    public function path(): ?string
    {
        return $this->path;
    }

    // ------------------------------------------------------------------ shape ---

    /** 32 bytes from the OS CSPRNG, as 64 lowercase hex — longer than a UUID, not UUID-shaped. */
    public static function mintKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isWellFormedKey(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match(self::KEY_SHAPE, $value);
    }

    /** "4f2a…/sha256:9c1b" — byte-identical to the CLI's fingerprint(). */
    public static function fingerprint(string $key): string
    {
        return substr($key, 0, 4).'…/sha256:'.substr(hash('sha256', $key), 0, 4);
    }

    // ------------------------------------------------------------- resolution ---

    /**
     * The key this process should accept, or null when the plane is unarmed. Never throws:
     * a refusal leaves the plane unarmed and is reported by problem().
     */
    public static function resolve(): ?ResolvedKey
    {
        $state = self::state();

        return $state['key'];
    }

    /** Why the plane is unarmed (null when it is armed). Never contains the key. */
    public static function problem(): ?string
    {
        return self::state()['problem'];
    }

    /** The remediation for problem(). */
    public static function fix(): ?string
    {
        return self::state()['fix'];
    }

    /**
     * resolve(), and — when no key exists anywhere and the file is merely absent (or holds no
     * machine block) — mint one into the credentials file. Used ONLY on an HTTP boot and by
     * `firefly-machine:key --init` (§4.4 rule 1). A refused file is never overwritten.
     */
    public static function resolveOrMint(): ?ResolvedKey
    {
        $state = self::state();
        if (null !== $state['key'] || 'absent' !== ($state['reason'] ?? null)) {
            return $state['key'];
        }
        $file = self::fromConfig();
        if (null === $file->path()) {
            return null;
        }

        try {
            $file->init(self::CREATED_BY, false);
        } catch (CredentialsRefused) {
            // fall through: state() below reports why
        }
        self::forget();

        return self::resolve();
    }

    /** Drop the memo (after a write, or in tests). */
    public static function forget(): void
    {
        self::$memo = null;
    }

    /**
     * @return array{sig: string, key: null|ResolvedKey, problem: null|string, fix: null|string, reason?: string}
     */
    private static function state(): array
    {
        $sig = self::signature();
        if (null !== self::$memo && self::$memo['sig'] === $sig) {
            return self::$memo;
        }

        return self::$memo = ['sig' => $sig, ...self::load()];
    }

    /** What the memo is keyed on: the source, and the stat of the file it came from. */
    private static function signature(): string
    {
        $env = self::envValue('FIREFLY_MACHINE_KEY');
        if (null !== $env) {
            return 'env:'.hash('sha256', $env);
        }
        $keyFile = self::envValue('FIREFLY_MACHINE_KEY_FILE');
        $path    = null !== $keyFile ? self::expandHome($keyFile) : self::configuredPath();
        if (null === $path) {
            return 'none';
        }
        clearstatcache(true, $path);
        $st = @lstat($path);

        return sprintf('%s:%s:%s', null !== $keyFile ? 'keyfile' : 'file', $path, false === $st ? 'missing' : implode('/', [$st['mtime'], $st['size'], $st['ino'], $st['mode'], $st['uid']]));
    }

    /**
     * @return array{key: null|ResolvedKey, problem: null|string, fix: null|string, reason: string}
     */
    private static function load(): array
    {
        $env = self::envValue('FIREFLY_MACHINE_KEY');
        if (null !== $env) {
            if (!self::isWellFormedKey($env)) {
                return self::unarmed('refused', 'FIREFLY_MACHINE_KEY is set but is not 64 lowercase hex characters', 'unset it to use the credentials file, or set a well-formed key');
            }

            return ['key' => new ResolvedKey($env, 'env', null), 'problem' => null, 'fix' => null, 'reason' => 'ok'];
        }

        $keyFile = self::envValue('FIREFLY_MACHINE_KEY_FILE');
        if (null !== $keyFile) {
            $path = self::expandHome($keyFile);

            try {
                if (!self::checkFile($path)) {
                    return self::unarmed('refused', 'FIREFLY_MACHINE_KEY_FILE points at a file that does not exist', 'create it (64 hex characters, mode 0600) or unset FIREFLY_MACHINE_KEY_FILE');
                }
                $key = trim(self::readChecked($path));
            } catch (CredentialsRefused $e) {
                return self::unarmed('refused', $e->getMessage(), $e->fix);
            }
            if (!self::isWellFormedKey($key)) {
                return self::unarmed('refused', 'FIREFLY_MACHINE_KEY_FILE does not hold a 64-hex key', 'php artisan firefly-machine:key --rotate');
            }

            return ['key' => new ResolvedKey($key, 'key-file', $path), 'problem' => null, 'fix' => null, 'reason' => 'ok'];
        }

        $file = self::fromConfig();
        if (null === $file->path()) {
            return self::unarmed('unconfigured', 'no credentials file is configured', 'set FIREFLY_MACHINE_CREDENTIALS_FILE');
        }

        try {
            $doc = $file->read();
        } catch (CredentialsRefused $e) {
            return self::unarmed('refused', $e->getMessage(), $e->fix);
        }
        $machine = self::machineBlock($doc);
        $key     = $machine?->api_key ?? null;
        if (null === $key) {
            return self::unarmed('absent', 'no firefly_iii.machine.api_key yet', 'start the web app once (it mints the key), or: php artisan firefly-machine:key --init');
        }
        if (!self::isWellFormedKey($key)) {
            return self::unarmed('refused', 'firefly_iii.machine.api_key in the credentials file is malformed', 'php artisan firefly-machine:key --rotate   (or: ffx key rotate --yes)');
        }

        return ['key' => new ResolvedKey($key, 'credentials-file', $file->path()), 'problem' => null, 'fix' => null, 'reason' => 'ok'];
    }

    /** @return array{key: null, problem: string, fix: string, reason: string} */
    private static function unarmed(string $reason, string $problem, string $fix): array
    {
        return ['key' => null, 'problem' => $problem, 'fix' => $fix, 'reason' => $reason];
    }

    // ------------------------------------------------------------------- read ---

    /**
     * The whole document, or null when the file does not exist. Refuses (throws) on a symlink,
     * a non-regular file, a mode looser than 0600, another owner, or invalid JSON.
     */
    public function read(): ?stdClass
    {
        if (null === $this->path || !self::checkFile($this->path)) {
            return null;
        }
        $text = self::readChecked($this->path);
        if ('' === trim($text)) {
            return new stdClass();
        }

        try {
            $doc = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CredentialsRefused(
                sprintf('%s is not valid JSON (%s)', self::tildify($this->path), $e->getMessage()),
                'fix the file by hand — it may hold other products\' secrets, so it is never overwritten',
            );
        }
        if (!$doc instanceof stdClass) {
            throw new CredentialsRefused(sprintf('%s: the top level is not a JSON object', self::tildify($this->path)), 'fix the file by hand');
        }

        return $doc;
    }

    /** firefly_iii.statements.root, if configured. */
    public function statementsRoot(): ?string
    {
        try {
            $doc = $this->read();
        } catch (CredentialsRefused) {
            return null;
        }
        $product = null === $doc ? null : ($doc->{self::APP_KEY} ?? null);
        $root    = $product instanceof stdClass && ($product->statements ?? null) instanceof stdClass ? ($product->statements->root ?? null) : null;

        return is_string($root) && '' !== trim($root) ? $root : null;
    }

    /**
     * Stat before read: a regular file, not a symlink, 0600-or-tighter, ours.
     * Returns false when the file does not exist.
     */
    public static function checkFile(string $path): bool
    {
        clearstatcache(true, $path);
        $st = @lstat($path);
        if (false === $st) {
            return false;
        }
        if (is_link($path)) {
            throw new CredentialsRefused(sprintf('refused: %s is a symlink', self::tildify($path)), 'replace it with a regular file — a symlinked secret is somebody redirecting it');
        }
        if (!is_file($path)) {
            throw new CredentialsRefused(sprintf('refused: %s is not a regular file', self::tildify($path)), 'replace it with a regular file, mode 0600');
        }
        if (0 !== ($st['mode'] & 0o077)) {
            throw new CredentialsRefused(
                sprintf('refused: %s is readable by others (mode %04o)', self::tildify($path), $st['mode'] & 0o777),
                sprintf('chmod 600 %s', self::tildify($path)),
            );
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if (null !== $uid && $st['uid'] !== $uid) {
            throw new CredentialsRefused(
                sprintf('refused: %s is owned by uid %d, not the app\'s uid %d', self::tildify($path), $st['uid'], $uid),
                sprintf('chown it to the user running the app, then chmod 600 %s', self::tildify($path)),
            );
        }

        return true;
    }

    /**
     * Read a file that checkFile() just approved, refusing it if it was swapped in between: the
     * opened handle must be the very inode lstat() saw (a symlink swapped in would fstat() as
     * its target — a different inode), so a race cannot redirect the read (the CLI does the same
     * with O_NOFOLLOW).
     */
    private static function readChecked(string $path): string
    {
        $seen   = @lstat($path);
        $handle = @fopen($path, 'rb');
        if (false === $seen || false === $handle) {
            throw new CredentialsRefused(sprintf('cannot read %s', self::tildify($path)), sprintf('check the permissions of %s', self::tildify($path)));
        }

        try {
            $same = fstat($handle);
            if (false === $same || $same['ino'] !== $seen['ino'] || $same['dev'] !== $seen['dev']) {
                throw new CredentialsRefused(sprintf('refused: %s changed while it was being read', self::tildify($path)), 'retry; if it keeps happening, something is swapping the file');
            }
            $text = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return false === $text ? '' : $text;
    }

    // ------------------------------------------------------------------ write ---

    /**
     * Mint only if absent (`--init`, and the HTTP-boot mint). Compare-and-set: if a well-formed
     * key appears while we hold the lock (ffx or the MCP minted one), keep that one. Returns
     * whether this call created the key, and the fingerprint of the key now on disk — read
     * back, trusting the file rather than our own mint.
     *
     * @param bool $replaceMalformed an explicit --init replaces a malformed key (like `ffx key init`);
     *                               the automatic boot mint never does
     *
     * @return array{created: bool, fingerprint: string}
     */
    public function init(string $createdBy = self::CREATED_BY, bool $replaceMalformed = true): array
    {
        $created = false;
        $this->writeMerged(static function (stdClass $doc) use (&$created, $createdBy, $replaceMalformed): bool {
            $existing = self::machineBlock($doc)?->api_key ?? null;
            if (self::isWellFormedKey($existing)) {
                return false; // someone else won the race — keep theirs, write nothing
            }
            if (null !== $existing && !$replaceMalformed) {
                throw new CredentialsRefused('firefly_iii.machine.api_key is malformed', 'php artisan firefly-machine:key --rotate');
            }
            self::setMachineBlock($doc, self::mintKey(), $createdBy);
            $created = true;

            return true;
        });

        return ['created' => $created, 'fingerprint' => $this->readBackFingerprint()];
    }

    /**
     * Mint unconditionally (rotation, §4.8). Outstanding confirm tokens die with the old key.
     *
     * @return array{previous: null|string, fingerprint: string}
     */
    public function rotate(string $createdBy = self::CREATED_BY): array
    {
        $previous = null;
        $this->writeMerged(static function (stdClass $doc) use (&$previous, $createdBy): bool {
            $existing = self::machineBlock($doc)?->api_key ?? null;
            $previous = self::isWellFormedKey($existing) ? self::fingerprint($existing) : null;
            self::setMachineBlock($doc, self::mintKey(), $createdBy);

            return true;
        });

        return ['previous' => $previous, 'fingerprint' => $this->readBackFingerprint()];
    }

    /**
     * Merge-write: read, mutate our subtree, write back — preserving every key we do not own.
     * $mutate receives the document and returns false to skip the write.
     *
     * @param Closure(stdClass): bool $mutate
     */
    public function writeMerged(Closure $mutate): void
    {
        if (null === $this->path) {
            throw new CredentialsRefused('no credentials file is configured', 'set FIREFLY_MACHINE_CREDENTIALS_FILE');
        }
        $path = $this->path;
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            $old = umask(0o077);

            try {
                if (!@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                    throw new CredentialsRefused(sprintf('cannot create %s', self::tildify($dir)), sprintf('mkdir -p %s && chmod 700 %s', self::tildify($dir), self::tildify($dir)));
                }
            } finally {
                umask($old);
            }
        }

        $this->withLock(function () use ($mutate, $path, $dir): void {
            $doc = $this->read() ?? new stdClass();
            if (false === $mutate($doc)) {
                return;
            }
            $json = self::encode($doc);
            $tmp  = sprintf('%s/.%s.%d.%s.tmp', $dir, basename($path), getmypid(), bin2hex(random_bytes(4)));
            $old  = umask(0o077);

            try {
                $fh = @fopen($tmp, 'x'); // O_CREAT|O_EXCL, 0600 from creation under umask 0077
                if (false === $fh) {
                    throw new CredentialsRefused(sprintf('cannot create a temp file in %s', self::tildify($dir)), sprintf('check the permissions of %s', self::tildify($dir)));
                }

                try {
                    fwrite($fh, $json);
                    fflush($fh);
                    fsync($fh);
                } finally {
                    fclose($fh);
                }
            } finally {
                umask($old);
            }
            clearstatcache(true, $path);
            if (is_link($path)) {
                @unlink($tmp);

                throw new CredentialsRefused(sprintf('refused: %s became a symlink during the write', self::tildify($path)), 'replace it with a regular file');
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);

                throw new CredentialsRefused(sprintf('cannot replace %s', self::tildify($path)), sprintf('check the permissions of %s', self::tildify($dir)));
            }
            @chmod($path, 0o600);
        });
        self::forget();
    }

    /**
     * The CLI's lock protocol: an O_EXCL `<file>.lock`, broken when older than 30 s, removed on
     * release. flock() is taken on it too while held.
     */
    private function withLock(Closure $fn): void
    {
        $lock     = $this->path.'.lock';
        $deadline = microtime(true) + self::LOCK_WAIT_MS / 1000;
        $handle   = false;
        $old      = umask(0o077);

        try {
            while (false === ($handle = @fopen($lock, 'x'))) {
                clearstatcache(true, $lock);
                $seen = @lstat($lock);
                if (false !== $seen && time() - $seen['mtime'] > self::LOCK_STALE_S) {
                    // break a stale lock only if it is still the SAME stale lock: two waiters that
                    // both saw it must not have the second delete the fresh lock the first took
                    clearstatcache(true, $lock);
                    $now = @lstat($lock);
                    if (false !== $now && $now['ino'] === $seen['ino'] && $now['dev'] === $seen['dev']) {
                        @unlink($lock);
                    }

                    continue;
                }
                if (microtime(true) > $deadline) {
                    throw new CredentialsRefused(
                        sprintf('could not lock %s — another process is writing the credentials file', self::tildify($lock)),
                        sprintf('retry; if nothing else is running, remove %s', self::tildify($lock)),
                    );
                }
                usleep(50_000);
            }
        } finally {
            umask($old);
        }
        flock($handle, LOCK_EX);
        $mine = fstat($handle);

        try {
            $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            // release only a lock that is still ours
            clearstatcache(true, $lock);
            $st = @lstat($lock);
            if (false !== $st && false !== $mine && $st['ino'] === $mine['ino'] && $st['dev'] === $mine['dev']) {
                @unlink($lock);
            }
        }
    }

    private function readBackFingerprint(): string
    {
        $key = self::machineBlock($this->read())?->api_key ?? null;
        if (!self::isWellFormedKey($key)) {
            throw new CredentialsRefused('the credentials file holds no well-formed key after the write', 'php artisan firefly-machine:key --rotate');
        }

        return self::fingerprint($key);
    }

    /**
     * Everything about the key except the key (`firefly-machine:key --show`).
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $out = ['file' => null === $this->path ? null : self::tildify($this->path), 'exists' => false];
        if (null === $this->path) {
            $out['problem'] = 'no credentials file is configured';

            return $out;
        }

        try {
            if (!self::checkFile($this->path)) {
                return $out;
            }
            $st            = (array) lstat($this->path);
            $out['exists'] = true;
            $out['mode']   = sprintf('%04o', $st['mode'] & 0o777);
            $machine       = self::machineBlock($this->read());
            $key           = $machine?->api_key ?? null;
            if (self::isWellFormedKey($key)) {
                $out['fingerprint'] = self::fingerprint($key);
            } else {
                $out['problem'] = null === $key ? 'no firefly_iii.machine.api_key' : 'api_key is malformed';
            }
            foreach (['created', 'created_by', 'label'] as $field) {
                if (isset($machine->{$field}) && is_string($machine->{$field})) {
                    $out[$field] = $machine->{$field};
                }
            }
            $root = $this->statementsRoot();
            if (null !== $root) {
                $out['statements_root'] = $root;
            }
        } catch (CredentialsRefused $e) {
            $out['problem'] = $e->getMessage();
            $out['fix']     = $e->fix;
        }

        return $out;
    }

    // ---------------------------------------------------------------- helpers ---

    private static function machineBlock(?stdClass $doc): ?stdClass
    {
        $product = null === $doc ? null : ($doc->{self::APP_KEY} ?? null);
        $machine = $product instanceof stdClass ? ($product->machine ?? null) : null;

        return $machine instanceof stdClass ? $machine : null;
    }

    /** Replace firefly_iii.machine; keep every other key under firefly_iii (statements, web_login…). */
    private static function setMachineBlock(stdClass $doc, string $key, string $createdBy): void
    {
        $product = $doc->{self::APP_KEY} ?? null;
        if (!$product instanceof stdClass) {
            $product = new stdClass();
        }
        $machine             = new stdClass();
        $machine->api_key    = $key;
        $machine->created    = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $machine->created_by = $createdBy;
        $machine->label      = (string) (gethostname() ?: 'localhost');
        $product->machine    = $machine;
        $doc->{self::APP_KEY} = $product;
    }

    /** JSON the way the CLI writes it: two-space indent, trailing newline. */
    private static function encode(stdClass $doc): string
    {
        $json = (string) json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        // PHP indents with four spaces; string values never contain a raw newline, so every
        // leading run of spaces is indentation.
        $json = (string) preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);

        return $json."\n";
    }

    /** A value from the process environment or .env — never from config (§17). */
    private static function envValue(string $name): ?string
    {
        $value = Env::get($name);
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private static function home(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if (!is_string($home) || '' === $home) {
            $info = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid()) : false;
            $home = is_array($info) ? (string) $info['dir'] : sys_get_temp_dir();
        }

        return rtrim($home, '/');
    }

    private static function expandHome(string $path): string
    {
        if ('~' === $path) {
            return self::home();
        }
        if (str_starts_with($path, '~/')) {
            return self::home().substr($path, 1);
        }

        return $path;
    }

    /** Messages name files with "~/", never the absolute home path (§16.2). */
    public static function tildify(string $path): string
    {
        $home = self::home();

        return str_starts_with($path, $home.'/') ? '~'.substr($path, strlen($home)) : $path;
    }
}
