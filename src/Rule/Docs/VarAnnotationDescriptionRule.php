<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
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
 * Detects local @var assertions that omit the reason the assertion is needed.
 */
final readonly class VarAnnotationDescriptionRule implements RuleInterface
{
    /**
     * Stable rule identifier for bare @var annotation findings.
     */
    public const ID = 'docs.var-annotation-description';

    /**
     * Describe the local @var annotation description rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
     * Find local @var assertions that do not explain why the assertion is needed.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for bare local @var annotations.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        // AST-driven detection: PhpParser attaches each docblock to the immediately following
        // statement/declaration. A `@var` docblock on a property/method declaration documents
        // the declaration itself - not a local variable assertion - even when attribute groups
        // sit between the docblock and the property/function keyword. Walking the AST keeps
        // this distinction reliable; a token-stream walker has to skip past `T_ATTRIBUTE` tokens
        // by counting brackets, which is the "Heuristic rules overmatch nested syntax shapes"
        // footgun documented at `.goat-flow/footguns/rules.md`.
        $definition = $this->definition();
        $findings   = [];
        $finder     = new NodeFinder();

        $candidates = $finder->find(
            $unit->statements,
            static fn (Node $node): bool => $node->getDocComment() instanceof Doc,
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

            if ($this->isDeclarationNode($node)) {
                continue;
            }

            foreach ($this->bareVarAnnotations($docText) as $variable) {
                $findings[] = new Finding(
                    ruleId:      $definition->id,
                    message:     sprintf('@var assertion for $%s must explain why the asserted type is needed.', $variable),
                    filePath:    $unit->file->displayPath,
                    line:        $doc->getStartLine(),
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      '$' . $variable,
                    remediation: sprintf('Add a short reason after $%s in the @var annotation.', $variable),
                    metadata:    ['variable' => $variable],
                );
            }
        }

        return $findings;
    }

    /**
     * Distinguish declaration docblocks from local variable assertion docblocks.
     *
     * @return bool True when the docblock belongs to a declaration node.
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
     * @return list<string>
     */
    private function bareVarAnnotations(string $docText): array
    {
        $descriptiveLines = [];
        $bareVariables    = [];

        foreach (preg_split('/\R/', $docText) ?: [] as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");

            if ($line === '') {
                continue;
            }

            if (!str_starts_with($line, '@')) {
                $descriptiveLines[] = $line;
                continue;
            }

            if (preg_match('/^@var\b.*?\$(?<variable>[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)(?<description>.*)$/u', $line, $matches) !== 1) {
                continue;
            }

            if (trim($matches['description']) !== '') {
                continue;
            }

            $bareVariables[] = $matches['variable'];
        }

        if ($descriptiveLines !== []) {
            return [];
        }

        return $bareVariables;
    }
}
