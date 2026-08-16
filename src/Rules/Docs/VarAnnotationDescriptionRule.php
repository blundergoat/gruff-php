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
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

/**
 * Flags a local `@var` type assertion that names a type but never says why it is needed, so the user
 * records the invariant that makes the narrowing safe instead of leaving a bare cast for the next reader.
 *
 * Runs per file (bailing fast when the source has no `@var`), walking the AST so a `@var` on a property,
 * parameter, or other declaration is exempt - only genuine local assertions are judged. A docblock with
 * any prose line is treated as explained. Warning, high confidence.
 */
final readonly class VarAnnotationDescriptionRule implements RuleInterface
{
    /**
     * Stable rule identifier for bare @var annotation findings.
     */
    public const ID = 'docs.var-annotation-description';

    /**
     * Describes the local var-annotation description rule for the registry and reports.
     *
     * @return RuleDefinition - metadata and defaults the registry uses to wire this rule
     */
    public function definition(): RuleDefinition
    {
        // Warning at high confidence: a bare local @var is an unambiguous documentation gap, so it should be loud.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Var annotation description',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
            description:     'Requires local @var type assertions to explain why the asserted type is needed.',
        );
    }

    /**
     * Reports each local var assertion that names a type without explaining why it is needed.
     *
     * @param AnalysisUnit $analysisUnit - parsed unit to inspect
     * @param RuleContext $ruleContext - rule context for this analysis pass
     *
     * @return list<Finding> - one finding per bare local var assertion; empty when every assertion carries a reason
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Fast bail: nothing to find when the file has no @var tag.
        if (!str_contains($analysisUnit->source, '@var')) {
            // No @var anywhere means no annotation to judge, so report nothing.
            return [];
        }

        // AST-driven detection: PhpParser attaches each docblock to the immediately following
        // statement/declaration. A `@var` docblock on a property/method declaration documents
        // the declaration itself - not a local variable assertion - even when attribute groups
        // sit between the docblock and the property/function keyword. Walking the AST keeps
        // this distinction reliable; a token-stream walker has to skip past `T_ATTRIBUTE` tokens
        // by counting brackets, which is the "Heuristic rules overmatch nested syntax shapes"
        // footgun documented at `.goat-flow/learning-loop/footguns/rules.md`.
        $definition = $this->definition();
        $findings   = [];
        $nodeFinder = new NodeFinder();

        $candidates = $nodeFinder->find(
            $analysisUnit->statements,
            static fn(Node $node): bool => $node->getDocComment() instanceof Doc,
        );

        // Weigh each node that carries a docblock.
        foreach ($candidates as $node) {
            $doc = $node->getDocComment();
            // Skip a node whose docblock vanished between find and read.
            if (!$doc instanceof Doc) {
                continue;
            }

            $docText = $doc->getText();
            // Only a docblock with a var assertion is in scope.
            if (!str_contains($docText, '@var')) {
                continue;
            }

            // A var tag on a declaration documents that declaration, not a local assertion.
            if ($this->isDeclarationNode($node)) {
                continue;
            }

            // Report each bare assertion the docblock carries.
            foreach ($this->bareVarAnnotations($docText) as $variable) {
                $findings[] = new Finding(
                    ruleId:      $definition->id,
                    message:     sprintf('@var assertion for $%s needs a brief reason after the variable name (one plain-English clause; not a restatement of the asserted type).', $variable),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $doc->getStartLine(),
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      '$' . $variable,
                    remediation: sprintf('Add a short reason after $%s in the @var annotation (e.g. `@var SomeType $%s Why this narrow type holds here.`). This rule wants content, not boilerplate - the reason should answer "why is this assertion safe here, what invariant guarantees the type."', $variable, $variable),
                    metadata:    ['variable' => $variable],
                );
            }
        }

        return $findings;
    }

    /**
     * Reports whether a docblock documents a declaration (exempt) rather than a local var assertion.
     *
     * @param Node $node - AST node carrying the @var docblock; a declaration node means the tag documents that
     *                   declaration rather than a local assertion, so it is exempt from this rule.
     *
     * @return bool - true when the docblock documents a declaration (exempt); false for a local @var assertion to judge.
     */
    private function isDeclarationNode(Node $node): bool
    {
        // A @var on any of these declaration shapes documents the declaration, not a local assertion to flag.
        return $node instanceof Property
               || $node instanceof ClassMethod
               || $node instanceof Function_
               || $node instanceof ClassConst
               || $node instanceof Const_
               || $node instanceof ClassLike
               || $node instanceof Param;
    }

    /**
     * Finds the local var-assertion variables that state a type but give no reason.
     *
     * @param string $docText - raw docblock text, including the comment markers
     *
     * @return list<string> - variable names whose local var assertion stated a type but no reason; empty
     *   when the docblock carries any prose line
     */
    private function bareVarAnnotations(string $docText): array
    {
        $descriptiveLines = [];
        $bareVariables    = [];

        // Weigh each line of the docblock.
        foreach (preg_split('/\R/', $docText) ?: [] as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");

            // Skip blank lines.
            if ($line === '') {
                continue;
            }

            // A non-tag line is prose that already explains the docblock.
            if (!str_starts_with($line, '@')) {
                $descriptiveLines[] = $line;
                continue;
            }

            // Capture the asserted variable and any trailing explanation on local @var tags.
            if (preg_match('/^@var\b.*?\$(?<variable>[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)(?<description>.*)$/u', $line, $matches) !== 1) {
                continue;
            }

            // A trailing explanation means this assertion already states its reason.
            if (trim($matches['description']) !== '') {
                continue;
            }

            $bareVariables[] = $matches['variable'];
        }

        if ($descriptiveLines !== []) {
            // Any prose line already explains the docblock, so suppress every bare-@var finding for it.
            return [];
        }

        // Only tag-only docblocks reach here, so surface the variables whose @var carried no reason.
        return $bareVariables;
    }
}
