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
 * Methods and named functions are always listed. With a rule's `everyCallable` option on, a closure or arrow function
 * assigned to a name (`$format = function () {...};`, `$this->handler = fn () => ...;`) is listed too, documented by
 * the docblock above its assignment, because php-parser attaches that docblock to the assignment, not the closure.
 */
final class DocumentedCallables
{
    /**
     * Per-rule option that widens a docs rule from methods and named functions to closures and arrow functions bound
     * to a name. Off by default, so default findings are unchanged.
     */
    public const EVERY_CALLABLE = 'everyCallable';

    /**
     * Option description shared by every docs rule that accepts the `everyCallable` switch.
     */
    public const EVERY_CALLABLE_DESCRIPTION = 'When true, the rule also checks closures and arrow functions assigned to a name, documented by the docblock above the assignment.';

    /**
     * Lists the callables a docs rule checks in one unit, honouring the rule's `everyCallable` option.
     *
     * @param AnalysisUnit   $unit       - parsed unit to inspect
     * @param RuleContext    $context    - rule context carrying the rule's configured options
     * @param RuleDefinition $definition - definition of the rule whose options decide whether bound closures are listed
     *
     * @return list<array{node: ClassMethod|Function_|Closure|ArrowFunction, doc: ?Doc, symbol: string}> - methods
     *         and functions in source order, then bound closures and arrow functions when the option is on
     */
    public static function inUnit(AnalysisUnit $unit, RuleContext $context, RuleDefinition $definition): array
    {
        $callables = [];

        // Methods and named functions are always checked, documented by their own docblock.
        foreach (NodeIndex::nodesOfAny($unit, [ClassMethod::class, Function_::class]) as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like declarations. */
            $callables[] = ['node' => $node, 'doc' => $node->getDocComment(), 'symbol' => CyclomaticComplexityRule::resolveSymbol($node)];
        }

        if (($context->settingsFor($definition)->options[self::EVERY_CALLABLE] ?? false) !== true) {
            // Off by default: closures stay out so default findings are unchanged.
            return $callables;
        }

        // A closure or arrow function assigned to a name is a callable a reader looks up by that name.
        foreach (NodeIndex::nodesOf($unit, Assign::class) as $assign) {
            /** @var Assign $assign Index bucket holds assignment expressions only. */
            $callable = $assign->expr;

            // Only an assigned closure or arrow function is a named callable; other assignments are not.
            if (!$callable instanceof Closure && !$callable instanceof ArrowFunction) {
                continue;
            }

            $callables[] = [
                'node'   => $callable,
                'doc'    => self::bindingDocComment($assign, $callable),
                'symbol' => sprintf('Closure@%d', $callable->getStartLine()),
            ];
        }

        return $callables;
    }

    /**
     * Finds the docblock that documents a bound closure: its own, else the one above its assignment statement.
     *
     * @param Assign                $assign   - assignment that binds the closure to a name
     * @param Closure|ArrowFunction $callable - the bound closure or arrow function
     *
     * @return Doc|null - the documenting docblock, or null when the binding has none
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
