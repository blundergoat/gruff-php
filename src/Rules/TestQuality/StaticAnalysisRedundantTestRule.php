<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Reports assertTrue() existence checks for class-like declarations or members indexed from the same parsed file.
 * These are source-based review candidates; runtime loading and compatibility contracts can still justify them.
 * A test can produce multiple findings, one per recognised assertion; findings are advisory at high confidence.
 */
final readonly class StaticAnalysisRedundantTestRule implements RuleInterface
{
    /**
     * Stable rule identifier for static-analysis-redundant test candidates.
     */
    public const ID = 'test-quality.static-analysis-redundant-test';

    /**
     * Describes the static-analysis-redundant-test rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Static-analysis-redundant test candidate',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
            description:     'Flags tests whose main assertion appears to verify only a static source declaration.',
            falsePositiveShapes: [
                [
                    'shape'      => 'Public API or compatibility contract where runtime existence is the behaviour under test.',
                    'mitigation' => 'Keep the test when the runtime contract is intentional; gruff reports this as a candidate, not a deletion command.',
                ],
            ],
        );
    }

    /**
     * Reports assertion calls that restate declarations already present in the parsed unit.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Required by the rule interface; unused by this rule.
     *
     * @return list<Finding> - Findings for static-analysis-redundant test candidates.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $declarations = $this->collectDeclarations($analysisUnit);
        // Without same-file declarations, this rule has no static redundancy evidence.
        if ($declarations === []) {
            return [];
        }

        $findings = [];


        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {

            foreach (TestQualityNodeHelper::assertionCalls($scope) as $assertionCall) {

                if (TestQualityNodeHelper::callName($assertionCall) !== 'asserttrue') {
                    continue;
                }

                $subjectCall = TestQualityNodeHelper::firstArgValue($assertionCall);

                if (!$subjectCall instanceof Expr\FuncCall) {
                    continue;
                }

                $candidate = $this->candidateFromSubjectCall($subjectCall, $declarations);
                // Keep only calls backed by a matching same-file declaration.
                if ($candidate === null) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:  self::ID,
                    message: sprintf(
                        '%s contains a static-analysis-redundant candidate: %s asserts %s, but %s.',
                        $scope->symbol,
                        $candidate['assertion'],
                        $candidate['evidenceSymbol'],
                        $candidate['staticFact'],
                    ),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $assertionCall->getStartLine(),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::High,
                    symbol:      $scope->symbol,
                    remediation: 'Remove only the redundant assertion, or replace it with behavioral evidence that static analysis cannot prove.',
                    metadata:    $candidate,
                );
            }
        }

        return $findings;
    }

    /**
     * Indexes the unit's class-like declarations by resolved and short name.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose declarations should be indexed.
     *
     * @return array<string, array{kind: string, name: string, methods: array<string, string>, properties: array<string, string>}> - Name-keyed rows.
     */
    private function collectDeclarations(AnalysisUnit $analysisUnit): array
    {
        $declarations = [];


        foreach ($this->topLevelClassLikes($analysisUnit) as $node) {

            if ($node->name === null) {
                continue;
            }

            $name = $this->classLikeName($node);

            if ($name === null) {
                continue;
            }

            $record = [
                'kind'       => $this->classLikeKind($node),
                'name'       => $name,
                'methods'    => [],
                'properties' => [],
            ];


            foreach ($node->stmts as $statement) {
                if ($statement instanceof Stmt\ClassMethod) {
                    // PHP resolves method names case-insensitively, so index by the lowercase name.
                    $methodName = $statement->name->toString();
                    $record['methods'][strtolower($methodName)] = $methodName;
                    continue;
                }

                // A property statement can declare several names.
                if ($statement instanceof Stmt\Property) {
                    foreach ($statement->props as $property) {
                        // PHP property names are case-sensitive, so index by the declared name as-is.
                        $propertyName = $property->name->toString();
                        $record['properties'][$propertyName] = $propertyName;
                    }
                }
            }


            foreach ($this->classLikeKeys($node, $name) as $key) {
                $declarations[$key] = $record;
            }
        }

        return $declarations;
    }

    /**
     * Collects class-like declarations directly at file or namespace scope, preserving source order.
     * Nested declarations are excluded because their registration depends on executing the enclosing code.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose top-level declarations should be collected.
     *
     * @return list<Stmt\ClassLike> - Class-like declarations at file or namespace scope, in source order.
     */
    private function topLevelClassLikes(AnalysisUnit $analysisUnit): array
    {
        $classLikes = [];


        foreach ($analysisUnit->statements as $statement) {

            if ($statement instanceof Stmt\Namespace_) {

                foreach ($statement->stmts as $namespaceStatement) {

                    if ($namespaceStatement instanceof Stmt\ClassLike) {
                        $classLikes[] = $namespaceStatement;
                    }
                }
                continue;
            }


            if ($statement instanceof Stmt\ClassLike) {
                $classLikes[] = $statement;
            }
        }

        return $classLikes;
    }

    /**
     * Builds candidate metadata when the subject call has matching same-file declaration evidence, or null.
     *
     * @param Expr\FuncCall $subjectCall - Function call wrapped by assertTrue().
     * @param array<string, array{kind: string, name: string, methods: array<string, string>, properties: array<string, string>}> $declarations
     *     - Same-unit declaration index.
     *
     * @return array{variant: string, assertion: string, staticFact: string, evidenceSymbol: string, candidateConfidence: string}|null
     *     - Candidate evidence, or null for unsupported calls or missing declaration evidence.
     */
    private function candidateFromSubjectCall(Expr\FuncCall $subjectCall, array $declarations): ?array
    {
        $assertion = TestQualityNodeHelper::functionName($subjectCall);

        if ($assertion === null) {
            return null;
        }

        $symbolName = $this->classNameFromClassConst(TestQualityNodeHelper::firstArgValue($subjectCall));

        if ($symbolName === null) {
            return null;
        }

        $declaration = $declarations[strtolower($symbolName)] ?? null;
        // Require same-file evidence before considering an assertion redundant.
        if ($declaration === null) {
            return null;
        }

        $expectedKind = $this->expectedKindForExistenceAssertion($assertion);

        if ($expectedKind !== null) {

            if ($declaration['kind'] !== $expectedKind) {
                return null;
            }

            return [
                'variant'             => $assertion,
                'assertion'           => $assertion,
                'staticFact'          => sprintf('%s %s is declared in the same parsed file', $declaration['kind'], $declaration['name']),
                'evidenceSymbol'      => $declaration['name'],
                'candidateConfidence' => Confidence::High->value,
            ];
        }


        if ($assertion === 'method_exists') {
            return $this->memberCandidate($subjectCall, $declaration, 'methods', 'method');
        }


        if ($assertion === 'property_exists') {
            return $this->memberCandidate($subjectCall, $declaration, 'properties', 'property');
        }

        return null;
    }

    /**
     * Builds the candidate metadata for a method_exists()/property_exists() assertion, or null.
     *
     * @param Expr\FuncCall $subjectCall - Existence check wrapped by assertTrue().
     * @param array{kind: string, name: string, methods: array<string, string>, properties: array<string, string>} $declaration - Same-file record.
     * @param 'methods'|'properties' $memberBucket - Declaration member bucket to inspect.
     * @param 'method'|'property' $memberKind - Human-readable member kind.
     *
     * @return array{variant: string, assertion: string, staticFact: string, evidenceSymbol: string, candidateConfidence: string}|null
     *     - Candidate evidence, or null without a literal string member name matching the indexed declaration.
     */
    private function memberCandidate(Expr\FuncCall $subjectCall, array $declaration, string $memberBucket, string $memberKind): ?array
    {
        $member = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::argValue($subjectCall, 1));

        if (!is_string($member)) {
            return null;
        }

        // Methods resolve case-insensitively in PHP; properties do not. Look each up the way the
        // language resolves it so a wrong-case property_exists() is not mistaken for a proven member.
        $memberKey    = $memberKind === 'property' ? $member : strtolower($member);
        $declaredName = $declaration[$memberBucket][$memberKey] ?? null;

        if (!is_string($declaredName)) {
            return null;
        }

        $evidenceSymbol = $memberKind === 'property'
            ? sprintf('%s::$%s', $declaration['name'], $declaredName)
            : sprintf('%s::%s()', $declaration['name'], $declaredName);

        return [
            'variant'             => TestQualityNodeHelper::functionName($subjectCall) ?? $memberKind . '_exists',
            'assertion'           => TestQualityNodeHelper::functionName($subjectCall) ?? $memberKind . '_exists',
            'staticFact'          => sprintf('%s %s is declared in the same parsed file', $memberKind, $evidenceSymbol),
            'evidenceSymbol'      => $evidenceSymbol,
            'candidateConfidence' => Confidence::High->value,
        ];
    }

    /**
     * Maps supported class-like existence function names to declaration kinds.
     *
     * @param string $assertion - Lowercase existence function name.
     *
     * @return string|null - Expected kind, or null for member checks and unsupported function names.
     */
    private function expectedKindForExistenceAssertion(string $assertion): ?string
    {
        return match ($assertion) {
            'class_exists' => 'class',
            'interface_exists' => 'interface',
            'trait_exists' => 'trait',
            'enum_exists' => 'enum',
            default => null,
        };
    }

    /**
     * Extracts the name from a named ::class fetch, using the parser's resolved name when available.
     *
     * @param Expr|null $expr - Candidate first argument to an existence function.
     *
     * @return string|null - Resolved or written name; null for dynamic, special or non-::class expressions.
     */
    private function classNameFromClassConst(?Expr $expr): ?string
    {

        if (!$expr instanceof Expr\ClassConstFetch || !$expr->class instanceof Name) {
            return null;
        }

        // self/static/parent are not concrete class names to index.
        if ($expr->class->isSpecialClassName()) {
            return null;
        }

        $name = $expr->name;

        if (!$name instanceof Node\Identifier || strtolower($name->toString()) !== 'class') {
            return null;
        }

        $resolved = $expr->class->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $expr->class->toString();
    }

    /**
     * Returns a declaration's namespaced name when available, otherwise its written name.
     *
     * @param Stmt\ClassLike $classLike - Declaration whose resolved or written name is returned.
     *
     * @return string|null - Namespaced or written declaration name; null for anonymous classes.
     */
    private function classLikeName(Stmt\ClassLike $classLike): ?string
    {

        if ($classLike->name === null) {
            return null;
        }

        $resolved = $classLike->namespacedName ?? null;

        return $resolved instanceof Name ? $resolved->toString() : $classLike->name->toString();
    }

    /**
     * Builds the lookup keys for a class-like declaration's resolved and short names.
     *
     * @param Stmt\ClassLike $classLike - Declaration whose short name supplies a fallback lookup key.
     * @param string         $resolvedName - Resolved name used as the primary lookup key.
     *
     * @return list<string> - Lowercase lookup keys.
     */
    private function classLikeKeys(Stmt\ClassLike $classLike, string $resolvedName): array
    {
        $keys = [strtolower($resolvedName)];

        // Also key by the short name so this heuristic can compare unqualified references.
        if ($classLike->name !== null) {
            $keys[] = strtolower($classLike->name->toString());
        }

        return array_values(array_unique($keys));
    }

    /**
     * Returns which PHP declaration kind a class-like node represents.
     *
     * @param Stmt\ClassLike $classLike - Declaration whose concrete kind is classified.
     *
     * @return 'class'|'interface'|'trait'|'enum' - Lowercase label identifying the supplied declaration's syntax kind.
     */
    private function classLikeKind(Stmt\ClassLike $classLike): string
    {
        return match (true) {
            $classLike instanceof Stmt\Interface_ => 'interface',
            $classLike instanceof Stmt\Trait_ => 'trait',
            $classLike instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };
    }
}
