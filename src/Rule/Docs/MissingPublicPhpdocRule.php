<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

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
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;

final readonly class MissingPublicPhpdocRule implements RuleInterface
{
    public const ID = 'docs.missing-public-phpdoc';

    private const EXEMPT_PREFIXES = ['get', 'set', 'is', 'has'];
    private const MAGIC_METHODS = [
        '__construct', '__destruct', '__clone', '__toString', '__debugInfo',
        '__get', '__set', '__isset', '__unset', '__call', '__callStatic',
        '__invoke', '__sleep', '__wakeup', '__serialize', '__unserialize',
        '__set_state',
    ];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Missing public method PHPDoc',
            pillar: Pillar::Documentation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::High,
            defaultThresholds: [
                'minBodyLines' => 8,
                'minParameters' => 2,
            ],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings = $context->settingsFor($definition);
        $minBodyLines = (int) $settings->numericThreshold('minBodyLines');
        $minParameters = (int) $settings->numericThreshold('minParameters');
        $finder = new NodeFinder();
        $classes = $finder->findInstanceOf($unit->statements, Class_::class);

        $findings = [];

        foreach ($classes as $class) {
            /** @var Class_ $class */
            if ($this->isImmutableValueObject($class)) {
                continue;
            }

            foreach ($class->getMethods() as $method) {
                if (!$method->isPublic() || $method->isAbstract()) {
                    continue;
                }

                $name = $method->name->toString();

                if (in_array($name, self::MAGIC_METHODS, true)) {
                    continue;
                }

                if ($this->isInheritedRuleContract($class, $name)) {
                    continue;
                }

                if ($this->isInternalHelperMethod($class) || $this->isConventionalReporterRender($class, $name)) {
                    continue;
                }

                if ($this->isTrivialAccessor($name)) {
                    continue;
                }

                $docComment = $method->getDocComment();

                if ($docComment !== null) {
                    continue;
                }

                if (!$this->needsIntentDoc($method, $finder, $minBodyLines, $minParameters)) {
                    continue;
                }

                $symbol = CyclomaticComplexityRule::resolveSymbol($method);

                $findings[] = new Finding(
                    ruleId: $definition->id,
                    message: sprintf('Public method %s has no PHPDoc.', $symbol),
                    filePath: $unit->file->displayPath,
                    line: $method->getStartLine(),
                    severity: $definition->defaultSeverity,
                    pillar: $definition->pillar,
                    tier: $definition->tier,
                    confidence: $definition->confidence,
                    symbol: $symbol,
                    remediation: 'Add a docblock describing the method\'s purpose.',
                );
            }
        }

        return $findings;
    }

    private function isInheritedRuleContract(Class_ $class, string $methodName): bool
    {
        if ($methodName !== 'definition' && $methodName !== 'analyse') {
            return false;
        }

        foreach ($class->implements as $interface) {
            if (in_array($this->shortName($interface), ['RuleInterface', 'SourceTextRuleInterface'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isInternalHelperMethod(Class_ $class): bool
    {
        $className = $class->name?->toString();

        return is_string($className) && str_ends_with($className, 'Helper');
    }

    private function isConventionalReporterRender(Class_ $class, string $methodName): bool
    {
        $className = $class->name?->toString();

        return $methodName === 'render' && is_string($className) && str_ends_with($className, 'Reporter');
    }

    private function shortName(Name $name): string
    {
        $parts = $name->getParts();

        return $parts[array_key_last($parts)];
    }

    private function isImmutableValueObject(Class_ $class): bool
    {
        if (!$class->isFinal() || !$class->isReadonly()) {
            return false;
        }

        if ($class->extends !== null) {
            return false;
        }

        $hasTypedProperty = false;

        foreach ($class->getProperties() as $property) {
            if ($property->type === null) {
                return false;
            }

            $hasTypedProperty = true;
        }

        if (!$hasTypedProperty) {
            foreach ($class->getMethods() as $method) {
                if ($method->name->toString() !== '__construct') {
                    continue;
                }

                foreach ($method->params as $param) {
                    if ($param->flags === 0) {
                        continue;
                    }

                    if ($param->type === null) {
                        return false;
                    }

                    $hasTypedProperty = true;
                }
            }
        }

        return $hasTypedProperty;
    }

    private function isTrivialAccessor(string $name): bool
    {
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix) && ctype_upper($name[strlen($prefix)])) {
                return true;
            }
        }

        return false;
    }

    private function needsIntentDoc(
        ClassMethod $method,
        NodeFinder $finder,
        int $minBodyLines,
        int $minParameters,
    ): bool {
        if (count($method->params) >= $minParameters) {
            return true;
        }

        if ($this->bodyLineCount($method) >= $minBodyLines) {
            return true;
        }

        return $finder->findFirst($method->stmts ?? [], static function (Node $node): bool {
            return $node instanceof If_
                || $node instanceof For_
                || $node instanceof Foreach_
                || $node instanceof While_
                || $node instanceof Do_
                || $node instanceof Switch_
                || $node instanceof TryCatch
                || $node instanceof Match_;
        }) !== null;
    }

    private function bodyLineCount(ClassMethod $method): int
    {
        if ($method->stmts === null || $method->stmts === []) {
            return 0;
        }

        $start = null;
        $end = null;

        foreach ($method->stmts as $statement) {
            $statementStart = $statement->getStartLine();
            $statementEnd = $statement->getEndLine();

            if ($statementStart <= 0 || $statementEnd <= 0) {
                continue;
            }

            $start = $start === null ? $statementStart : min($start, $statementStart);
            $end = $end === null ? $statementEnd : max($end, $statementEnd);
        }

        if ($start === null || $end === null) {
            return 0;
        }

        return $end - $start + 1;
    }
}
