<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Engine\Config\SeverityThreshold;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Token;

/**
 * Flags files with more TODO/FIXME/HACK/XXX occurrences in comments than the configured threshold.
 * Counts each marker in comments and docblocks and reports at most one finding per file.
 *
 * The default threshold is 10; a count strictly above it produces an error with high confidence.
 */
final readonly class TodoDensityRule implements RuleInterface
{
    /**
     * Stable rule identifier for TODO density findings.
     */
    public const ID = 'docs.todo-density';

    /**
     * Describes the TODO-density rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and thresholds (default 10 markers per file).
     */
    public function definition(): RuleDefinition
    {

        return new RuleDefinition(
            id:                self::ID,
            name:              'TODO/FIXME density',
            pillar:            Pillar::Documentation,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            severityThreshold: new SeverityThreshold(10, Severity::Error),
        );
    }

    /**
     * Counts TODO-style markers across the file's comments and reports when the count exceeds the threshold.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context supplying the density threshold.
     *
     * @return list<Finding> - Empty when skipped or within the threshold; otherwise one file-level finding.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // A source-level precheck avoids scanning comment tokens when no deferred-work marker can contribute to the threshold.
        if (preg_match('/\b(?:TODO|FIXME|HACK|XXX)\b/i', $analysisUnit->source) !== 1) {
            return [];
        }

        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $count     = 0;
        $firstLine = null;


        foreach ($analysisUnit->tokens as $token) {
            // Ignore markers in executable code and string literals; only comments contribute to the count.
            if (!$this->isCommentToken($token)) {
                continue;
            }

            // Repeated markers in one comment each contribute to the file-level threshold.
            $matches = preg_match_all('/\b(TODO|FIXME|HACK|XXX)\b/i', $token->text);

            if ($matches === 0 || $matches === false) {
                continue;
            }

            $count += $matches;
            $firstLine ??= $token->line;
        }
        $thresholdMatch = $settings->highValueThresholdMatch($count);

        if ($thresholdMatch === null) {
            return [];
        }

        // Report one file-level finding at the start of the first comment containing a marker.
        return [
            new Finding(
                ruleId:      $definition->id,
                message:     sprintf('File has %d TODO/FIXME markers, above the %s threshold of %s.', $count, $thresholdMatch->severity->value, (string) (int) $thresholdMatch->threshold),
                filePath:    $analysisUnit->file->displayPath,
                line:        $firstLine,
                severity:    $thresholdMatch->severity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                remediation: 'Resolve outstanding TODOs or track them as issues.',
                metadata:    ['count' => $count, 'threshold' => $thresholdMatch->threshold, 'thresholdType' => $thresholdMatch->severity->value],
            ),
        ];
    }

    /**
     * Identifies comment and docblock tokens eligible for the TODO-density count.
     *
     * @param Token $token - Lexer token whose kind decides whether its text is counted.
     *
     * @return bool - True for T_COMMENT or T_DOC_COMMENT; false for all other token kinds.
     */
    private function isCommentToken(Token $token): bool
    {

        return $token->id === T_COMMENT || $token->id === T_DOC_COMMENT;
    }
}
