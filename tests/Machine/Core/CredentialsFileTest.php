<?php

/*
 * CredentialsFileTest.php
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

namespace Tests\Machine\Core;

use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Machine\Credentials\CredentialsRefused;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §4.2–§4.5, §4.8 and §18 "Credentials": the mint shape and entropy source, the
 * merge that preserves other products' keys, 0644 and symlinks refused, the CLI-compatible
 * on-disk shape and lock, the fingerprint, and no mint on a test boot.
 *
 * @internal
 *
 * @coversNothing
 */
final class CredentialsFileTest extends MachineTestCase
{
    public function testMintIs64LowercaseHexFromTheCsprng(): void
    {
        $a = CredentialsFile::mintKey();
        $b = CredentialsFile::mintKey();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        $this->assertNotSame($a, $b);
        $this->assertTrue(CredentialsFile::isWellFormedKey($a));
        $this->assertFalse(CredentialsFile::isWellFormedKey(strtoupper($a)));
        $this->assertFalse(CredentialsFile::isWellFormedKey(substr($a, 1)));
        $this->assertFalse(CredentialsFile::isWellFormedKey('550e8400-e29b-41d4-a716-446655440000'));

        $source = (string) file_get_contents(base_path('app/Machine/Credentials/CredentialsFile.php'));
        $this->assertStringContainsString('random_bytes(32)', $source);
    }

    public function testNoWeakRandomnessAnywhereInThePlane(): void
    {
        foreach (self::planeFiles() as $file) {
            $code = self::codeOnly((string) file_get_contents($file));
            foreach (['/\brand\s*\(/', '/\bmt_rand\s*\(/', '/\buniqid\s*\(/', '/Str::random\s*\(/', '/\blcg_value\s*\(/'] as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $code, sprintf('%s uses a non-CSPRNG source (%s)', $file, $pattern));
            }
        }
    }

    public function testInitWritesTheCliShapeAt0600(): void
    {
        $path = $this->sandbox.'/fresh/.credentials/firefly_iii.json';
        $file = new CredentialsFile($path);
        $out  = $file->init();
        $this->assertTrue($out['created']);
        $this->assertFileExists($path);
        $this->assertSame(0o600, fileperms($path) & 0o777);
        $this->assertSame(0o700, fileperms(dirname($path)) & 0o777);
        $this->assertFileDoesNotExist($path.'.lock', 'the lock file is removed on release');

        $doc = json_decode((string) file_get_contents($path), true);
        $this->assertSame(['api_key', 'created', 'created_by', 'label'], array_keys($doc['firefly_iii']['machine']));
        $this->assertSame('firefly-web', $doc['firefly_iii']['machine']['created_by']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $doc['firefly_iii']['machine']['created']);
        $this->assertSame(CredentialsFile::fingerprint($doc['firefly_iii']['machine']['api_key']), $out['fingerprint']);
        // two-space indentation and a trailing newline, like JSON.stringify(doc, null, 2) + "\n"
        $text = (string) file_get_contents($path);
        $this->assertStringStartsWith("{\n  \"firefly_iii\": {\n    \"machine\": {", $text);
        $this->assertStringEndsWith("}\n", $text);
    }

    public function testInitReusesAnExistingKey(): void
    {
        $file   = new CredentialsFile($this->credentialsPath());
        $before = CredentialsFile::fingerprint($this->machineKey);
        $out    = $file->init();
        $this->assertFalse($out['created']);
        $this->assertSame($before, $out['fingerprint']);
        $this->assertSame($this->machineKey, json_decode((string) file_get_contents($this->credentialsPath()), true)['firefly_iii']['machine']['api_key']);
    }

    public function testTheMergePreservesEveryKeyItDoesNotOwn(): void
    {
        $this->writeCredentials([
            'actual_budget' => ['machine' => ['api_key' => str_repeat('f', 64)], 'empty' => new \stdClass(), 'list' => []],
            'firefly_iii'   => ['statements' => ['root' => '/tmp/invented/bank_statements'], 'web_login' => ['email' => 'ops@example.invalid']],
            'zzz'           => 12,
        ]);
        $file = new CredentialsFile($this->credentialsPath());
        $out  = $file->init();
        $this->assertTrue($out['created']);
        $raw  = (string) file_get_contents($this->credentialsPath());
        $doc  = json_decode($raw, true);
        $this->assertSame(str_repeat('f', 64), $doc['actual_budget']['machine']['api_key']);
        $this->assertSame('/tmp/invented/bank_statements', $doc['firefly_iii']['statements']['root']);
        $this->assertSame('ops@example.invalid', $doc['firefly_iii']['web_login']['email']);
        $this->assertSame(12, $doc['zzz']);
        $this->assertStringContainsString('"empty": {}', $raw, 'an empty object stays an object');
        $this->assertStringContainsString('"list": []', $raw);
        $this->assertSame('/tmp/invented/bank_statements', $file->statementsRoot());

        $rot  = $file->rotate();
        $doc2 = json_decode((string) file_get_contents($this->credentialsPath()), true);
        $this->assertNotSame($doc['firefly_iii']['machine']['api_key'], $doc2['firefly_iii']['machine']['api_key']);
        $this->assertSame($out['fingerprint'], $rot['previous']);
        $this->assertSame('/tmp/invented/bank_statements', $doc2['firefly_iii']['statements']['root']);
        $this->assertSame(str_repeat('f', 64), $doc2['actual_budget']['machine']['api_key']);
    }

    public function testALooseModeIsRefusedAndUnarms(): void
    {
        chmod($this->credentialsPath(), 0o644);
        CredentialsFile::forget();
        $this->assertNull(CredentialsFile::resolve());
        $this->assertStringContainsString('readable by others', (string) CredentialsFile::problem());
        $this->assertStringContainsString('chmod 600', (string) CredentialsFile::fix());
        $this->machine('GET', '/ping')->assertStatus(404);

        $this->expectException(CredentialsRefused::class);
        (new CredentialsFile($this->credentialsPath()))->init();
    }

    public function testASymlinkIsRefusedAtReadAndAtWrite(): void
    {
        $real = $this->sandbox.'/credentials/real.json';
        rename($this->credentialsPath(), $real);
        symlink($real, $this->credentialsPath());
        CredentialsFile::forget();
        $this->assertNull(CredentialsFile::resolve());
        $this->assertStringContainsString('symlink', (string) CredentialsFile::problem());
        $this->machine('GET', '/ping')->assertStatus(404);

        try {
            (new CredentialsFile($this->credentialsPath()))->rotate();
            $this->fail('a write through a symlink must be refused');
        } catch (CredentialsRefused $e) {
            $this->assertStringContainsString('symlink', $e->getMessage());
        }
        $this->assertSame($this->machineKey, json_decode((string) file_get_contents($real), true)['firefly_iii']['machine']['api_key'], 'the symlink target is untouched');
    }

    public function testAMalformedKeyUnarmsWithTheRotateHintAndIsNeverAutoReplaced(): void
    {
        $this->writeCredentials(['firefly_iii' => ['machine' => ['api_key' => 'NOT-A-KEY']]]);
        $this->assertNull(CredentialsFile::resolve());
        $this->assertStringContainsString('malformed', (string) CredentialsFile::problem());
        $this->assertStringContainsString('--rotate', (string) CredentialsFile::fix());
        $this->assertNull(CredentialsFile::resolveOrMint(), 'the boot mint never overwrites a malformed key');
        $this->assertStringContainsString('NOT-A-KEY', (string) file_get_contents($this->credentialsPath()));
    }

    public function testResolveOrMintMintsOnlyWhenAbsent(): void
    {
        unlink($this->credentialsPath());
        CredentialsFile::forget();
        $key = CredentialsFile::resolveOrMint();
        $this->assertNotNull($key);
        $this->assertSame('credentials-file', $key->source);
        $this->assertSame(0o600, fileperms($this->credentialsPath()) & 0o777);
        $again = CredentialsFile::resolveOrMint();
        $this->assertSame($key->fingerprint(), $again?->fingerprint());
    }

    public function testNoMintOnATestBoot(): void
    {
        unlink($this->credentialsPath());
        CredentialsFile::forget();
        // the provider's HTTP-boot arming is skipped under PHPUnit: a request leaves no file
        $this->machine('GET', '/ping')->assertStatus(404);
        $this->assertFileDoesNotExist($this->credentialsPath());
        // and with no configured file at all, the real home directory is never consulted
        config(['machine.credentials_file' => null]);
        $this->assertNull(CredentialsFile::configuredPath());
    }

    public function testARotationTakesEffectOnTheNextRequest(): void
    {
        $this->machine('GET', '/ping')->assertStatus(200);
        (new CredentialsFile($this->credentialsPath()))->rotate();
        $this->machine('GET', '/ping')->assertStatus(401);
        $this->machineKey = (string) json_decode((string) file_get_contents($this->credentialsPath()), true)['firefly_iii']['machine']['api_key'];
        $this->machine('GET', '/ping')->assertStatus(200);
    }

    public function testAnExternalRewriteIsNoticedThroughTheStatSignature(): void
    {
        $this->machine('GET', '/ping')->assertStatus(200);
        // what ffx does: a new file renamed over the old one (new inode)
        $new = str_repeat('a', 64);
        $tmp = $this->sandbox.'/credentials/.tmp';
        file_put_contents($tmp, json_encode(['firefly_iii' => ['machine' => ['api_key' => $new]]]));
        chmod($tmp, 0o600);
        rename($tmp, $this->credentialsPath());
        $this->machine('GET', '/ping')->assertStatus(401);
        $this->machineKey = $new;
        $this->machine('GET', '/ping')->assertStatus(200);
    }

    public function testEnvAndKeyFileSourcesComeFirst(): void
    {
        $env = str_repeat('b', 64);
        $_SERVER['FIREFLY_MACHINE_KEY'] = $env;
        $_ENV['FIREFLY_MACHINE_KEY']    = $env;
        putenv('FIREFLY_MACHINE_KEY='.$env);
        CredentialsFile::forget();

        try {
            $this->assertSame('env', CredentialsFile::resolve()?->source);
            $this->machineKey = $env;
            $this->machine('GET', '/ping')->assertStatus(200);
        } finally {
            unset($_SERVER['FIREFLY_MACHINE_KEY'], $_ENV['FIREFLY_MACHINE_KEY']);
            putenv('FIREFLY_MACHINE_KEY');
        }

        $keyFile = $this->sandbox.'/key.txt';
        file_put_contents($keyFile, str_repeat('c', 64)."\n");
        chmod($keyFile, 0o600);
        $_SERVER['FIREFLY_MACHINE_KEY_FILE'] = $keyFile;
        $_ENV['FIREFLY_MACHINE_KEY_FILE']    = $keyFile;
        putenv('FIREFLY_MACHINE_KEY_FILE='.$keyFile);
        CredentialsFile::forget();

        try {
            $this->assertSame('key-file', CredentialsFile::resolve()?->source);
            chmod($keyFile, 0o644);
            CredentialsFile::forget();
            $this->assertNull(CredentialsFile::resolve(), 'a loose key file is refused too');
        } finally {
            unset($_SERVER['FIREFLY_MACHINE_KEY_FILE'], $_ENV['FIREFLY_MACHINE_KEY_FILE']);
            putenv('FIREFLY_MACHINE_KEY_FILE');
            CredentialsFile::forget();
        }
    }

    public function testTheFingerprintMatchesTheClients(): void
    {
        $key = '4f2a'.str_repeat('0', 60);
        $this->assertSame('4f2a…/sha256:'.substr(hash('sha256', $key), 0, 4), CredentialsFile::fingerprint($key));
        // the CLI's implementation is the same formula
        $cli = (string) file_get_contents(base_path('cli/code/src/credentials.ts'));
        $this->assertStringContainsString("return `\${key.slice(0, 4)}…/sha256:\${digest.slice(0, 4)}`;", $cli);
    }

    public function testAStaleLockIsBroken(): void
    {
        $lock = $this->credentialsPath().'.lock';
        touch($lock, time() - 120);
        $out  = (new CredentialsFile($this->credentialsPath()))->rotate();
        $this->assertNotSame(CredentialsFile::fingerprint($this->machineKey), $out['fingerprint']);
        $this->assertFileDoesNotExist($lock);
    }

    public function testTheKeyNeverAppearsInAnyPlaneResponseOrTheAuditLog(): void
    {
        $this->operatorUser();
        $bodies = '';
        foreach (['/ping', '/whoami', '/capabilities', '/health?probe=true', '/nope'] as $path) {
            $bodies .= (string) $this->machine('GET', $path)->getContent();
        }
        $this->enableWrites();
        $bodies .= (string) $this->machine('POST', '/transactions', ['transactions' => []])->getContent();
        $bodies .= (string) @file_get_contents($this->sandbox.'/state/machine.audit');
        $this->assertStringNotContainsString($this->machineKey, $bodies);
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $bodies);
    }

    /** @return list<string> */
    public static function planeFiles(): array
    {
        $files = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app/Machine', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $files[] = $f->getPathname();
            }
        }
        $files[] = dirname(__DIR__, 3).'/routes/machine.php';
        sort($files);

        return $files;
    }

    /** PHP source with comments and strings removed. */
    public static function codeOnly(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
