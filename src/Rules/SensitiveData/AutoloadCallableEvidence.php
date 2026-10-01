<?php

declare(strict_types=1);

namespace GruffPhp\Rules\SensitiveData;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;

/**
 * Keeps a proven local autoload class name out of the user's entropy warnings.
 *
 * The entropy detector uses this evidence when a scan includes PHP syntax.
 * Unresolved calls or unavailable target methods keep their normal secret checks.
 */
final class AutoloadCallableEvidence
{
    /**
     * Finds quoted class names that refer to an available public static autoload method in the scanned file.
     *
     * @param AnalysisUnit $unit - Scanned file with optional syntax evidence; missing or bounded syntax grants no exception.
     * @return array<int, true> - Proven literal positions; an empty map leaves every value eligible for entropy scoring.
     */
    public static function classLiteralOffsets(AnalysisUnit $unit): array
    {
        // A large or malformed file can lack reliable syntax, so its text still receives the normal entropy checks.
        if ($unit->hasParseErrors() || $unit->isDeepScanBounded()) {
            return [];
        }

        $classes = [];
        // For a generated Composer loader, only classes available without running a condition can establish the callable target.
        foreach (NodeIndex::nodesOf($unit, Stmt\Class_::class) as $class) {
            $parent = $class->getAttribute('parent');
            // Missing name resolution or a conditional/nested declaration leaves the class name eligible for a warning.
            if (!isset($class->namespacedName)
                || ($parent !== null && !$parent instanceof Stmt\Namespace_)) {
                continue;
            }
            $classes[strtolower($class->namespacedName->toString())] = $class;
        }

        $offsets = [];
        // Check each call separately; proving one literal must not suppress equal text elsewhere.
        foreach (NodeIndex::nodesOf($unit, Expr\FuncCall::class) as $call) {
            $literal = self::provenClassLiteral($call, $classes);
            // Only this proven class slot skips scoring; an unresolved call contributes no exception.
            if ($literal !== null) {
                $offsets[$literal->getStartFilePos() + 1] = true;
            }
        }

        return $offsets;
    }

    /**
     * Proves the class slot belongs to a built-in autoload operation with an available local target.
     *
     * @param Expr\FuncCall              $call    - Call to examine.
     * @param array<string, Stmt\Class_> $classes - Unconditional local class declarations by lowercase name.
     * @return String_|null - Proven class slot; null keeps the call's strings eligible for entropy warnings.
     */
    private static function provenClassLiteral(Expr\FuncCall $call, array $classes): ?String_
    {
        // A similarly named imported function cannot grant the built-in autoload exception.
        if (!self::isResolvedAutoloadBuiltin($call)) {
            return null;
        }

        // An omitted first argument provides no callable class to exempt from the scan.
        $argument = $call->args[0] ?? null;
        // Named, unpacked or non-pair arguments do not prove which value is the callable class.
        if (!$argument instanceof Node\Arg || $argument->unpack || $argument->name !== null
            || !$argument->value instanceof Expr\Array_ || count($argument->value->items) !== 2) {
            return null;
        }
        [$classItem, $methodItem] = $argument->value->items;
        // Positional string slots are enough for Composer; dynamic or keyed arrays remain unproven.
        if ($classItem->key !== null || $methodItem->key !== null
            || $classItem->unpack || $methodItem->unpack
            || !$classItem->value instanceof String_ || !$methodItem->value instanceof String_) {
            return null;
        }

        $class = $classes[strtolower(ltrim($classItem->value->value, '\\'))] ?? null;
        // A missing local class or method cannot justify hiding its quoted name from the user.
        $method = $class?->getMethod($methodItem->value->value);
        // The callback needs a concrete public static method before its class slot can skip entropy scoring.
        if ($method === null || !$method->isPublic() || !$method->isStatic() || $method->isAbstract()) {
            return null;
        }

        return $classItem->value;
    }

    /**
     * Requires name-resolution evidence instead of trusting the spelling of a possibly rebound function.
     *
     * @param Expr\FuncCall $call - Call whose function binding is being proved.
     * @return bool - Whether the resolved function is either built-in autoload operation.
     */
    private static function isResolvedAutoloadBuiltin(Expr\FuncCall $call): bool
    {
        // A dynamic function name cannot establish that this is PHP's built-in autoload operation.
        if (!$call->name instanceof Name) {
            return false;
        }
        $resolved = $call->name->getAttribute('resolvedName');

        return $resolved instanceof Name\FullyQualified
            && in_array(strtolower($resolved->toString()), ['spl_autoload_register', 'spl_autoload_unregister'], true);
    }
}
