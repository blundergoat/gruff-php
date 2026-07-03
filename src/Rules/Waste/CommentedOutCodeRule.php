<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Waste;

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
 * Detects regular comments that look like disabled PHP code.
 */
final readonly class CommentedOutCodeRule implements RuleInterface
{
    /**
     * Stable rule identifier for commented-out code findings.
     */
    public const ID = 'waste.commented-out-code';

    /**
     * Describe the commented-out code rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence and advisory: the code-shape heuristic can misread prose, so teams opt in.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Commented-out code',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find comment tokens that appear to contain disabled executable code.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per comment token whose stripped body crosses the code-shape threshold.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $findings   = [];

        // User view: add each item that can appear in findings list.
        foreach ($analysisUnit->tokens as $token) {
            // User view: choose the findings list branch for this case.
            if (!$this->isCommentToken($token)) {
                continue;
            }

            $text = $token->text;
            $line = $token->line;

            // User view: choose the findings list branch for this case.
            if (str_starts_with(trim($text), '/**')) {
                continue;
            }

            $content = $this->stripCommentMarkers($text);

            // User view: choose the findings list branch for this case.
            if ($this->isCodeLike($content)) {
                $findings[] = new Finding(
                    ruleId:      $definition->id,
                    message:     'Comment appears to contain commented-out code.',
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $line,
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    remediation: 'Remove commented-out code or restore it if still needed.',
                );
            }
        }

        return $findings;
    }

    /**
     * Check whether a parser token is a regular comment.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Token $token - Lexer token to classify; only line and inline block comments qualify, never docblocks.
     *
     * @return bool - True for non-docblock comment tokens.
     */
    private function isCommentToken(Token $token): bool
    {
        // Docblocks lex as T_DOC_COMMENT, so matching only T_COMMENT excludes `/**` from this rule.
        return $token->id === T_COMMENT;
    }

    /**
     * Remove PHP comment delimiters before code-shape checks.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $text - Raw comment token text, still carrying its leading slashes, hash, or block delimiters.
     *
     * @return string - Trimmed comment body.
     */
    private function stripCommentMarkers(string $text): string
    {
        // User view: missing data becomes a safe findings list default.
        $text = preg_replace('/^\/\*+\s*|\s*\*+\/$/', '', $text) ?? $text;
        // User view: missing data becomes a safe findings list default.
        $text = preg_replace('/^\s*\*\s?/m', '', $text) ?? $text;
        // User view: missing data becomes a safe findings list default.
        $text = preg_replace('/^\/\/\s?/m', '', $text) ?? $text;

        // Inner content with every delimiter and per-line decoration removed, ready for the shape scan.
        return trim($text);
    }

    /**
     * Detect whether comment content has enough PHP-like syntax signals.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $content - Delimiter-stripped comment body to scan for disabled-code signals.
     *
     * @return bool - True when the content looks like disabled code.
     */
    private function isCodeLike(string $content): bool
    {
        // User view: choose the findings list branch for this case.
        if (strlen($content) < 5) {
            // Too short to carry a meaningful statement; never treat tiny comments as code.
            return false;
        }

        // User view: an empty value becomes a clear findings list fallback.
        $lines = array_filter(explode("\n", $content), static fn (string $line): bool => trim($line) !== '');

        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($lines === []) {
            // Only blank lines remained after filtering, so there is nothing to classify.
            return false;
        }

        $codeIndicators = 0;

        // User view: add each item that can appear in findings list.
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Count commented snippets that look like variable-bearing statements.
            // User view: choose the findings list branch for this case.
            if (str_contains($trimmed, ';') && preg_match('/\$\w/', $trimmed) === 1) {
                $codeIndicators++;
            }

            // Count commented snippets that begin with common PHP control-flow or output keywords.
            // User view: choose the findings list branch for this case.
            if (preg_match('/^(if|for|foreach|while|return|throw|echo)\s*\(/', $trimmed) === 1) {
                $codeIndicators++;
            }

            // Count commented snippets that look like method calls on variables.
            // User view: choose the findings list branch for this case.
            if (preg_match('/\$\w+\s*->\s*\w+\s*\(/', $trimmed) === 1) {
                $codeIndicators++;
            }

            // Count commented snippets that look like variable assignments.
            // User view: choose the findings list branch for this case.
            if (preg_match('/\$\w+\s*=\s*/', $trimmed) === 1) {
                $codeIndicators++;
            }
        }

        // Require at least two signals so a single `$`-mention or stray keyword in prose stays below the bar.
        return $codeIndicators >= 2;
    }
}
