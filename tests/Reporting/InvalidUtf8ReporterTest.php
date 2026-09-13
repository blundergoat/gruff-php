<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Output\Reporter\HotspotReporter;
use GruffPhp\Output\Reporter\JsonReporter;
use GruffPhp\Output\Reporter\SarifReporter;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Results\Scoring\ScoreCalculator;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * Covers machine-readable reporter behaviour when source-derived strings carry invalid UTF-8 bytes.
 */
final class InvalidUtf8ReporterTest extends TestCase
{
    /** Replacement character substituted for invalid byte sequences. */
    private const SUBSTITUTION = "\u{FFFD}";

    /**
     * Verify the JSON reporter substitutes invalid bytes and still emits decodable JSON.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testJsonReporterSubstitutesInvalidBytesAndStaysDecodable(): void
    {
        $rendered = (new JsonReporter())->render($this->reportWithInvalidBytes());

        $decoded = json_decode($rendered, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $findings = $decoded['findings'] ?? null;
        self::assertIsArray($findings);
        $first = $findings[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('Bad ' . self::SUBSTITUTION . ' byte.', $first['message'] ?? null);
        self::assertSame('src/f' . self::SUBSTITUTION . 'o.php', $first['file'] ?? null);
    }

    /**
     * Verify the hotspot reporter substitutes invalid path bytes and still emits decodable JSON.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testHotspotReporterSubstitutesInvalidBytesAndStaysDecodable(): void
    {
        $rendered = (new HotspotReporter())->render($this->reportWithInvalidBytes());

        $decoded = json_decode($rendered, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        // Non-ASCII stays escaped in rendered output, so the substitution appears as the � escape.
        self::assertStringContainsString('\ufffd', $rendered);
    }

    /**
     * Verify the SARIF reporter substitutes invalid bytes instead of degrading to its encode-error object.
     *
     * @throws JsonException
     *
     * @return void
     */
    public function testSarifReporterSubstitutesInvalidBytesAndStaysDecodable(): void
    {
        $rendered = (new SarifReporter())->render($this->reportWithInvalidBytes());

        $decoded = json_decode($rendered, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('error', $decoded);
        self::assertSame('2.1.0', $decoded['version'] ?? null);
        self::assertStringContainsString('\ufffd', $rendered);
    }

    /**
     * Verify ordinary valid-UTF-8 reports gain no substitution characters from the new flag.
     *
     * @return void
     */
    public function testCleanReportsGainNoSubstitutionCharacters(): void
    {
        $cleanReport = $this->report([$this->finding(message: 'Clean finding.', filePath: 'src/Clean.php')]);

        self::assertStringNotContainsString('\ufffd', (new JsonReporter())->render($cleanReport));
        self::assertStringNotContainsString('\ufffd', (new HotspotReporter())->render($cleanReport));
        self::assertStringNotContainsString('\ufffd', (new SarifReporter())->render($cleanReport));
    }

    /**
     * Build a scored report whose finding message and file path carry invalid UTF-8 bytes.
     *
     * @return AnalysisReport - report exercising every reporter surface that embeds source-derived strings
     */
    private function reportWithInvalidBytes(): AnalysisReport
    {
        return $this->report([$this->finding(message: "Bad \xB1 byte.", filePath: "src/f\xB1o.php")]);
    }

    /**
     * Build a report over the given findings with a computed score.
     *
     * @param list<Finding> $findings - Findings to attach and score.
     *
     * @return AnalysisReport - report carrying the findings plus top-offender score data derived from them
     */
    private function report(array $findings): AnalysisReport
    {
        return new AnalysisReport(
            toolVersion:     '0.1.0-test',
            requestedPaths:  ['src'],
            format:          'json',
            failOn:          'none',
            filesDiscovered: count($findings),
            filesParsed:     count($findings),
            ignoredPaths:    [],
            missingPaths:    [],
            diagnostics:     [],
            findings:        $findings,
            exitCode:        0,
            score:           (new ScoreCalculator())->calculate($findings, 10, null, DiffResult::inactive()),
            diff:            DiffResult::inactive(),
        );
    }

    /**
     * Build one warning finding with controllable identity strings.
     *
     * @param string $message - Finding message; the invalid-byte cases inject raw non-UTF-8 here.
     * @param string $filePath - Display path; also exercised with raw invalid bytes.
     *
     * @return Finding - single security warning carrying the given message and path
     */
    private function finding(string $message, string $filePath): Finding
    {
        return new Finding(
            ruleId:     'security.dangerous-function-call',
            message:    $message,
            filePath:   $filePath,
            line:       3,
            severity:   Severity::Warning,
            pillar:     Pillar::Security,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
        );
    }
}
