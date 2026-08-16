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
 * Flags a documented method or function whose parameters have no matching `@param` tag, so the user keeps
 * the documented contract in step with the signature at any visibility.
 *
 * Runs per file over documented function-likes that carry contract prose or tags. A constructor skeleton
 * also enforces tags for promoted parameters only, keeping property documentation under one rule without
 * changing ordinary skeleton parameters. A depth-aware parser reads the documented parameter names,
 * tolerating multi-line array shapes. Advisory, high confidence.
 */
final readonly class MissingParamTagRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing @param tag findings.
     */
    public const ID = 'docs.missing-param-tag';

    /**
     * Describes the missing `@param` tag rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (advisory severity, high confidence).
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
     * Reports undocumented parameters for contract-bearing docblocks and promoted parameters in constructor skeletons.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for missing parameter tags.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        // Check every method and function in the file.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $docComment = $node->getDocComment();

            // Skip a callable with no docblock or no parameters to document.
            if ($docComment === null || $node->params === []) {
                continue;
            }

            $docText        = $docComment->getText();
            $hasContractDoc = $this->hasContractDoc($docText);

            // A skeleton gets narrow enforcement only when promoted parameters need a documentation owner.
            if (!$hasContractDoc && !$this->hasPromotedConstructorParameter($node)) {
                continue;
            }

            $documentedParams = $this->extractParamNames($docText);
            $symbol           = CyclomaticComplexityRule::resolveSymbol($node);

            // Check each signature parameter has a matching tag.
            foreach ($node->params as $param) {
                // Preserve skeleton behaviour for ordinary parameters; only promoted properties gain an owner.
                if (!$hasContractDoc && !$param->isPromoted()) {
                    continue;
                }

                // Only a plain named parameter can be matched by name.
                if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                    continue;
                }

                $paramName = $param->var->name;

                // A documented parameter is fine.
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
     * Extracts the parameter names documented by `@param` tags. Depth-aware so multi-line array shapes match
     * the closing documented variable even when it sits on a different line from the `@param` token.
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

        // Walk the docblock, finding each param tag in turn.
        while ($position < $length) {
            $tagPosition = strpos($stripped, '@param', $position);
            // No more param tags left to read.
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
                // A following word character marks a different tag such as param-out.
                if ($next === '-' || ctype_alnum($next) || $next === '_') {
                    continue;
                }
            }

            $varName = self::scanForParamVariable($stripped, $length, $position);
            // Record the variable this tag documents.
            if ($varName !== null) {
                $paramNames[] = $varName;
            }
        }

        return $paramNames;
    }

    /**
     * Walks forward from a `@param` token through balanced `{} <> [] ()` brackets and returns the
     * first parameter variable token reached at depth zero. Stops at the next `@tag` (next docblock entry) or
     * end of input.
     *
     * @param string $stripped - Docblock text with `/**`, ` * `, and `*\/` framing removed.
     * @param int    $length   - Pre-computed `strlen($stripped)` to avoid recomputation per call.
     * @param int    $position - Cursor position (mutated to point past the matched variable).
     *
     * @return string|null - Variable name when found, null when the `@param` tag has no closing variable token.
     */
    private static function scanForParamVariable(string $stripped, int $length, int &$position): ?string
    {
        $depth = 0;
        // Scan forward through balanced brackets for the parameter variable.
        while ($position < $length) {
            $character = $stripped[$position];

            // An opening bracket enters a type shape.
            if ($character === '{' || $character === '<' || $character === '[' || $character === '(') {
                $depth++;
                $position++;
                continue;
            }

            // A closing bracket leaves the type shape.
            if ($character === '}' || $character === '>' || $character === ']' || $character === ')') {
                // Never let depth go negative on an unbalanced bracket.
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
     * Removes docblock framing (`/**`, ` * `, `*\/`) so the result reads as flat prose for the
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
     * Reports whether a constructor promotes at least one parameter into a property.
     *
     * @param ClassMethod|Function_ $node - Documented function-like whose skeleton may need narrow enforcement.
     *
     * @return bool - True for a constructor with at least one promoted parameter.
     */
    private function hasPromotedConstructorParameter(ClassMethod|Function_ $node): bool
    {
        if (!$node instanceof ClassMethod || strtolower($node->name->toString()) !== '__construct') {
            return false;
        }

        foreach ($node->params as $param) {
            if ($param->isPromoted()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a docblock carries enough contract text to require `@param` tags.
     *
     * @param string $docText - Raw docblock text scanned for prose or contract-bearing tags.
     *
     * @return bool - True when parameter tags should be enforced.
     */
    private function hasContractDoc(string $docText): bool
    {
        // Weigh each line of the docblock.
        foreach (preg_split('/\R/', $docText) ?: [] as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");
            // Blank and tag lines are not contract prose.
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
