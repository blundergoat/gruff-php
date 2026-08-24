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
 * Flags a regular comment whose body looks like disabled PHP code, so the user can clear out
 * commented-out snippets that reviewers otherwise have to mentally skip.
 *
 * Runs per file over every non-docblock comment token, stripping the delimiters and scoring the body for
 * code-shaped signals (assignments, method calls, control-flow keywords). Two or more signals reports the
 * comment at advisory, since the heuristic can misread prose.
 */
final readonly class CommentedOutCodeRule implements RuleInterface
{
    /**
     * Stable rule identifier for commented-out code findings.
     */
    public const ID = 'waste.commented-out-code';

    /**
     * Describes the commented-out-code rule for the registry and reports.
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
            falsePositiveShapes: [
                [
                    'shape'      => 'Explanatory prose that names a variable and an assignment in the same sentence, which alone reaches the two-signal bar.',
                    'mitigation' => 'Signals are counted per line with no parse, so reword the comment to describe the behaviour without writing the assignment out.',
                ],
            ],
        );
    }

    /**
     * Reports each comment whose stripped body scores as commented-out code.
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

        // Scan every token, looking only at the comments.
        foreach ($analysisUnit->tokens as $token) {
            // Only a regular comment is a candidate.
            if (!$this->isCommentToken($token)) {
                continue;
            }

            $text = $token->text;
            $line = $token->line;

            // A docblock is documentation, never disabled code.
            if (str_starts_with(trim($text), '/**')) {
                continue;
            }

            $content = $this->stripCommentMarkers($text);

            // Report a comment body that scores as code.
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
     * Reports whether a parser token is a regular comment (never a docblock).
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
     * Strips PHP comment delimiters and per-line decoration, leaving the bare comment body.
     *
     * @param string $text - Raw comment token text, still carrying its leading slashes, hash, or block delimiters.
     *
     * @return string - Trimmed comment body.
     */
    private function stripCommentMarkers(string $text): string
    {
        // Strip the surrounding block-comment delimiters.
        $text = preg_replace('/^\/\*+\s*|\s*\*+\/$/', '', $text) ?? $text;
        // Strip the leading star decoration from each block-comment line.
        $text = preg_replace('/^\s*\*\s?/m', '', $text) ?? $text;
        // Strip the leading slashes from each line comment.
        $text = preg_replace('/^\/\/\s?/m', '', $text) ?? $text;

        // Inner content with every delimiter and per-line decoration removed, ready for the shape scan.
        return trim($text);
    }

    /**
     * Reports whether a comment body carries enough PHP-like signals to look like disabled code.
     *
     * @param string $content - Delimiter-stripped comment body to scan for disabled-code signals.
     *
     * @return bool - True when the content looks like disabled code.
     */
    private function isCodeLike(string $content): bool
    {
        // Too short to carry a meaningful statement; never treat tiny comments as code.
        if (strlen($content) < 5) {
            return false;
        }

        // Drop blank lines before scoring the rest.
        $lines = array_filter(explode("\n", $content), static fn (string $line): bool => trim($line) !== '');

        // Only blank lines remained after filtering, so there is nothing to classify.
        if ($lines === []) {
            return false;
        }

        $codeIndicators = 0;

        // Score each non-blank line for code signals.
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Count commented snippets that look like variable-bearing statements.
            if (str_contains($trimmed, ';') && preg_match('/\$\w/', $trimmed) === 1) {
                $codeIndicators++;
            }

            // Count commented snippets that begin with common PHP control-flow or output keywords.
            if (preg_match('/^(if|for|foreach|while|return|throw|echo)\s*\(/', $trimmed) === 1) {
                $codeIndicators++;
            }

            // Count commented snippets that look like method calls on variables.
            if (preg_match('/\$\w+\s*->\s*\w+\s*\(/', $trimmed) === 1) {
                $codeIndicators++;
            }

            // Count commented snippets that look like variable assignments.
            if (preg_match('/\$\w+\s*=\s*/', $trimmed) === 1) {
                $codeIndicators++;
            }
        }

        // Require at least two signals so a single `$`-mention or stray keyword in prose stays below the bar.
        return $codeIndicators >= 2;
    }
}
