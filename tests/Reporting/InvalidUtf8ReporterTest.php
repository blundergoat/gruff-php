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
 * JSON assertions inspect decoded finding fields; hotspot and SARIF assertions check the escaped replacement character.
 * Valid-input assertions reject the escaped replacement character in each reporter's output.
 */
final class InvalidUtf8ReporterTest extends TestCase
{
    /** Replacement character substituted for invalid byte sequences. */
    private const SUBSTITUTION = "\u{FFFD}";

    /**
     * Verify the JSON reporter substitutes invalid bytes and still emits decodable JSON.
     *
     * @throws JsonException - If rendering or decoding the report fails.
     *
     * @return void - Asserts decoded message and file fields contain U+FFFD in place of invalid bytes.
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
     * Verify the hotspot report remains decodable and includes an escaped replacement character.
     *
     * @throws JsonException - If rendering or decoding the report fails.
     *
     * @return void - Asserts a decoded array and an escaped U+FFFD in the rendered JSON.
     */
    public function testHotspotReporterSubstitutesInvalidBytesAndStaysDecodable(): void
    {
        $rendered = (new HotspotReporter())->render($this->reportWithInvalidBytes());

        $decoded = json_decode($rendered, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        // The reporter JSON-escapes the replacement character, so the rendered text contains \ufffd.
        self::assertStringContainsString('\ufffd', $rendered);
    }

    /**
     * Verify the SARIF reporter substitutes invalid bytes instead of degrading to its encode-error object.
     *
     * @throws JsonException - If the rendered JSON cannot be decoded.
     *
     * @return void - Asserts a decoded array, no root error key, version 2.1.0, and an escaped U+FFFD.
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
     * Verify valid-input reporter output contains no escaped replacement character.
     *
     * @return void - Asserts that JSON, hotspot, and SARIF output omit the JSON escape for U+FFFD.
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
     * @return AnalysisReport - Scored report with invalid UTF-8 in its finding message and file path.
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
     * Build one warning finding with a caller-supplied message and file path.
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
