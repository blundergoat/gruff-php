<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Mutation\InfectionMutant;
use GruffPhp\Mutation\InfectionReport;
use GruffPhp\Mutation\MutationAnalysisResult;
use GruffPhp\Mutation\MutationFindingFactory;
use GruffPhp\Reporting\JsonReporter;
use GruffPhp\Reporting\SarifReporter;
use GruffPhp\Scoring\ScoreCalculator;
use GruffPhp\Scoring\ScoreReport;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers SARIF report rendering contracts.
 */
final class SarifReporterTest extends TestCase
{
    /**
     * Verify SARIF output preserves native report identity while exposing code-scanning metadata.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testSarifReporterEmitsRegistryRulesResultIdentityAndRunProperties(): void
    {
        $finding = new Finding(
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

        $payload = $this->decode((new SarifReporter())->render($report));
        $run     = $this->stringKeyedArray($this->listValue($payload, 'runs')[0] ?? null);
        $driver  = $this->stringKeyedArray($this->stringKeyedArray($this->stringKeyedArray($run, 'tool'), 'driver'));
        $rules   = $this->listValue($driver, 'rules');
        $ruleIds = array_map(
            fn (mixed $rule): string => $this->stringValue($this->stringKeyedArray($rule), 'id'),
            $rules,
        );
        $sortedRuleIds = $ruleIds;
        sort($sortedRuleIds, SORT_STRING);
        $result    = $this->stringKeyedArray($this->listValue($run, 'results')[0] ?? null);
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
        self::assertSame(12, $region['startLine'] ?? null);
        self::assertSame(5, $region['startColumn'] ?? null);
        self::assertSame(13, $region['endLine'] ?? null);
        $partialFingerprints = $this->stringKeyedArray($result, 'partialFingerprints');
        self::assertSame($finding->fingerprint(), $this->stringValue($partialFingerprints, 'gruffFingerprint'));
        self::assertArrayNotHasKey('primary', $partialFingerprints);
        $resultProperties = $this->stringKeyedArray($result, 'properties');
        self::assertSame(['maintainability'], $this->listValue($resultProperties, 'secondaryPillars'));
        self::assertSame('eval', $this->stringKeyedArray($resultProperties, 'metadata')['target'] ?? null);
        $runProperties = $this->stringKeyedArray($run, 'properties');
        self::assertSame('gruff.analysis.v1', $this->stringValue($runProperties, 'gruffSchemaVersion'));
        self::assertSame($score->composite->score, $runProperties['score'] ?? null);
        self::assertSame($score->composite->letter, $runProperties['grade'] ?? null);
        self::assertSame('gruff.analysis.v1', $this->stringValue($this->decode((new JsonReporter())->render($report)), 'schemaVersion'));
        self::assertArrayNotHasKey('codeFlows', $result);
        self::assertArrayNotHasKey('threadFlows', $result);
        self::assertArrayNotHasKey('fixes', $result);
    }

    /**
     * Verify empty reports stay valid SARIF while omitting absent score data.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testSarifReporterHandlesEmptyReportAndOmittedScore(): void
    {
        $payload = $this->decode((new SarifReporter())->render($this->report([])));
        $run     = $this->stringKeyedArray($this->listValue($payload, 'runs')[0] ?? null);
        $driver  = $this->stringKeyedArray($this->stringKeyedArray($this->stringKeyedArray($run, 'tool'), 'driver'));

        self::assertSame([], $this->listValue($run, 'results'));
        self::assertContains('size.file-length', array_map(
            fn (mixed $rule): string => $this->stringValue($this->stringKeyedArray($rule), 'id'),
            $this->listValue($driver, 'rules'),
        ));
        $runProperties = $this->stringKeyedArray($run, 'properties');
        self::assertSame('gruff.analysis.v1', $this->stringValue($runProperties, 'gruffSchemaVersion'));
        self::assertArrayNotHasKey('score', $runProperties);
        self::assertArrayNotHasKey('grade', $runProperties);
    }

    /**
     * Verify severity values map to SARIF result levels.
     *
     * @param Severity $severity Finding severity to render.
     * @param string   $level    Expected SARIF level.
     *
     * @throws JsonException
     * @return void No return value.
     */
    #[DataProvider('severityLevels')]
    public function testSarifReporterMapsSeverityLevels(Severity $severity, string $level): void
    {
        $payload = $this->decode((new SarifReporter())->render($this->report([
            $this->finding(severity: $severity),
        ])));
        $result = $this->stringKeyedArray($this->listValue($this->sarifRun($payload), 'results')[0] ?? null);

        self::assertSame($level, $this->stringValue($result, 'level'));
    }

    /**
     * @return iterable<string, array{0: Severity, 1: string}>
     */
    public static function severityLevels(): iterable
    {
        yield 'error' => [Severity::Error, 'error'];
        yield 'warning' => [Severity::Warning, 'warning'];
        yield 'advisory' => [Severity::Advisory, 'note'];
    }

    /**
     * Verify non-registry mutation findings receive deterministic fallback driver rules.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testSarifReporterEmitsFallbackRuleForMutationFinding(): void
    {
        $mutationResult = new MutationAnalysisResult(new InfectionReport(
            reportPath: 'infection.json',
            stats:      [
                'totalMutantsCount' => 1,
                'msi' => 0.0,
                'coveredCodeMsi' => 0.0,
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
        $finding   = (new MutationFindingFactory())->findingsFor($mutationResult)[0];
        $payload   = $this->decode((new SarifReporter())->render($this->report([$finding])));
        $run       = $this->sarifRun($payload);
        $driver    = $this->stringKeyedArray($this->stringKeyedArray($this->stringKeyedArray($run, 'tool'), 'driver'));
        $rules     = $this->listValue($driver, 'rules');
        $result    = $this->stringKeyedArray($this->listValue($run, 'results')[0] ?? null);
        $ruleIndex = $result['ruleIndex'] ?? null;
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
     * @throws JsonException
     * @return void No return value.
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
        self::assertSame('src/NestedDot.php', $this->stringValue($this->stringKeyedArray($secondLocation, 'artifactLocation'), 'uri'));
        self::assertSame(4, $this->stringKeyedArray($secondLocation, 'region')['startLine'] ?? null);

        $thirdLocation = $this->physicalLocation($results[2] ?? null);
        self::assertSame('C:/repo/src/Mixed.php', $this->stringValue($this->stringKeyedArray($thirdLocation, 'artifactLocation'), 'uri'));
    }

    /**
     * Verify SARIF and native JSON preserve the same report identity over one report instance.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testSarifReporterPreservesNativeJsonSchemaAndFindingCount(): void
    {
        $report = $this->report([
            $this->finding(ruleId: 'fixture.warning', severity: Severity::Warning),
            $this->finding(ruleId: 'fixture.advisory', severity: Severity::Advisory),
        ]);
        $json  = $this->decode((new JsonReporter())->render($report));
        $sarif = $this->decode((new SarifReporter())->render($report));
        $run   = $this->sarifRun($sarif);

        self::assertSame('gruff.analysis.v1', $this->stringValue($json, 'schemaVersion'));
        self::assertSame($this->stringValue($json, 'schemaVersion'), $this->stringValue($this->stringKeyedArray($run, 'properties'), 'gruffSchemaVersion'));
        self::assertCount(count($this->listValue($json, 'findings')), $this->listValue($run, 'results'));
        self::assertSame(
            array_map(
                fn (mixed $finding): string => $this->stringValue($this->stringKeyedArray($finding), 'ruleId'),
                $this->listValue($json, 'findings'),
            ),
            array_map(
                fn (mixed $result): string => $this->stringValue($this->stringKeyedArray($result), 'ruleId'),
                $this->listValue($run, 'results'),
            ),
        );
    }

    /**
     * @param list<Finding> $findings Findings to attach to the report.
     * @return AnalysisReport Focused report fixture.
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
     * @return Finding Focused finding fixture.
     */
    private function finding(
        string $ruleId = 'security.dangerous-function-call',
        string $filePath = 'src/app.php',
        ?int $line = 1,
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
     * @param array<mixed> $payload SARIF payload.
     * @return array<mixed>
     */
    private function sarifRun(array $payload): array
    {
        return $this->stringKeyedArray($this->listValue($payload, 'runs')[0] ?? null);
    }

    /**
     * @return array<mixed>
     */
    private function physicalLocation(mixed $result): array
    {
        return $this->stringKeyedArray(
            $this->stringKeyedArray($this->listValue($this->stringKeyedArray($result), 'locations')[0] ?? null),
            'physicalLocation',
        );
    }

    /**
     * @throws JsonException
     * @return array<mixed>
     */
    private function decode(string $json): array
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param array<mixed> $value Source array.
     * @return list<mixed>
     */
    private function listValue(array $value, string $key): array
    {
        $item = $value[$key] ?? null;
        self::assertIsArray($item);

        return array_values($item);
    }

    /**
     * @param array<mixed>|mixed $value Source value.
     * @return array<mixed>
     */
    private function stringKeyedArray(mixed $value, ?string $key = null): array
    {
        $item = $key === null && is_array($value) ? $value : (is_array($value) ? ($value[$key] ?? null) : null);
        self::assertIsArray($item);

        return $item;
    }

    /**
     * @param array<mixed> $value Source array.
     * @return string String value.
     */
    private function stringValue(array $value, string $key): string
    {
        $item = $value[$key] ?? null;
        self::assertIsString($item);

        return $item;
    }
}
