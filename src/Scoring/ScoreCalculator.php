<?php

declare(strict_types=1);

namespace GruffPhp\Scoring;

use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\Severity;
use GruffPhp\Mutation\MutationAnalysisResult;
use GruffPhp\Mutation\MutationFileSummary;

final readonly class ScoreCalculator
{
    private const STATIC_PILLARS = [
        'size',
        'complexity',
        'maintainability',
        'dead-code',
        'naming',
        'documentation',
        'modernisation',
        'security',
        'sensitive-data',
        'test-quality',
    ];

    /**
     * @param list<Finding> $findings
     */
    public function calculate(array $findings, ?MutationAnalysisResult $mutation, ?DiffResult $diff): ScoreReport
    {
        $pillars = $this->pillarScores($findings, $mutation);
        $scoreTotal = 0.0;
        $scoreCount = 0;

        foreach ($pillars as $pillar) {
            if (!$pillar->applicable || !$pillar->grade instanceof Grade) {
                continue;
            }

            $scoreTotal += $pillar->grade->score;
            $scoreCount++;
        }

        $averageScore = $scoreCount === 0 ? 100.0 : $scoreTotal / $scoreCount;

        $scope = $diff instanceof DiffResult && $diff->active ? 'diff' : 'full-project';

        return new ScoreReport(
            composite: Grade::fromScore($averageScore),
            pillars: $pillars,
            topOffenders: $this->fileScores($findings, $mutation),
            complexityDistribution: $this->complexityDistribution($findings),
            scope: $scope,
            explanation: 'Per-pillar scores start at 100 and subtract weighted finding penalties; the composite is the average of applicable pillar scores. Mutation is omitted when no Infection report is supplied.',
        );
    }

    /**
     * @param list<Finding> $findings
     * @return list<PillarScore>
     */
    private function pillarScores(array $findings, ?MutationAnalysisResult $mutation): array
    {
        $pillarNames = self::STATIC_PILLARS;

        foreach ($findings as $finding) {
            if (!in_array($finding->pillar->value, $pillarNames, true)) {
                $pillarNames[] = $finding->pillar->value;
            }
        }

        if ($mutation instanceof MutationAnalysisResult && !in_array(Pillar::Mutation->value, $pillarNames, true)) {
            $pillarNames[] = Pillar::Mutation->value;
        }

        $scores = [];

        foreach ($pillarNames as $pillarName) {
            if ($pillarName === Pillar::Mutation->value && !$mutation instanceof MutationAnalysisResult) {
                $scores[] = new PillarScore($pillarName, false, null, 0, 0, 0, 0, 0.0);
                continue;
            }

            if ($pillarName === Pillar::Mutation->value && $mutation instanceof MutationAnalysisResult) {
                $mutationFindings = array_values(array_filter(
                    $findings,
                    static fn (Finding $finding): bool => $finding->pillar === Pillar::Mutation,
                ));
                $counts = $this->severityCounts($mutationFindings);
                $scores[] = new PillarScore(
                    pillar: $pillarName,
                    applicable: true,
                    grade: Grade::fromScore($mutation->report->msi()),
                    findings: count($mutationFindings),
                    advisories: $counts['advisory'],
                    warnings: $counts['warning'],
                    errors: $counts['error'],
                    penalty: max(0.0, 100.0 - $mutation->report->msi()),
                );
                continue;
            }

            $pillarFindings = array_values(array_filter(
                $findings,
                static fn (Finding $finding): bool => $finding->pillar->value === $pillarName,
            ));
            $penalty = $this->findingPenalty($pillarFindings) * 4.0;
            $counts = $this->severityCounts($pillarFindings);

            $scores[] = new PillarScore(
                pillar: $pillarName,
                applicable: true,
                grade: Grade::fromScore(100.0 - $penalty),
                findings: count($pillarFindings),
                advisories: $counts['advisory'],
                warnings: $counts['warning'],
                errors: $counts['error'],
                penalty: $penalty,
            );
        }

        return $scores;
    }

    /**
     * @param list<Finding> $findings
     * @return list<FileScore>
     */
    private function fileScores(array $findings, ?MutationAnalysisResult $mutation): array
    {
        /** @var array<string, list<Finding>> $byFile */
        $byFile = [];

        foreach ($findings as $finding) {
            $byFile[$finding->filePath] ??= [];
            $byFile[$finding->filePath][] = $finding;
        }

        $mutationByFile = [];
        if ($mutation instanceof MutationAnalysisResult) {
            foreach ($mutation->report->fileSummaries() as $summary) {
                $mutationByFile[$summary->filePath] = $summary;
                $byFile[$summary->filePath] ??= [];
            }
        }

        $scores = [];

        foreach ($byFile as $filePath => $fileFindings) {
            $counts = $this->severityCounts($fileFindings);
            $penalty = $this->findingPenalty($fileFindings) * 5.0;
            $mutationSummary = $mutationByFile[$filePath] ?? null;

            $scores[] = new FileScore(
                filePath: $filePath,
                grade: Grade::fromScore(100.0 - $penalty),
                findings: count($fileFindings),
                advisories: $counts['advisory'],
                warnings: $counts['warning'],
                errors: $counts['error'],
                penalty: $penalty,
                maxCyclomatic: $this->maxMetadataInt($fileFindings, 'complexity.cyclomatic', 'complexity'),
                maxCognitive: $this->maxMetadataInt($fileFindings, 'complexity.cognitive', 'complexity'),
                maxLines: $this->maxLineMetric($fileFindings),
                mutationScore: $mutationSummary instanceof MutationFileSummary ? $mutationSummary->msi : null,
            );
        }

        usort($scores, static function (FileScore $a, FileScore $b): int {
            return $a->grade->score <=> $b->grade->score
                ?: $b->findings <=> $a->findings
                ?: strcmp($a->filePath, $b->filePath);
        });

        return array_slice($scores, 0, 10);
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, int>
     */
    private function complexityDistribution(array $findings): array
    {
        $buckets = [
            '1-5' => 0,
            '6-10' => 0,
            '11-15' => 0,
            '16-20' => 0,
            '21+' => 0,
        ];

        foreach ($findings as $finding) {
            if ($finding->ruleId !== 'complexity.cyclomatic') {
                continue;
            }

            $complexity = $finding->metadata['complexity'] ?? null;
            if (!is_int($complexity)) {
                continue;
            }

            if ($complexity <= 5) {
                $buckets['1-5']++;
            } elseif ($complexity <= 10) {
                $buckets['6-10']++;
            } elseif ($complexity <= 15) {
                $buckets['11-15']++;
            } elseif ($complexity <= 20) {
                $buckets['16-20']++;
            } else {
                $buckets['21+']++;
            }
        }

        return $buckets;
    }

    /**
     * @param list<Finding> $findings
     */
    private function findingPenalty(array $findings): float
    {
        return array_sum(array_map(fn (Finding $finding): float => $this->penaltyFor($finding), $findings));
    }

    private function penaltyFor(Finding $finding): float
    {
        $severityWeight = match ($finding->severity) {
            Severity::Advisory => 1.0,
            Severity::Warning => 4.0,
            Severity::Error => 12.0,
        };

        $confidenceWeight = match ($finding->confidence) {
            Confidence::Low => 0.5,
            Confidence::Medium => 0.75,
            Confidence::High => 1.0,
        };

        return $severityWeight * $confidenceWeight;
    }

    /**
     * @param list<Finding> $findings
     * @return array{advisory: int, warning: int, error: int}
     */
    private function severityCounts(array $findings): array
    {
        $counts = ['advisory' => 0, 'warning' => 0, 'error' => 0];

        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * @param list<Finding> $findings
     */
    private function maxMetadataInt(array $findings, string $ruleId, string $key): ?int
    {
        $max = null;

        foreach ($findings as $finding) {
            if ($finding->ruleId !== $ruleId) {
                continue;
            }

            $value = $finding->metadata[$key] ?? null;
            if (!is_int($value)) {
                continue;
            }

            $max = $max === null ? $value : max($max, $value);
        }

        return $max;
    }

    /**
     * @param list<Finding> $findings
     */
    private function maxLineMetric(array $findings): ?int
    {
        $max = null;

        foreach ($findings as $finding) {
            if (!in_array($finding->ruleId, ['size.file-length', 'size.method-length', 'size.class-length'], true)) {
                continue;
            }

            $value = $finding->metadata['lines'] ?? null;
            if (!is_int($value)) {
                continue;
            }

            $max = $max === null ? $value : max($max, $value);
        }

        return $max;
    }
}
