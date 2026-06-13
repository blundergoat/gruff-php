<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Security;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\ReflectedXssRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers the reflected-XSS rule: request data reaching output sinks, with
 * escaping/casting recognised as safe and local aliases followed in-scope.
 */
final class ReflectedXssRuleTest extends TestCase
{
    /** Project root used to resolve fixtures. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /** Fixture exercising unsafe and safe output sinks. */
    private const FIXTURE = 'tests/Fixtures/Security/reflected-xss.php';

    /**
     * Verify only unescaped request-derived output is flagged.
     *
     * @return void
     */
    public function testUnescapedRequestOutputDetected(): void
    {
        $findings = $this->findings(self::FIXTURE);

        $lines = array_map(static fn(Finding $finding): ?int => $finding->line, $findings);
        sort($lines);

        // directEcho, concatEcho, printSink, printfSink, aliasedEcho, serverEcho.
        self::assertSame([19, 29, 39, 49, 60, 70], $lines);
    }

    /**
     * Verify each finding carries safe, value-free metadata.
     *
     * @return void
     */
    public function testFindingsCarryStableMetadataAndNoRawValues(): void
    {
        $findings = $this->findings(self::FIXTURE);

        self::assertCount(6, $findings);
        self::assertSame(array_fill(0, 6, Severity::Warning), array_map(static fn(Finding $finding): Severity => $finding->severity, $findings));
        self::assertSame(array_fill(0, 6, Pillar::Security), array_map(static fn(Finding $finding): Pillar => $finding->pillar, $findings));
        self::assertSame(array_fill(0, 6, Confidence::Medium), array_map(static fn(Finding $finding): Confidence => $finding->confidence, $findings));

        $sinks        = array_map(static fn(Finding $finding): mixed => $finding->metadata['sink'] ?? null, $findings);
        $allowedSinks = ['echo', 'print', 'printf', 'vprintf'];
        $unknownSinks = array_values(array_filter(
                                         $sinks,
                                         static fn(mixed $sink): bool => !is_string($sink) || !in_array($sink, $allowedSinks, true),
                                     ));
        self::assertSame([], $unknownSinks);
        self::assertContains('echo', $sinks);
        self::assertContains('print', $sinks);
        self::assertContains('printf', $sinks);
    }

    /**
     * Verify escaped, cast, literal, and non-tainted output never fires.
     *
     * @return void
     */
    public function testEscapedAndSafeOutputIgnored(): void
    {
        $findings = $this->findings(self::FIXTURE);

        // Every safe method (escapedEcho, castEcho, escapedAlias, literalEcho,
        // bladeEscapeHelper, safePrintf, nonTaintedLocal) is defined after the
        // last unsafe sink (serverEcho on line 70), so no finding may land below it.
        foreach ($findings as $finding) {
            self::assertNotNull($finding->line, 'Reflected-XSS findings must remain line-addressable.');
            self::assertLessThanOrEqual(70, $finding->line, 'Safe output below line 70 must not fire.');
        }
    }

    /**
     * Verify JSON encoding does not count as escaping for an HTML output sink.
     *
     * @return void
     */
    public function testJsonEncodeDoesNotCountAsHtmlEscaping(): void
    {
        $findings = $this->findingsForSource(<<<'PHP'
<?php
function render(): void
{
    echo json_encode($_GET['name']);
}
PHP);

        self::assertCount(1, $findings);
        self::assertSame(4, $findings[0]->line);
    }

    /**
     * Analyse a fixture and return only reflected-XSS findings.
     *
     * @param string $displayPath - Fixture display path.
     *
     * @return list<Finding> - reflected-XSS findings for the fixture in registry order; empty when the rule fires none
     */
    private function findings(string $displayPath): array
    {
        $unit     = (new PhpFileParser())->parse(new SourceFile(
                                                     self::PROJECT_ROOT . '/' . $displayPath,
                                                     $displayPath,
                                                     SourceFile::TYPE_PHP,
                                                 ));
        $registry = RuleRegistry::defaults();
        $findings = $registry->analyse([$unit], new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry($registry)));

        return array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === ReflectedXssRule::ID));
    }

    /**
     * Analyse inline PHP source and return reflected-XSS findings.
     *
     * @param string $source - PHP source to parse.
     *
     * @return list<Finding> - reflected-XSS findings for the source
     */
    private function findingsForSource(string $source): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-xss-');
        self::assertIsString($path);
        file_put_contents($path, $source);

        try {
            $unit     = (new PhpFileParser())->parse(new SourceFile($path, 'inline.php', SourceFile::TYPE_PHP));
            $registry = RuleRegistry::defaults();
            $findings = $registry->analyse([$unit], new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry($registry)));

            return array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === ReflectedXssRule::ID));
        } finally {
            unlink($path);
        }
    }
}
