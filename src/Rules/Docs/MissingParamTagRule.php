<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Detects documented methods and functions whose parameters lack matching @param tags.
 */
final readonly class MissingParamTagRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing @param tag findings.
     */
    public const ID = 'docs.missing-param-tag';

    /**
     * Describe the missing @param tag rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing @param tag',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find documented function-like declarations with undocumented parameters, at any visibility.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for missing parameter tags.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $docComment = $node->getDocComment();

            if ($docComment === null || $node->params === []) {
                continue;
            }

            $docText = $docComment->getText();

            if (!$this->hasContractDoc($docText)) {
                continue;
            }

            $documentedParams = $this->extractParamNames($docText);
            $symbol           = CyclomaticComplexityRule::resolveSymbol($node);

            foreach ($node->params as $param) {
                if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                    continue;
                }

                $paramName = $param->var->name;

                if (in_array($paramName, $documentedParams, true)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      $definition->id,
                    message:     sprintf('Parameter $%s in %s needs an @param tag with a brief description (one plain-English clause; not a restatement of the type signature).', $paramName, $symbol),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $param->getStartLine(),
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      $symbol,
                    remediation: sprintf('Add an `@param SomeType $%s Description.` tag. This rule wants content, not boilerplate - the description should answer "what does the caller need to satisfy for this parameter."', $paramName),
                    metadata:    ['parameter' => $paramName],
                );
            }
        }

        return $findings;
    }

    /**
     * Extract parameter names documented by @param tags. Depth-aware so multi-line array shapes match the
     * closing documented variable even when it sits on a different line from the `@param` token.
     *
     * @param string $docText - Raw docblock text.
     *
     * @return list<string> - Parameter names found in the docblock.
     */
    public static function extractParamNames(string $docText): array
    {
        $stripped   = self::stripDocFraming($docText);
        $paramNames = [];
        $length     = strlen($stripped);
        $position   = 0;

        while ($position < $length) {
            $tagPosition = strpos($stripped, '@param', $position);
            if ($tagPosition === false) {
                break;
            }

            $position = $tagPosition + strlen('@param');

            // Require a word boundary after `@param`. Otherwise `@param-out`,
            // `@param-immutable`, etc. (which document output-only or constraint
            // semantics, not an input parameter) would be treated as `@param`
            // and falsely suppress the missing-input-tag finding.
            if ($position < $length) {
                $next = $stripped[$position];
                if ($next === '-' || ctype_alnum($next) || $next === '_') {
                    continue;
                }
            }

            $varName = self::scanForParamVariable($stripped, $length, $position);
            if ($varName !== null) {
                $paramNames[] = $varName;
            }
        }

        return $paramNames;
    }

    /**
     * Walk forward from a `@param` token through balanced `{} <> [] ()` brackets and return the
     * first parameter variable token reached at depth zero. Stops at the next `@tag` (next docblock entry) or
     * end of input.
     *
     * @param string $stripped - Docblock text with `/**`, ` * `, and `*\/` framing removed.
     * @param int    $length - Pre-computed `strlen($stripped)` to avoid recomputation per call.
     * @param int    $position - Cursor position (mutated to point past the matched variable).
     *
     * @return string|null - Variable name when found, null when the `@param` tag has no closing variable token.
     */
    private static function scanForParamVariable(string $stripped, int $length, int &$position): ?string
    {
        $depth = 0;
        while ($position < $length) {
            $character = $stripped[$position];

            if ($character === '{' || $character === '<' || $character === '[' || $character === '(') {
                $depth++;
                $position++;
                continue;
            }

            if ($character === '}' || $character === '>' || $character === ']' || $character === ')') {
                if ($depth > 0) {
                    $depth--;
                }
                $position++;
                continue;
            }

            if ($depth === 0 && $character === '@') {
                // Reached the next tag before a variable; this @param has no input name.
                return null;
            }

            // Anchored regex pulls the `$name` token at the current cursor position; the `/A` anchor enforces start-at-offset.
            if ($depth === 0 && $character === '$' && preg_match('/\$(\w+)/A', $stripped, $matches, 0, $position) === 1) {
                $position += strlen($matches[0]);

                return $matches[1];
            }

            $position++;
        }

        return null;
    }

    /**
     * Remove docblock framing (`/**`, ` * `, `*\/`) so the result reads as flat prose for the
     * depth-aware parser. Newlines are preserved so multi-line bracketed shapes keep their line breaks
     * for caller inspection; only the per-line `*` decoration is stripped.
     *
     * @param string $docText - Raw docblock text including its `/**`, ` * `, and `*\/` framing.
     *
     * @return string - Docblock text without framing characters.
     */
    private static function stripDocFraming(string $docText): string
    {
        $stripped = preg_replace('/^\s*\/\*\*+/', '', $docText) ?? '';
        $stripped = preg_replace('/\*\/\s*$/', '', $stripped) ?? '';

        return preg_replace('/^\s*\*\s?/m', '', $stripped) ?? '';
    }

    /**
     * Check whether a docblock carries enough contract text to require @param tags.
     *
     * @param string $docText - Raw docblock text scanned for prose or contract-bearing tags.
     *
     * @return bool - True when parameter tags should be enforced.
     */
    private function hasContractDoc(string $docText): bool
    {
        foreach (preg_split('/\R/', $docText) ?: [] as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }

            // A non-empty, non-tag line is prose: the docblock states a contract.
            return true;
        }

        // Treat contract-bearing tags as enough context to require complete parameter docs.
        return preg_match('/@(param|return|throws|var|template|phpstan-param|psalm-param)\b/', $docText) === 1;
    }
}
