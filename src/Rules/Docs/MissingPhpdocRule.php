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
use GruffPhp\Support\DeclarationLine;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags a method that carries no docblock of its own, so the user documents its intent - the strictest
 * docs rule (error severity), since an undocumented method is the one a reviewer most has to reverse-engineer.
 *
 * Runs per file over every method declaration, reporting each one with no local PHPDoc block. High
 * confidence. Inherited or trait-provided documentation does not count; the rule wants a local block.
 * The message is built from the signature: it names each parameter, and asks for the return value only when
 * one exists and for the throw condition only when the body throws in its own scope.
 * With the rule's `everyCallable` option on (off by default), undocumented named functions are reported too.
 */
final readonly class MissingPhpdocRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing method PHPDoc findings.
     */
    public const ID = 'docs.missing-phpdoc';

    /**
     * Id the rule carried before 0.6.0, when its name read as public-only; the registry still resolves it, so 0.5 configs and flags keep working.
     */
    public const LEGACY_ID = 'docs.missing-public-phpdoc';

    /**
     * Description of this rule's `everyCallable` option, which widens it from methods to named functions.
     */
    private const EVERY_CALLABLE_DESCRIPTION = 'When true, the rule also reports named functions with no docblock, not only methods.';

    /**
     * Describes the missing-method-PHPDoc rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (error severity, high confidence).
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Missing method PHPDoc',
            description:        'Flags a method declaration with no PHPDoc block; with `everyCallable`, also undocumented named functions.',
            pillar:             Pillar::Documentation,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Error,
            confidence:         Confidence::High,
            defaultOptions:     [DocumentedCallables::EVERY_CALLABLE => false],
            optionDescriptions: [DocumentedCallables::EVERY_CALLABLE => self::EVERY_CALLABLE_DESCRIPTION],
        );
    }

    /**
     * Reports each method declaration that has no local PHPDoc block, and each named function when `everyCallable` is on.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass, carrying the rule's `everyCallable` option.
     *
     * @return list<Finding> - Findings for undocumented methods and, with the option on, undocumented named functions.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $findings   = [];

        $everyCallable = ($ruleContext->settingsFor($definition)->options[DocumentedCallables::EVERY_CALLABLE] ?? false) === true;

        // Methods are always checked; named functions only with everyCallable on, so default findings are unchanged.
        $declarations = $everyCallable
            ? NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class])
            : NodeIndex::nodesOf($analysisUnit, ClassMethod::class);

        // Check every collected method and function declaration.
        foreach ($declarations as $declaration) {
            /** @var ClassMethod|Function_ $declaration Finder predicate restricts results to method and function declarations. */
            // A declaration that already carries a docblock is fine.
            if ($declaration->getDocComment() !== null) {
                continue;
            }

            $findings[] = $this->findingForDeclaration($analysisUnit, $definition, $declaration);
        }

        return $findings;
    }

    /**
     * Builds the missing-PHPDoc finding for one undocumented method or named function.
     *
     * @param AnalysisUnit          $analysisUnit - Parsed unit supplying the display path reported in the finding.
     * @param RuleDefinition        $definition   - Rule metadata supplying severity, pillar, tier, and confidence.
     * @param ClassMethod|Function_ $declaration  - Undocumented method or named function whose name and start line are reported.
     *
     * @return Finding - Documentation finding.
     */
    private function findingForDeclaration(AnalysisUnit $analysisUnit, RuleDefinition $definition, ClassMethod|Function_ $declaration): Finding
    {
        $symbol = CyclomaticComplexityRule::resolveSymbol($declaration);
        $kind   = $declaration instanceof ClassMethod ? 'method' : 'function';

        // Finding anchored at the declaration line, flagging its absent docblock.
        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('%s %s needs a brief intent description above its declaration (one plain-English line; not a restatement of the %s signature).%s', ucfirst($kind), $symbol, $kind, $this->signatureClause($declaration)),
            filePath:    $analysisUnit->file->displayPath,
            line:        DeclarationLine::of($declaration),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: sprintf('Add a `/** Description. */` block above the %s that opens with a one-line intent summary. This rule wants content, not boilerplate - if your project policy is "no comments", that policy is about avoiding comments that restate code, not about removing documentation. The description should answer "what is this for and what must the caller satisfy", and the block should also describe anything the message lists.', $kind),
        );
    }

    /**
     * Builds the message's second sentence from the declaration's signature and body.
     *
     * An undocumented declaration documents none of its parameters, so every one is named. The return value is
     * asked for only when the declaration yields one, and the throw condition only when the body throws in its
     * own scope; both use the tests docs.return-comment and docs.missing-throws-tag apply.
     *
     * @param ClassMethod|Function_ $declaration - Undocumented method or named function to read.
     *
     * @return string - ` Also describe ...` naming what the block must cover, or an empty string when the signature asks for nothing beyond intent.
     */
    private function signatureClause(ClassMethod|Function_ $declaration): string
    {
        $asks       = [];
        $paramNames = [];

        // No docblock means no parameter is described, so name each one.
        foreach ($declaration->params as $param) {
            // Only a plain variable carries a name to quote.
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $paramNames[] = '$' . $param->var->name;
            }
        }

        if ($paramNames !== []) {
            $asks[] = (count($paramNames) === 1 ? 'parameter ' : 'parameters ') . self::joinWords($paramNames);
        }

        // Ask for the return value only when the declaration yields one.
        if ((new ReturnCommentRule())->isValueReturning($declaration)) {
            $asks[] = 'the return value';
        }

        // Ask for the failure condition only when the body throws in its own scope.
        if ((new MissingThrowsTagRule())->hasDirectThrow($declaration->getStmts() ?? [])) {
            $asks[] = 'when it throws';
        }

        return $asks === [] ? '' : ' Also describe ' . self::joinWords($asks) . '.';
    }

    /**
     * Joins words as an English list: "a", "a and b", "a, b and c".
     *
     * @param non-empty-list<string> $words - Words to join, in order.
     *
     * @return string - The joined list.
     */
    private static function joinWords(array $words): string
    {
        $last = array_pop($words);

        return $words === [] ? $last : implode(', ', $words) . ' and ' . $last;
    }
}
