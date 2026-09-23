<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Wrong on purpose: the pull request this belongs to proves that a failing test fails the build. It asks the
 * filesystem rather than comparing two constants, so that static analysis cannot see it coming.
 */
final class BuildFailsOnAFailingTest extends TestCase
{
    public function testTheBuildFailsWhenATestFails(): void
    {
        self::assertFileExists(__FILE__ . '.that-does-not-exist');
    }
}
