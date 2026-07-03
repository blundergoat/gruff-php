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
 * Detects local var assertions that omit the reason the assertion is needed.
 */
final readonly class VarAnnotationDescriptionRule implements RuleInterface
{
    /**
     * Stable rule identifier for bare @var annotation findings.
     */
    public const ID = 'docs.var-annotation-description';

    /**
     * Describe the local var-annotation description rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
     * Find local var assertions that do not explain why the assertion is needed.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - parsed unit to inspect
     * @param RuleContext $ruleContext - rule context for this analysis pass
     *
     * @return list<Finding> - one finding per bare local var assertion; empty when every assertion carries a reason
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Fast bail: nothing to find when the file has no @var tag.
        // User view: choose the findings list branch for this case.
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
        // footgun documented at `.goat-flow/footguns/rules.md`.
        $definition = $this->definition();
        $findings   = [];
        $nodeFinder = new NodeFinder();

        $candidates = $nodeFinder->find(
            $analysisUnit->statements,
            static fn(Node $node): bool => $node->getDocComment() instanceof Doc,
        );

        // User view: add each item that can appear in findings list.
        foreach ($candidates as $node) {
            $doc = $node->getDocComment();
            // User view: choose the findings list branch for this case.
            if (!$doc instanceof Doc) {
                continue;
            }

            $docText = $doc->getText();
            // User view: choose the findings list branch for this case.
            if (!str_contains($docText, '@var')) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if ($this->isDeclarationNode($node)) {
                continue;
            }

            // User view: add each item that can appear in findings list.
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
     * Distinguish declaration docblocks from local variable assertion docblocks.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
     * Find local var assertion tags that name a type without explaining intent.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: add each item that can appear in findings list.
        foreach (preg_split('/\R/', $docText) ?: [] as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");

            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
            if ($line === '') {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (!str_starts_with($line, '@')) {
                $descriptiveLines[] = $line;
                continue;
            }

            // Capture the asserted variable and any trailing explanation on local @var tags.
            // User view: choose the findings list branch for this case.
            if (preg_match('/^@var\b.*?\$(?<variable>[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)(?<description>.*)$/u', $line, $matches) !== 1) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
            if (trim($matches['description']) !== '') {
                continue;
            }

            $bareVariables[] = $matches['variable'];
        }

        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($descriptiveLines !== []) {
            // Any prose line already explains the docblock, so suppress every bare-@var finding for it.
            return [];
        }

        // Only tag-only docblocks reach here, so surface the variables whose @var carried no reason.
        return $bareVariables;
    }
}
