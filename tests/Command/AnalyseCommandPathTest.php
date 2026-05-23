<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Command;

use GruffPhp\Command\AnalyseCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers AnalyseCommand path normalization behavior.
 */
final class AnalyseCommandPathTest extends TestCase
{
    /**
     * Verify absolute requested paths are canonicalized before root trimming.
     *
     * @return void
     */
    public function testNormaliseRequestedPathsCanonicalizesAbsoluteDotSegments(): void
    {
        $reflection = new ReflectionClass(new AnalyseCommand());
        $method     = $reflection->getMethod('normaliseRequestedPaths');
        $method->setAccessible(true);

        self::assertSame(['src'], $method->invoke(new AnalyseCommand(), '/repo', ['/repo/../repo/src']));
    }
}
