<?php

/*
 * HandlerHookTest.php
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

namespace Tests\Machine\ErrorFile;

use FireflyIII\Machine\Http\Controllers\BatchController;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Machine\Core\Fixtures\ProbeRoutes;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx §4.5 and the §15 HandlerHookTest row, end to end through the HTTP kernel:
 * a probe that throws → exactly one `[ERROR] [php-machine]` line whose `where` is the probe's own
 * file and line; the render duplicate is not a second line; a logged-out web GET
 * (AuthenticationException) and a failed web-form validation → zero lines; a batch whose op
 * throws → exactly one `[php-machine]` line, under the sub-request's route, with the batch's rid.
 *
 * @internal
 */
#[CoversNothing]
final class HandlerHookTest extends MachineTestCase
{
    use ErrorFileSandbox;

    protected array $machineFamilies = [ProbeRoutes::class];

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = $this->resetErrorFile();
    }

    protected function tearDown(): void
    {
        BatchController::resetTestOps();
        $this->clearErrorFile();
        parent::tearDown();
    }

    public function testAThrowingProbeWritesExactlyOnePhpMachineLine(): void
    {
        $this->operatorUser();
        $this->assertPlaneError($this->machine('GET', '/_probe/throw/boom', [], ['X-Firefly-Request-Id' => '0a1b2c3d', 'X-Firefly-Client' => 'ffx']), 500, 'internal');
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, implode("\n", self::lines($this->path)));
        $line = $headers[0];
        self::assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\] \[ERROR\] \[php-machine\] \[tests\/Machine\/Core\/Fixtures\/ProbeController\.php:\d+\] handling GET \/machine\/v1\/_probe\/throw\/\{kind\} — RuntimeException: /u', $line);
        self::assertStringContainsString('net=report', $line);
        self::assertStringContainsString('caller=ffx', $line);
        self::assertStringContainsString('rid=0a1b2c3d', $line);
        self::assertMatchesRegularExpression('/ took_ms=\d+ pid=\d+\}/', $line);
        self::assertStringNotContainsString('/Users/somebody', $line, 'home paths are scrubbed');
        self::assertStringContainsString('    at Tests\Machine\Core\Fixtures\ProbeController->throw (tests/Machine/Core/Fixtures/ProbeController.php:', implode("\n", self::lines($this->path)));
        self::assertSame(0o600, fileperms($this->path) & 0o777);
    }

    public function testAnAnswerWritesNothing(): void
    {
        $this->operatorUser();
        foreach (['machine' => 409, 'model' => 404, 'validation' => 400, 'authz' => 403] as $kind => $status) {
            $response = $this->machine('GET', '/_probe/throw/'.$kind);
            self::assertSame($status, $response->getStatusCode(), $kind.': '.$response->getContent());
        }
        self::assertSame([], self::lines($this->path));
    }

    public function testALoggedOutWebGetWritesNothing(): void
    {
        $response = $this->get('/accounts/asset');
        self::assertContains($response->getStatusCode(), [302, 401], (string) $response->getContent());
        self::assertSame([], self::lines($this->path));
    }

    public function testAFailedWebFormValidationWritesNothing(): void
    {
        $this->be($this->createAuthenticatedUser());
        $response = $this->post('/categories/store', ['name' => '']);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame([], self::lines($this->path));
    }

    public function testABatchWhoseOpThrowsWritesExactlyOneLine(): void
    {
        BatchController::registerTestOp('probe.categories', ['POST', '/_probe/categories']);
        BatchController::registerTestOp('probe.boom', ['POST', '/_probe/boom-write']);
        $this->operatorUser();
        $this->enableWrites();
        $env = $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [
            ['op' => 'probe.categories', 'names' => ['Synthetic Category']],
            ['op' => 'probe.boom'],
        ]], ['X-Firefly-Request-Id' => '7f3a9c01']), 500, 'internal');
        self::assertSame(1, $env['error']['details']['index']);

        $headers = self::headers($this->path);
        self::assertCount(1, $headers, implode("\n", self::lines($this->path)));
        self::assertStringContainsString('[ERROR] [php-machine] [tests/Machine/Core/Fixtures/ProbeController.php:', $headers[0]);
        self::assertStringContainsString('handling POST /machine/v1/_probe/boom-write — RuntimeException: failed half way', $headers[0], 'under the sub-request\'s route');
        self::assertStringContainsString('rid=7f3a9c01', $headers[0], 'with the batch call\'s rid');
    }

    public function testABatchWithoutAClientRidStillCarriesOneRid(): void
    {
        BatchController::registerTestOp('probe.boom', ['POST', '/_probe/boom-write']);
        $this->operatorUser();
        $this->enableWrites();
        $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 'probe.boom']]]), 500, 'internal');
        $headers = self::headers($this->path);
        self::assertCount(1, $headers);
        self::assertMatchesRegularExpression('/ rid=[0-9a-f]{8} /', $headers[0], 'minted lazily, only because a record was written');
    }
}
