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
        // Use warning severity at high confidence because a local @var without a reason leaves a clear documentation gap.
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
     * @param AnalysisUnit $analysisUnit - Parsed file supplying source text, statement nodes, and finding locations.
     * @param RuleContext $ruleContext - Required by the rule interface; unused by this check.
     *
     * @return list<Finding> - one finding per bare local var assertion; empty when every assertion carries a reason
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {

        if (!str_contains($analysisUnit->source, '@var')) {

            return [];
        }

        // AST nodes distinguish declaration docblocks from local assertions even when attributes precede a declaration.






        $definition = $this->definition();
        $findings   = [];
        $nodeFinder = new NodeFinder();

        $candidates = $nodeFinder->find(
            $analysisUnit->statements,
            static fn(Node $node): bool => $node->getDocComment() instanceof Doc,
        );


        foreach ($candidates as $node) {
            $doc = $node->getDocComment();

            if (!$doc instanceof Doc) {
                continue;
            }

            $docText = $doc->getText();

            if (!str_contains($docText, '@var')) {
                continue;
            }

            // A var tag on a declaration documents that declaration, not a local assertion.
            if ($this->isDeclarationNode($node)) {
                continue;
            }


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
     * @param Node $node - Node carrying the @var docblock; declaration annotations are exempt from this rule.
     *
     * @return bool - true when the docblock documents a declaration (exempt); false for a local @var assertion to judge.
     */
    private function isDeclarationNode(Node $node): bool
    {

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
     * @return list<string> - Variable names from bare @var tags; empty when the docblock contains any prose line.
     */
    private function bareVarAnnotations(string $docText): array
    {
        $descriptiveLines = [];
        $bareVariables    = [];

        // Split the docblock into physical lines so tags and shared prose can be classified separately.
        foreach (preg_split('/\R/', $docText) ?: [] as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");


            if ($line === '') {
                continue;
            }

            // Treat non-tag content as a shared explanation for the docblock.
            if (!str_starts_with($line, '@')) {
                $descriptiveLines[] = $line;
                continue;
            }

            // Capture the asserted variable and any trailing explanation on local @var tags.
            if (preg_match('/^@var\b.*?\$(?<variable>[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)(?<description>.*)$/u', $line, $matches) !== 1) {
                continue;
            }

            // Treat trailing content after the variable name as this assertion's reason.
            if (trim($matches['description']) !== '') {
                continue;
            }

            $bareVariables[] = $matches['variable'];
        }

        if ($descriptiveLines !== []) {
            // Shared non-tag content suppresses every bare-@var finding for this docblock.
            return [];
        }


        return $bareVariables;
    }
}
