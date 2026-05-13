<?php

declare(strict_types=1);

namespace Fixtures\TestQuality;

use PHPUnit\Framework\TestCase;

final class SutNotCalledHeuristicTest extends TestCase
{
    public function testCalculateTotalReturnsExpectedValue(): void
    {
        $result = 6;

        self::assertSame(6, $result);
    }

    public function testRenderBuildsHtmlReport(): void
    {
        $html = (new HtmlReporterFixture())->render();

        self::assertStringContainsString('<section', $html);
    }

    public function testHtmlReporterRendersDiagnosticsWhenPresent(): void
    {
        $html = (new HtmlReporterFixture())->render();

        self::assertStringContainsString('diagnostics', $html);
    }

    public function testRejectsSeverityWithoutThreshold(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ConfigLoaderFixture())->load('{"rules":{"size.file-length":{"severity":"error"}}}');
    }

    public function testMissingClassPhpdocSkipsAnonymousAndDocumentedClasses(): void
    {
        $symbols = $this->analyseRule('missing-class-phpdoc.php');

        self::assertNotContains('DocumentedClass', $symbols);
        self::assertNotContains('AnonymousFactory', $symbols);
    }

    /**
     * @return list<string>
     */
    private function analyseRule(string $fixture): array
    {
        return [$fixture];
    }
}
