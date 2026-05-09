<?php

declare(strict_types=1);

namespace GruffPhp\Analysis;

use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Severity;

final readonly class AnalysisReport
{
    public const SCHEMA_VERSION = 'gruff.analysis.v1';

    /**
     * @param list<string> $requestedPaths
     * @param list<string> $ignoredPaths
     * @param list<string> $missingPaths
     * @param list<RunDiagnostic> $diagnostics
     * @param list<Finding> $findings
     */
    public function __construct(
        public string $toolVersion,
        public array $requestedPaths,
        public string $format,
        public string $failOn,
        public int $filesDiscovered,
        public int $filesParsed,
        public array $ignoredPaths,
        public array $missingPaths,
        public array $diagnostics,
        public array $findings,
        public int $exitCode,
        public ?string $configPath = null,
    ) {
    }

    /**
     * @return array{advisory: int, warning: int, error: int, total: int}
     */
    public function findingCounts(): array
    {
        $counts = [
            'advisory' => 0,
            'warning' => 0,
            'error' => 0,
            'total' => count($this->findings),
        ];

        foreach ($this->findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    public function parseErrorCount(): int
    {
        return count(array_filter(
            $this->diagnostics,
            static fn (RunDiagnostic $diagnostic): bool => $diagnostic->type === 'parse-error',
        ));
    }

    /**
     * @return array{
     *     schemaVersion: string,
     *     tool: array{name: string, version: string},
     *     run: array{format: string, failOn: string, config: string|null, paths: list<string>},
     *     summary: array{
     *         filesDiscovered: int,
     *         filesParsed: int,
     *         ignoredPaths: int,
     *         missingPaths: int,
     *         parseErrors: int,
     *         findings: array{advisory: int, warning: int, error: int, total: int},
     *         exitCode: int
     *     },
     *     ignoredPaths: list<string>,
     *     missingPaths: list<string>,
     *     diagnostics: list<array{type: string, message: string, file: string|null, line: int|null, path: string|null}>,
     *     findings: list<array{
     *         ruleId: string,
     *         message: string,
     *         file: string,
     *         line: int|null,
     *         endLine: int|null,
     *         column: int|null,
     *         symbol: string|null,
     *         severity: string,
     *         pillar: string,
     *         secondaryPillars: list<string>,
     *         tier: string,
     *         confidence: string,
     *         remediation: string|null,
     *         fingerprint: string,
     *         metadata: array<string, mixed>
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'tool' => [
                'name' => 'gruff',
                'version' => $this->toolVersion,
            ],
            'run' => [
                'format' => $this->format,
                'failOn' => $this->failOn,
                'config' => $this->configPath,
                'paths' => $this->requestedPaths,
            ],
            'summary' => [
                'filesDiscovered' => $this->filesDiscovered,
                'filesParsed' => $this->filesParsed,
                'ignoredPaths' => count($this->ignoredPaths),
                'missingPaths' => count($this->missingPaths),
                'parseErrors' => $this->parseErrorCount(),
                'findings' => $this->findingCounts(),
                'exitCode' => $this->exitCode,
            ],
            'ignoredPaths' => $this->ignoredPaths,
            'missingPaths' => $this->missingPaths,
            'diagnostics' => array_map(
                static fn (RunDiagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->diagnostics,
            ),
            'findings' => array_map(
                static fn (Finding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }

    public function hasFindingsAtSeverity(Severity $severity): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === $severity) {
                return true;
            }
        }

        return false;
    }
}
