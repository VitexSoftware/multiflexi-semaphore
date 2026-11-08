<?php

declare(strict_types=1);

/**
 * This file is part of the MultiFlexi package
 *
 * https://multiflexi.eu/
 *
 * (c) Vítězslav Dvořák <http://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Test\MultiFlexi;

use MultiFlexi\Action\Semaphore;
use MultiFlexi\CommonAction;
use PHPUnit\Framework\TestCase;

final class ActionSemaphoreTest extends TestCase
{
    public function testExtendsCommonAction(): void
    {
        $this->assertTrue(is_a(Semaphore::class, CommonAction::class, true));
    }

    public function testNameAndDescription(): void
    {
        $this->assertSame('Semaphore', Semaphore::name());
        $this->assertNotEmpty(Semaphore::description());
    }

    public function testDefaultEnvKeysContainEventVariables(): void
    {
        $this->assertStringContainsString('EVENT_RECORD_ID', Semaphore::DEFAULT_ENV_KEYS);
        $this->assertStringContainsString('EVENT_EVIDENCE', Semaphore::DEFAULT_ENV_KEYS);
    }
}
