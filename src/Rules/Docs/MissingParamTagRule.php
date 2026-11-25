<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Flags documented callable parameters that lack an `@param` tag so documentation stays aligned with signatures.
 * Ordinary docblock skeletons are exempt; constructor skeletons still owe tags for promoted parameters.
 *
 * Overrides may reuse same-file ancestor tags by name or position, or an inheritance marker for a contract outside this file.
 * Constructors never inherit parameter docs. Multi-line array shapes are supported; named closures and arrows follow `everyCallable`.
 * Advisory severity, high confidence.
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
            id:                 self::ID,
            name:               'Missing @param tag',
            description:        'Flags a documented method or function missing an @param tag for one of its parameters; with `everyCallable`, also closures and arrow functions bound to a name, read from the docblock above the assignment.',
            pillar:             Pillar::Documentation,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::High,
            defaultOptions:     [DocumentedCallables::EVERY_CALLABLE => true],
            optionDescriptions: [DocumentedCallables::EVERY_CALLABLE => DocumentedCallables::EVERY_CALLABLE_DESCRIPTION],
        );
    }

    /**
     * Reports undocumented parameters for contract-bearing docblocks and promoted parameters in constructor skeletons.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Context passed to callable selection for this rule.
     *
     * @return list<Finding> - Findings for missing parameter tags.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition        = $this->definition();
        $nodeFinder        = new NodeFinder();
        $inheritanceHelper = new DocsInheritanceHelper();

        $findings = [];

        // Check every method and function in the file, plus bound closures when everyCallable is on.
        foreach (DocumentedCallables::inUnit($analysisUnit, $ruleContext, $definition) as ['node' => $node, 'doc' => $docComment, 'symbol' => $symbol]) {

            if ($docComment === null || $node->params === []) {
                continue;
            }

            $docText        = $docComment->getText();
            $hasContractDoc = $this->hasContractDoc($docText);

            // A skeleton gets narrow enforcement only when promoted parameters need a documentation owner.
            if (!$hasContractDoc && !$this->hasPromotedConstructorParameter($node)) {
                continue;
            }

            $owingParams = $this->paramsOwingTags(
                node:              $node,
                hasContractDoc:    $hasContractDoc,
                documentedParams:  $this->extractParamNames($docText),
                statements:        $analysisUnit->statements,
                nodeFinder:        $nodeFinder,
                inheritanceHelper: $inheritanceHelper,
            );

            foreach ($owingParams as $paramName => $param) {
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
     * Extracts parameter names from @param tags.
     * Depth-aware scanning finds a multi-line array shape's variable on a later line.
     *
     * @param string $docText - Raw docblock text to scan for input-parameter tags.
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

            // Only plain `@param` documents an input; accepting output or constraint tags would hide a missing input tag.
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
     * Walks forward from a parameter tag while tracking balanced braces and brackets.
     * Returns the first parameter variable token reached at depth zero.
     * Stops at the next depth-zero at-sign or the end of input.
     *
     * @param string $stripped - Docblock text with `/**`, ` * `, and `*\/` framing removed.
     * @param int    $length   - Pre-computed `strlen($stripped)` to avoid recomputation per call.
     * @param int    $position - Cursor position (mutated to point past the matched variable).
     *
     * @return string|null - Variable name when found; null when none appears before the stop point.
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
                // Another depth-zero at-sign appears before a variable, so this parameter tag has no input name.
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
     * Removes docblock framing for the depth-aware parameter parser, preserving newlines in multi-line type shapes.
     *
     * @param string $docText - Raw docblock text including its `/**`, ` * `, and `*\/` framing.
     *
     * @return string - Docblock text without framing characters.
     */
    private static function stripDocFraming(string $docText): string
    {
        // Trim the opener, including runs such as /***, and the closing */; interior text is untouched.
        $stripped = preg_replace('/^\s*\/\*\*+/', '', $docText) ?? '';
        $stripped = preg_replace('/\*\/\s*$/', '', $stripped) ?? '';

        // Remove leading whitespace, a star and at most one following whitespace character; matches can consume newlines.
        return preg_replace('/^\s*\*\s?/m', '', $stripped) ?? '';
    }

    /**
     * Returns the parameters that owe an `@param` tag of their own.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node              - Documented function-like being checked.
     * @param bool                                        $hasContractDoc    - Whether its docblock carries a contract rather than a skeleton.
     * @param list<string>                                $documentedParams  - Parameter names its own docblock already documents.
     * @param list<Node\Stmt>                             $statements        - The unit's statements, searched for the overridden method.
     * @param NodeFinder                                  $nodeFinder        - Shared finder reused across nodes.
     * @param DocsInheritanceHelper                       $inheritanceHelper - Resolves same-file ancestors and inheritance markers.
     *
     * @return array<string, Node\Param> - Missing own or inherited tags, keyed by name in signature order; skeletons owe promoted parameters only.
     */
    private function paramsOwingTags(
        ClassMethod|Function_|Closure|ArrowFunction $node,
        bool $hasContractDoc,
        array $documentedParams,
        array $statements,
        NodeFinder $nodeFinder,
        DocsInheritanceHelper $inheritanceHelper,
    ): array {
        $owing = [];
        // A contract docblock requires every missing named parameter tag; a skeleton requires tags only for promoted properties.
        foreach ($node->params as $param) {
            $paramName = $this->paramName($param);
            if ($paramName !== null && ($hasContractDoc || $param->isPromoted()) && !in_array($paramName, $documentedParams, true)) {
                $owing[$paramName] = $param;
            }
        }

        // An inherited contract can discharge what is left; that lookup walks the file, so it runs only when needed.
        $inherited = $owing === [] ? [] : $this->inheritedParamNames($node, $statements, $nodeFinder, $inheritanceHelper);

        return array_diff_key($owing, array_flip($inherited));
    }

    /**
     * Find names covered by inherited method docs; constructors own their signature and promoted-property docs.
     * Same-file ancestors cover documented names or positions; an ancestor's inheritance marker covers its declared positions.
     * Without a resolved ancestor method, a valid marker covers every named parameter; non-method callables return none.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node              - Documented function-like with at least one undocumented parameter.
     * @param list<Node\Stmt>                             $statements        - The unit's statements, searched for the overridden method.
     * @param NodeFinder                                  $nodeFinder        - Shared finder reused across nodes.
     * @param DocsInheritanceHelper                       $inheritanceHelper - Resolves same-file ancestors and inheritance markers.
     *
     * @return list<string> - Parameter names already covered by inheritance; empty when no inherited contract applies.
     */
    private function inheritedParamNames(
        ClassMethod|Function_|Closure|ArrowFunction $node,
        array $statements,
        NodeFinder $nodeFinder,
        DocsInheritanceHelper $inheritanceHelper,
    ): array {
        if (!$node instanceof ClassMethod || strtolower($node->name->toString()) === '__construct') {
            return [];
        }

        $ownNames        = array_map($this->paramName(...), $node->params);
        $ancestorMethods = $inheritanceHelper->sameFileAncestorMethods($node, $statements, $nodeFinder);
        if ($ancestorMethods === []) {
            // With no same-file ancestor to inspect, an inheritance marker stands in for every named parameter.
            return $inheritanceHelper->hasInheritanceMarker($node) ? array_values(array_filter($ownNames, is_string(...))) : [];
        }

        $inherited = [];

        // Aggregate documented parameters across every matching direct ancestor so multi-interface implementations inherit all declared tags.
        foreach ($ancestorMethods as $ancestorMethod) {
            // A same-file ancestor with an inheritance marker is treated as covering every named position it declares.
            $ancestorDocumented = $inheritanceHelper->hasInheritanceMarker($ancestorMethod)
                ? array_filter(array_map($this->paramName(...), $ancestorMethod->params), is_string(...))
                : $this->extractParamNames($ancestorMethod->getDocComment()?->getText() ?? '');
            $ancestorNames = array_map($this->paramName(...), $ancestorMethod->params);

            // A parameter the ancestor also declares is matched by name; a renamed one is matched by position.
            // It is inherited only when the ancestor documents it; parameters added past that signature remain owed here.
            foreach ($ownNames as $position => $ownName) {
                $ancestorName = in_array($ownName, $ancestorNames, true) ? $ownName : ($ancestorNames[$position] ?? null);
                // A parameter is inherited only when the matching ancestor parameter, by name or by position, has its own @param.
                if ($ownName !== null && $ancestorName !== null && in_array($ancestorName, $ancestorDocumented, true)) {
                    $inherited[] = $ownName;
                }
            }
        }

        return array_values(array_unique($inherited));
    }

    /**
     * Returns a parameter's plain variable name.
     *
     * @param Node\Param|null $param - Parameter to read, or null past the end of a signature.
     *
     * @return string|null - the name without `$`; null for a missing or dynamically named parameter
     */
    private function paramName(?Node\Param $param): ?string
    {
        return $param?->var instanceof Variable && is_string($param->var->name) ? $param->var->name : null;
    }

    /**
     * Reports whether a constructor promotes at least one parameter into a property.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Documented function-like whose skeleton may need narrow enforcement.
     *
     * @return bool - True for a constructor with at least one promoted parameter.
     */
    private function hasPromotedConstructorParameter(ClassMethod|Function_|Closure|ArrowFunction $node): bool
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
        // \R splits on any newline sequence, so CRLF docblocks are read line by line too.
        foreach (preg_split('/\R/', $docText) ?: [] as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }

            return true;
        }

        // Treat contract-bearing tags as enough context to require complete parameter docs.
        return preg_match('/@(param|return|throws|var|template|phpstan-param|psalm-param)\b/', $docText) === 1;
    }
}
