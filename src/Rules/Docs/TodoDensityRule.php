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
 * Detects files where TODO and FIXME comments exceed the configured density.
 */
final readonly class TodoDensityRule implements RuleInterface
{
    /**
     * Stable rule identifier for TODO density findings.
     */
    public const ID = 'docs.todo-density';

    /**
     * Describe the TODO density rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        // Default threshold of 10 markers per file: a handful of TODOs is normal, a pile of them signals neglect.
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
     * Count TODO-style markers in comments and report files above threshold.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for excessive TODO density.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Fast bail: most files have zero deferred-work markers; skip the per-token scan for them.
        // User view: choose the findings list branch for this case.
        if (preg_match('/\b(?:TODO|FIXME|HACK|XXX)\b/i', $analysisUnit->source) !== 1) {
            // No marker anywhere in the raw source means nothing to count; the density rule cannot fire.
            return [];
        }

        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $count     = 0;
        $firstLine = null;

        // User view: add each item that can appear in findings list.
        foreach ($analysisUnit->tokens as $token) {
            // User view: choose the findings list branch for this case.
            if (!$this->isCommentToken($token)) {
                continue;
            }

            $matches = preg_match_all('/\b(TODO|FIXME|HACK|XXX)\b/i', $token->text);

            // User view: choose the findings list branch for this case.
            if ($matches === 0 || $matches === false) {
                continue;
            }

            $count += $matches;
            $firstLine ??= $token->line;
        }
        $thresholdMatch = $settings->highValueThresholdMatch($count);

        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($thresholdMatch === null) {
            // Marker count stayed at or below the configured threshold, so this file is within tolerance.
            return [];
        }

        // Count crossed the threshold; report a single file-level finding anchored at the first marker seen.
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
     * Check whether a token is a normal comment or docblock.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Token $token - Lexer token from the parsed unit; only comment-bearing kinds can hold a marker.
     *
     * @return bool - True when the token can contain TODO markers.
     */
    private function isCommentToken(Token $token): bool
    {
        // Only // # and /* */ comments and /** */ docblocks carry marker text; code and whitespace cannot.
        return $token->id === T_COMMENT || $token->id === T_DOC_COMMENT;
    }
}
