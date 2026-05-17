<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Naming;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Detects bool-returning callables without boolean-style names.
 */
final readonly class BooleanPrefixRule implements RuleInterface
{
    /**
     * Stable identifier for the boolean prefix rule.
     */
    public const ID = 'naming.boolean-prefix';

    /**
     * Prefixes that make boolean-returning callables read as predicates.
     */
    private const GOOD_PREFIXES = [
        'is', 'has', 'can', 'should', 'will', 'was', 'does', 'allows',
        'all', 'contains', 'extends', 'invokes', 'matches', 'refers', 'returns',
        'looks', 'supports', 'touches', 'uses',
    ];

    /**
     * Describe the boolean method prefix rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Boolean method prefix',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  ['allowedPrefixes' => self::GOOD_PREFIXES],
        );
    }

    /**
     * Find bool-returning functions and methods without a boolean-style prefix.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for poorly named boolean callables.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $prefixes   = $context->settingsFor($definition)->stringListOption('allowedPrefixes');
        $finder     = new NodeFinder();
        $nodes      = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            if (!$this->returnsBool($node)) {
                continue;
            }

            $name = $node->name->toString();

            if ($this->hasAllowedPrefix($name, $prefixes)) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s returns bool but does not use a boolean prefix (is, has, can, should, will).', $symbol),
                filePath:    $unit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: 'Rename to use a boolean prefix, e.g. isActive(), hasPermission().',
            );
        }

        return $findings;
    }

    /**
     * Check whether a function-like declaration has an explicit bool return type.
     *
     * @return bool True when the declared return type is bool.
     */
    private function returnsBool(ClassMethod|Function_ $node): bool
    {
        $returnType = $node->getReturnType();

        if ($returnType instanceof Identifier) {
            return $returnType->toString() === 'bool';
        }

        if ($returnType instanceof Name) {
            return $returnType->toString() === 'bool';
        }

        return false;
    }

    /**
     * Check whether the callable name starts with a configured predicate prefix.
     *
     * @param list<string> $prefixes Configured predicate prefixes.
     * @return bool True when the name has an allowed prefix followed by a word boundary.
     */
    private function hasAllowedPrefix(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            if (strlen($name) === strlen($prefix)) {
                return true;
            }

            $nextChar = $name[strlen($prefix)];
            if ($nextChar >= 'A' && $nextChar <= 'Z') {
                return true;
            }
        }

        return false;
    }
}
