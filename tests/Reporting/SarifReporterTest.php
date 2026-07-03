<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Results\Mutation\InfectionMutant;
use GruffPhp\Results\Mutation\InfectionReport;
use GruffPhp\Results\Mutation\MutationAnalysisResult;
use GruffPhp\Results\Mutation\MutationFindingFactory;
use GruffPhp\Output\Reporter\JsonReporter;
use GruffPhp\Output\Reporter\SarifReporter;
use GruffPhp\Results\Scoring\ScoreCalculator;
use GruffPhp\Results\Scoring\ScoreReport;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers SARIF report rendering contracts.
 *
 * @phpstan-type JsonScalar bool|float|int|object|string|null
 * @phpstan-type JsonLevel8 array<array-key, JsonScalar>
 * @phpstan-type JsonLevel7 array<array-key, JsonScalar|JsonLevel8>
 * @phpstan-type JsonLevel6 array<array-key, JsonScalar|JsonLevel7>
 * @phpstan-type JsonLevel5 array<array-key, JsonScalar|JsonLevel6>
 * @phpstan-type JsonLevel4 array<array-key, JsonScalar|JsonLevel5>
 * @phpstan-type JsonLevel3 array<array-key, JsonScalar|JsonLevel4>
 * @phpstan-type JsonLevel2 array<array-key, JsonScalar|JsonLevel3>
 * @phpstan-type JsonLevel1 array<array-key, JsonScalar|JsonLevel2>
 * @phpstan-type JsonArray array<array-key, JsonScalar|JsonLevel1>
 * @phpstan-type JsonValue JsonScalar|JsonLevel1|JsonLevel2|JsonLevel3|JsonLevel4|JsonLevel5|JsonLevel6|JsonLevel7|JsonLevel8|JsonArray
 */
final class SarifReporterTest extends TestCase
{
    /**
     * Verify SARIF output preserves native report identity while exposing code-scanning metadata.
     *
     * @return void
     * @throws JsonException
     */
    public function testSarifReporterEmitsRegistryRulesResultIdentityAndRunProperties(): void
    {
        $finding  = new Finding(
            ruleId:           'security.dangerous-function-call',
            message:          'Dangerous call to eval().',
            filePath:         './src\\app.php',
            line:             12,
            severity:         Severity::Error,
            pillar:           Pillar::Security,
            tier:             RuleTier::V01,
            confidence:       Confidence::High,
            endLine:          13,
            column:           5,
            symbol:           'run',
            remediation:      'Avoid eval.',
            secondaryPillars: [Pillar::Maintainability],
            metadata:         ['target' => 'eval'],
        );
        $findings = [$finding];
        $score    = (new ScoreCalculator())->calculate($findings, null, DiffResult::inactive());
        $report   = $this->report($findings, $score);

        $payload       = $this->decode((new SarifReporter())->render($report));
        $sarifRun      = $this->stringKeyedArray($this->listValue($payload, 'runs')[0] ?? null);
        $driver        = $this->stringKeyedArray($this->stringKeyedArray($this->stringKeyedArray($sarifRun, 'tool'), 'driver'));
        $rules         = $this->listValue($driver, 'rules');
        $ruleIds       = array_map(
            fn(mixed $rule): string => $this->stringValue($this->stringKeyedArray($rule), 'id'),
            $rules,
        );
        $sortedRuleIds = $ruleIds;
        sort($sortedRuleIds, SORT_STRING);
        $result    = $this->stringKeyedArray($this->listValue($sarifRun, 'results')[0] ?? null);
        $ruleIndex = $result['ruleIndex'] ?? null;
        self::assertIsInt($ruleIndex);
        $matchingRule = $this->stringKeyedArray($rules[$ruleIndex] ?? null);

        self::assertSame('2.1.0', $this->stringValue($payload, 'version'));
        self::assertSame('gruff-php', $this->stringValue($driver, 'name'));
        self::assertSame('0.1.0-test', $this->stringValue($driver, 'semanticVersion'));
        self::assertArrayNotHasKey('informationUri', $driver);
        self::assertSame($sortedRuleIds, $ruleIds);
        self::assertContains('size.file-length', $ruleIds);
        self::assertSame('security.dangerous-function-call', $this->stringValue($result, 'ruleId'));
        self::assertSame('security.dangerous-function-call', $this->stringValue($matchingRule, 'id'));
        self::assertSame('Dangerous function calls', $this->stringValue($matchingRule, 'name'));
        self::assertSame('warning', $this->stringValue($this->stringKeyedArray($matchingRule, 'properties'), 'defaultSeverity'));
        self::assertSame('error', $this->stringValue($result, 'level'));
        self::assertSame('Dangerous call to eval().', $this->stringValue($this->stringKeyedArray($result, 'message'), 'text'));
        self::assertSame('src/app.php', $this->stringValue(
            $this->stringKeyedArray(
                $this->stringKeyedArray(
                    $this->stringKeyedArray($this->listValue($result, 'locations')[0] ?? null),
                    'physicalLocation',
                ),
                'artifactLocation',
            ),
            'uri',
        ));
        $region = $this->stringKeyedArray(
            $this->stringKeyedArray($this->listValue($result, 'locations')[0] ?? null),
            'physicalLocation',
        )['region'];
        self::assertIsArray($region);
        $expectedStartLine   = $finding->line;
        $expectedStartColumn = $finding->column;
        $expectedEndLine     = $finding->endLine;
        self::assertSame($expectedStartLine, $region['startLine'] ?? null);
        self::assertSame($expectedStartColumn, $region['startColumn'] ?? null);
        self::assertSame($expectedEndLine, $region['endLine'] ?? null);
        $partialFingerprints = $this->stringKeyedArray($result, 'partialFingerprints');
        self::assertSame($finding->fingerprint(), $this->stringValue($partialFingerprints, 'gruffFingerprint'));
        self::assertSame($finding->stableIdentity(), $this->stringValue($partialFingerprints, 'gruffStableIdentity'));
        self::assertArrayNotHasKey('primary', $partialFingerprints);
        $resultProperties = $this->stringKeyedArray($result, 'properties');
        self::assertSame(['maintainability'], $this->listValue($resultProperties, 'secondaryPillars'));
        self::assertSame('eval', $this->stringKeyedArray($resultProperties, 'metadata')['target'] ?? null);
        $runProperties = $this->stringKeyedArray($sarifRun, 'properties');
        self::assertSame('gruff.analysis.v2', $this->stringValue($runProperties, 'gruffSchemaVersion'));
        self::assertSame($score->composite->score, $runProperties['score'] ?? null);
        self::assertSame($score->composite->letter, $runProperties['grade'] ?? null);
        self::assertSame('gruff.analysis.v2', $this->stringValue($this->decode((new JsonReporter())->render($report)), 'schemaVersion'));
        self::assertArrayNotHasKey('codeFlows', $result);
        self::assertArrayNotHasKey('threadFlows', $result);
        self::assertArrayNotHasKey('fixes', $result);
    }

    /**
     * Verify empty reports stay valid SARIF while omitting absent score data.
     *
     * @return void
     * @throws JsonException
     */
    public function testSarifReporterHandlesEmptyReportAndOmittedScore(): void
    {
        $payload  = $this->decode((new SarifReporter())->render($this->report([])));
        $sarifRun = $this->stringKeyedArray($this->listValue($payload, 'runs')[0] ?? null);
        $driver   = $this->stringKeyedArray($this->stringKeyedArray($this->stringKeyedArray($sarifRun, 'tool'), 'driver'));

        self::assertSame([], $this->listValue($sarifRun, 'results'));
        self::assertContains('size.file-length', array_map(
            fn(mixed $rule): string => $this->stringValue($this->stringKeyedArray($rule), 'id'),
            $this->listValue($driver, 'rules'),
        ));
        $runProperties = $this->stringKeyedArray($sarifRun, 'properties');
        self::assertSame('gruff.analysis.v2', $this->stringValue($runProperties, 'gruffSchemaVersion'));
        self::assertArrayNotHasKey('score', $runProperties);
        self::assertArrayNotHasKey('grade', $runProperties);
    }

    /**
     * Verify severity values map to SARIF result levels.
     *
     * @param Severity $severity - Finding severity to render.
     * @param string   $level - Expected SARIF level.
     *
     * @return void
     * @throws JsonException
     */
    #[DataProvider('severityLevels')]
    public function testSarifReporterMapsSeverityLevels(Severity $severity, string $level): void
    {
        $payload = $this->decode((new SarifReporter())->render($this->report([
                                                                                 $this->finding(severity: $severity),
                                                                             ])));
        $result  = $this->stringKeyedArray($this->listValue($this->sarifRun($payload), 'results')[0] ?? null);

        self::assertSame($level, $this->stringValue($result, 'level'));
    }

    /**
     * Provide SARIF severity examples for reporter tests.
     *
     * @return iterable<string, array{0: Severity, 1: string}> - data-provider cases keyed by case name, each pairing a finding severity with its
     *                          expected SARIF level
     */
    public static function severityLevels(): iterable
    {
        yield 'error' => [Severity::Error, 'error'];
        yield 'warning' => [Severity::Warning, 'warning'];
        yield 'advisory' => [Severity::Advisory, 'note'];
    }

    /**
     * Verify the stable partial fingerprint survives line drift while the precise fingerprint does not.
     *
     * @return void
     * @throws JsonException
     */
    public function testSarifReporterStableIdentitySurvivesLineShiftWhilePreciseFingerprintChanges(): void
    {
        $lineBeforeShift = 10;
        $lineAfterShift  = 20;
        $payload         = $this->decode((new SarifReporter())->render($this->report([
                                                                                         $this->finding(line: $lineBeforeShift),
                                                                                         $this->finding(line: $lineAfterShift),
                                                                                     ])));
        $results         = $this->listValue($this->sarifRun($payload), 'results');
        $first           = $this->stringKeyedArray($this->stringKeyedArray($results[0] ?? null), 'partialFingerprints');
        $second          = $this->stringKeyedArray($this->stringKeyedArray($results[1] ?? null), 'partialFingerprints');

        self::assertSame(
            $this->stringValue($first, 'gruffStableIdentity'),
            $this->stringValue($second, 'gruffStableIdentity'),
        );
        self::assertNotSame(
            $this->stringValue($first, 'gruffFingerprint'),
            $this->stringValue($second, 'gruffFingerprint'),
        );
    }

    /**
     * Verify non-registry mutation findings receive deterministic fallback driver rules.
     *
     * @return void
     * @throws JsonException
     */
    public function testSarifReporterEmitsFallbackRuleForMutationFinding(): void
    {
        $mutationAnalysisResult = new MutationAnalysisResult(new InfectionReport(
                                                                 reportPath: 'infection.json',
                                                                 stats:      [
                                                                                 'totalMutantsCount'    => 1,
                                                                                 'msi'                  => 0.0,
                                                                                 'coveredCodeMsi'       => 0.0,
                                                                                 'mutationCodeCoverage' => 100.0,
                                                                             ],
                                                                 mutants:    [
                                                                                 new InfectionMutant(
                                                                                     status:        'escaped',
                                                                                     filePath:      './tests\\ExampleTest.php',
                                                                                     line:          22,
                                                                                     mutator:       'PublicVisibility',
                                                                                     diff:          'diff',
                                                                                     processOutput: 'output',
                                                                                 ),
                                                                             ],
                                                             ));
        $finding                = (new MutationFindingFactory())->findingsFor($mutationAnalysisResult)[0];
        $payload                = $this->decode((new SarifReporter())->render($this->report([$finding])));
        $sarifRun               = $this->sarifRun($payload);
        $driver                 = $this->stringKeyedArray($this->stringKeyedArray($this->stringKeyedArray($sarifRun, 'tool'), 'driver'));
        $rules                  = $this->listValue($driver, 'rules');
        $result                 = $this->stringKeyedArray($this->listValue($sarifRun, 'results')[0] ?? null);
        $ruleIndex              = $result['ruleIndex'] ?? null;
        self::assertIsInt($ruleIndex);
        $matchingRule = $this->stringKeyedArray($rules[$ruleIndex] ?? null);

        self::assertSame('mutation.survived-mutant', $this->stringValue($result, 'ruleId'));
        self::assertSame('mutation.survived-mutant', $this->stringValue($matchingRule, 'id'));
        self::assertSame('mutation.survived-mutant', $this->stringValue($matchingRule, 'name'));
        self::assertSame('mutation.survived-mutant', $this->stringValue($this->stringKeyedArray($matchingRule, 'shortDescription'), 'text'));
        $ruleProperties = $this->stringKeyedArray($matchingRule, 'properties');
        self::assertSame('mutation', $this->stringValue($ruleProperties, 'pillar'));
        self::assertSame('warning', $this->stringValue($ruleProperties, 'severity'));
        $resultProperties = $this->stringKeyedArray($result, 'properties');
        self::assertSame('PublicVisibility', $this->stringValue($resultProperties, 'symbol'));
        self::assertSame('escaped', $this->stringKeyedArray($resultProperties, 'metadata')['status'] ?? null);
    }

    /**
     * Verify optional regions and additional path shapes render without fabricated data.
     *
     * @return void
     * @throws JsonException
     */
    public function testSarifReporterHandlesOptionalRegionsAndAdditionalPathShapes(): void
    {
        $payload = $this->decode((new SarifReporter())->render($this->report([
                                                                                 $this->finding(ruleId: 'fixture.no-line', filePath: 'src\\NoLine.php', line: null),
                                                                                 $this->finding(ruleId: 'fixture.nested-dot', filePath: '././src/NestedDot.php', line: 4),
                                                                                 $this->finding(ruleId: 'fixture.windows-mixed', filePath: 'C:\\repo/src\\Mixed.php', line: 8),
                                                                             ])));
        $results = $this->listValue($this->sarifRun($payload), 'results');

        $firstLocation = $this->physicalLocation($results[0] ?? null);
        self::assertSame('src/NoLine.php', $this->stringValue($this->stringKeyedArray($firstLocation, 'artifactLocation'), 'uri'));
        self::assertArrayNotHasKey('region', $firstLocation);

        $secondLocation = $this->physicalLocation($results[1] ?? null);
        $nestedDotLine  = 4;
        self::assertSame('src/NestedDot.php', $this->stringValue($this->stringKeyedArray($secondLocation, 'artifactLocation'), 'uri'));
        self::assertSame($nestedDotLine, $this->stringKeyedArray($secondLocation, 'region')['startLine'] ?? null);

        $thirdLocation = $this->physicalLocation($results[2] ?? null);
        self::assertSame('C:/repo/src/Mixed.php', $this->stringValue($this->stringKeyedArray($thirdLocation, 'artifactLocation'), 'uri'));
    }

    /**
     * Verify SARIF and native JSON preserve the same report identity over one report instance.
     *
     * @return void
     * @throws JsonException
     */
    public function testSarifReporterPreservesNativeJsonSchemaAndFindingCount(): void
    {
        $report   = $this->report([
                                      $this->finding(ruleId: 'fixture.warning', severity: Severity::Warning),
                                      $this->finding(ruleId: 'fixture.advisory', severity: Severity::Advisory),
                                  ]);
        $json     = $this->decode((new JsonReporter())->render($report));
        $sarif    = $this->decode((new SarifReporter())->render($report));
        $sarifRun = $this->sarifRun($sarif);

        self::assertSame('gruff.analysis.v2', $this->stringValue($json, 'schemaVersion'));
        self::assertSame($this->stringValue($json, 'schemaVersion'), $this->stringValue($this->stringKeyedArray($sarifRun, 'properties'), 'gruffSchemaVersion'));
        self::assertCount(count($this->listValue($json, 'findings')), $this->listValue($sarifRun, 'results'));
        self::assertSame(
            array_map(
                fn(mixed $finding): string => $this->stringValue($this->stringKeyedArray($finding), 'ruleId'),
                $this->listValue($json, 'findings'),
            ),
            array_map(
                fn(mixed $result): string => $this->stringValue($this->stringKeyedArray($result), 'ruleId'),
                $this->listValue($sarifRun, 'results'),
            ),
        );
    }

    /**
     * @param list<Finding>    $findings - Findings to attach to the report.
     * @param ScoreReport|null $score - Precomputed score, or null to let assertions exercise an unscored report.
     *
     * @return AnalysisReport - a sarif-format report carrying only the given findings and optional score, so a test renders a known input
     */
    private function report(array $findings, ?ScoreReport $score = null): AnalysisReport
    {
        return new AnalysisReport(
            toolVersion:     '0.1.0-test',
            requestedPaths:  ['src'],
            format:          'sarif',
            failOn:          'none',
            filesDiscovered: count($findings),
            filesParsed:     count($findings),
            ignoredPaths:    [],
            missingPaths:    [],
            diagnostics:     [],
            findings:        $findings,
            exitCode:        0,
            score:           $score,
            diff:            DiffResult::inactive(),
        );
    }

    /**
     * Build a focused finding for SARIF renderer tests.
     *
     * @param string   $ruleId - Emitted as the SARIF ruleId; defaulted so tests override only what they assert.
     * @param string   $filePath - Artifact path the SARIF location should reference, relative to the scanned root.
     * @param int|null $line - One-based location line; null exercises rendering when a finding has no line.
     * @param Severity $severity - Drives the mapped SARIF result level; varied to check the severity-to-level mapping.
     *
     * @return Finding - a single finding built from the given fields, ready to render through the SARIF reporter and assert against
     */
    private function finding(
        string   $ruleId = 'security.dangerous-function-call',
        string   $filePath = 'src/app.php',
        ?int     $line = 1,
        Severity $severity = Severity::Warning,
    ): Finding {
        return new Finding(
            ruleId:     $ruleId,
            message:    'Finding message.',
            filePath:   $filePath,
            line:       $line,
            severity:   $severity,
            pillar:     Pillar::Security,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
        );
    }

    /**
     * @param JsonArray $payload - SARIF payload.
     *
     * @return JsonArray - the single run object at runs[0], holding the tool driver, results, and run properties
     */
    private function sarifRun(array $payload): array
    {
        return $this->stringKeyedArray($this->listValue($payload, 'runs')[0] ?? null);
    }

    /**
     * Extract the physical location object from a SARIF result fixture.
     *
     * @param mixed $result - One decoded SARIF result object; mixed because it arrives straight from json_decode.
     *
     * @return JsonArray - the physicalLocation object under the result's first location, holding the artifact URI and optional region
     */
    private function physicalLocation(mixed $result): array
    {
        // A SARIF result can carry several locations; the reporter only ever emits one, so the source pointer
        // tests assert against (artifact URI plus region) always lives under the first entry.
        return $this->stringKeyedArray(
            $this->stringKeyedArray($this->listValue($this->stringKeyedArray($result), 'locations')[0] ?? null),
            'physicalLocation',
        );
    }

    /**
     * @param string $json - Rendered SARIF document to decode; expected to be a single top-level JSON object.
     *
     * @return JsonArray - the decoded document as a string-keyed array so callers can index named top-level fields
     * @throws JsonException
     */
    private function decode(string $json): array
    {
        $decodedJson = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->stringKeyedArray($decodedJson);
    }

    /**
     * @param JsonArray $payload - Source array.
     * @param string    $key - Key whose value must itself be an array; the test fails if it is absent or scalar.
     *
     * @return list<JsonValue> - the child array re-indexed as a 0-based list so positional access ignores the original keys
     */
    private function listValue(array $payload, string $key): array
    {
        $payloadValue = $payload[$key] ?? null;
        self::assertIsArray($payloadValue);

        return array_values($payloadValue);
    }

    /**
     * Normalize a decoded JSON value or keyed child to an array payload.
     *
     * @param mixed       $payload - Decoded JSON node; mixed because it comes from json_decode, and non-arrays fail.
     * @param string|null $key - When set, descend into that child first; null treats $payload itself as the target.
     *
     * @return JsonArray - the resolved node narrowed to a JSON object, after the test has failed on any non-array or scalar leaf
     */
    private function stringKeyedArray(mixed $payload, ?string $key = null): array
    {
        $payloadValue = $key === null && is_array($payload) ? $payload : (is_array($payload) ? ($payload[$key] ?? null) : null);
        $this->assertJsonArray($payloadValue);
        // assertJsonArray is a @phpstan-assert guard, so static analysis only narrows $payloadValue to JsonArray
        // on this path; an empty or non-object node has already failed the test rather than reaching here.
        return $payloadValue;
    }

    /**
     * Assert that decoded SARIF contains an object at the requested key.
     *
     * @param mixed              $payload - Value under test; passes only when it is an array whose leaves are all JSON scalars.
     *
     * @phpstan-assert JsonArray $payload
     *
     * @return void
     */
    private function assertJsonArray(mixed $payload): void
    {
        self::assertIsArray($payload);

        foreach ($payload as $payloadValue) {
            if (is_array($payloadValue)) {
                $this->assertJsonArray($payloadValue);

                continue;
            }

            self::assertTrue(
                $payloadValue === null
                || is_bool($payloadValue)
                || is_float($payloadValue)
                || is_int($payloadValue)
                || is_object($payloadValue)
                || is_string($payloadValue),
            );
        }
    }

    /**
     * @param JsonArray $payload - Source array.
     * @param string    $key - Key whose value must be a string; the test fails if it is missing or non-string.
     *
     * @return string - the string value at $key, after the assertion has failed the test on a missing or non-string field
     */
    private function stringValue(array $payload, string $key): string
    {
        $payloadValue = $payload[$key] ?? null;
        self::assertIsString($payloadValue);
        // assertIsString doubles as the type narrowing static analysis needs here: a missing or non-string field
        // has already failed the test, so reaching this point means the looked-up value is genuinely a string.
        return $payloadValue;
    }
}
