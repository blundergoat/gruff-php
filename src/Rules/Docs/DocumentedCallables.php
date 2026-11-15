<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Comment\Doc;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;

/**
 * Lists the callables a per-callable docs rule checks, each with the docblock that documents it and its display symbol.
 *
 * Methods and named functions are always listed; everyCallable also includes directly assigned closures and arrow functions.
 * Assigned callables use their own docblock, then the assignment's, then its immediate parent if it is an expression statement.
 */
final class DocumentedCallables
{
    /**
     * Per-rule option key for named functions or bound closures, depending on the documentation rule.
     * Missing PHPDoc, parameter tags and return descriptions default to true; param-comment defaults to false.
     * An explicit false disables that rule's expanded callable scope.
     */
    public const EVERY_CALLABLE = 'everyCallable';

    /**
     * Option description shared by the docs rules that list callables through inUnit(); docs.missing-phpdoc accepts the
     * same switch but widens to named functions, so it carries its own description.
     */
    public const EVERY_CALLABLE_DESCRIPTION = 'When true, the rule also checks closures and arrow functions assigned to a name, documented by the docblock above the assignment.';

    /**
     * Lists the callables a docs rule checks in one unit, honouring the rule's everyCallable option.
     *
     * @param AnalysisUnit   $unit       - parsed unit to inspect
     * @param RuleContext    $context    - rule context carrying the rule's configured options
     * @param RuleDefinition $definition - definition of the rule whose options decide whether bound closures are listed
     *
     * @return list<array{node: ClassMethod|Function_|Closure|ArrowFunction, doc: ?Doc, symbol: string}> - methods and functions first;
     *         assigned closures and arrow functions follow only when everyCallable is enabled.
     */
    public static function inUnit(AnalysisUnit $unit, RuleContext $context, RuleDefinition $definition): array
    {
        $callables = [];


        foreach (NodeIndex::nodesOfAny($unit, [ClassMethod::class, Function_::class]) as $node) {
            /** @var ClassMethod|Function_ $node - Index lookup restricts results to methods and named functions. */
            $callables[] = ['node' => $node, 'doc' => $node->getDocComment(), 'symbol' => CyclomaticComplexityRule::resolveSymbol($node)];
        }

        if (($context->settingsFor($definition)->options[self::EVERY_CALLABLE] ?? false) !== true) {

            return $callables;
        }

        // An assigned closure or arrow function expands the review beyond declared methods and functions.
        foreach (NodeIndex::nodesOf($unit, Assign::class) as $assign) {
            /** @var Assign $assign - Index bucket holds assignment expressions only. */
            $callable = $assign->expr;


            if (!$callable instanceof Closure && !$callable instanceof ArrowFunction) {
                continue;
            }

            $callables[] = [
                'node' => $callable,
                'doc' => self::bindingDocComment($assign, $callable),
                'symbol' => sprintf('Closure@%d', $callable->getStartLine()),
            ];
        }

        return $callables;
    }

    /**
     * Finds the first docblock on the callable, its assignment, or an immediate expression-statement parent.
     *
     * @param Assign $assign - Assignment checked next; only an immediate expression-statement parent is also checked.
     * @param Closure|ArrowFunction $callable - Closure or arrow function whose own docblock takes precedence.
     *
     * @return Doc|null - First available docblock in that order, or null when none of the checked nodes has one.
     */
    private static function bindingDocComment(Assign $assign, Closure|ArrowFunction $callable): ?Doc
    {
        $doc = $callable->getDocComment() ?? $assign->getDocComment();

        if ($doc !== null) {
            return $doc;
        }

        $statement = $assign->getAttribute('parent');

        // A docblock above an assignment statement may sit on that enclosing expression statement instead.
        return $statement instanceof Expression ? $statement->getDocComment() : null;
    }
}
