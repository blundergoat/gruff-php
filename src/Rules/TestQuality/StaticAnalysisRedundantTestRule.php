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
 * Detects tests that only assert source declarations already visible statically.
 */
final readonly class StaticAnalysisRedundantTestRule implements RuleInterface
{
    /**
     * Stable rule identifier for static-analysis-redundant test candidates.
     */
    public const ID = 'test-quality.static-analysis-redundant-test';

    /**
     * Describe the static-analysis-redundant test rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
     * Find assertion calls that restate declarations already present in the parsed unit.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for static-analysis-redundant test candidates.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $declarations = $this->collectDeclarations($analysisUnit);
        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($declarations === []) {
            return [];
        }

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // User view: add each item that can appear in findings list.
            foreach (TestQualityNodeHelper::assertionCalls($scope) as $assertionCall) {
                // User view: choose the findings list branch for this case.
                if (TestQualityNodeHelper::callName($assertionCall) !== 'asserttrue') {
                    continue;
                }

                $subjectCall = TestQualityNodeHelper::firstArgValue($assertionCall);
                // User view: choose the findings list branch for this case.
                if (!$subjectCall instanceof Expr\FuncCall) {
                    continue;
                }

                $candidate = $this->candidateFromSubjectCall($subjectCall, $declarations);
                // User view: choose the findings list branch for this case.
                // User view: missing data becomes the expected findings list state.
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
     * Build a same-unit declaration index keyed by resolved and short class-like names.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose declarations should be indexed.
     *
     * @return array<string, array{kind: string, name: string, methods: array<string, string>, properties: array<string, string>}> - Declaration index.
     */
    private function collectDeclarations(AnalysisUnit $analysisUnit): array
    {
        $declarations = [];

        // User view: add each item that can appear in findings list.
        foreach ($this->topLevelClassLikes($analysisUnit) as $node) {
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($node->name === null) {
                continue;
            }

            $name = $this->classLikeName($node);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($name === null) {
                continue;
            }

            $record = [
                'kind'       => $this->classLikeKind($node),
                'name'       => $name,
                'methods'    => [],
                'properties' => [],
            ];

            // User view: add each item that can appear in findings list.
            foreach ($node->stmts as $statement) {
                // User view: choose the findings list branch for this case.
                if ($statement instanceof Stmt\ClassMethod) {
                    // PHP resolves method names case-insensitively, so index by the lowercase name.
                    $methodName = $statement->name->toString();
                    $record['methods'][strtolower($methodName)] = $methodName;
                    continue;
                }

                // User view: choose the findings list branch for this case.
                if ($statement instanceof Stmt\Property) {
                    // User view: add each item that can appear in findings list.
                    foreach ($statement->props as $property) {
                        // PHP property names are case-sensitive, so index by the declared name as-is.
                        $propertyName = $property->name->toString();
                        $record['properties'][$propertyName] = $propertyName;
                    }
                }
            }

            // User view: add each item that can appear in findings list.
            foreach ($this->classLikeKeys($node, $name) as $key) {
                $declarations[$key] = $record;
            }
        }

        return $declarations;
    }

    /**
     * Collect class-like declarations PHP registers unconditionally: those at the file top level or
     * directly inside a namespace block. Declarations nested in functions, methods, or conditional
     * blocks are only registered once that code path runs, so a static existence assertion against
     * them is not provably redundant and must be excluded from the index.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose top-level declarations should be collected.
     *
     * @return list<Stmt\ClassLike> - Class-like declarations at file or namespace scope, in source order.
     */
    private function topLevelClassLikes(AnalysisUnit $analysisUnit): array
    {
        $classLikes = [];

        // User view: add each item that can appear in findings list.
        foreach ($analysisUnit->statements as $statement) {
            // User view: choose the findings list branch for this case.
            if ($statement instanceof Stmt\Namespace_) {
                // User view: add each item that can appear in findings list.
                foreach ($statement->stmts as $namespaceStatement) {
                    // User view: choose the findings list branch for this case.
                    if ($namespaceStatement instanceof Stmt\ClassLike) {
                        $classLikes[] = $namespaceStatement;
                    }
                }
                continue;
            }

            // User view: choose the findings list branch for this case.
            if ($statement instanceof Stmt\ClassLike) {
                $classLikes[] = $statement;
            }
        }

        return $classLikes;
    }

    /**
     * Build a candidate metadata payload when a source declaration proves the subject call.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall                                                                                  $subjectCall - Function call wrapped by assertTrue().
     * @param array<string, array{kind: string, name: string, methods: array<string, string>, properties: array<string, string>}> $declarations - Same-unit declaration index.
     *
     * @return array{variant: string, assertion: string, staticFact: string, evidenceSymbol: string, candidateConfidence: string}|null - Candidate evidence for a redundant static-fact assertion, or null when the assertion stays unowned.
     */
    private function candidateFromSubjectCall(Expr\FuncCall $subjectCall, array $declarations): ?array
    {
        $assertion = TestQualityNodeHelper::functionName($subjectCall);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($assertion === null) {
            return null;
        }

        $symbolName = $this->classNameFromClassConst(TestQualityNodeHelper::firstArgValue($subjectCall));
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($symbolName === null) {
            return null;
        }

        // User view: missing data becomes a safe findings list default.
        $declaration = $declarations[strtolower($symbolName)] ?? null;
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($declaration === null) {
            return null;
        }

        $expectedKind = $this->expectedKindForExistenceAssertion($assertion);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($expectedKind !== null) {
            // User view: choose the findings list branch for this case.
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

        // User view: choose the findings list branch for this case.
        if ($assertion === 'method_exists') {
            return $this->memberCandidate($subjectCall, $declaration, 'methods', 'method');
        }

        // User view: choose the findings list branch for this case.
        if ($assertion === 'property_exists') {
            return $this->memberCandidate($subjectCall, $declaration, 'properties', 'property');
        }

        return null;
    }

    /**
     * Build candidate metadata for method_exists() or property_exists() assertions.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall                                                       $subjectCall - Existence check wrapped by assertTrue().
     * @param array{kind: string, name: string, methods: array<string, string>, properties: array<string, string>} $declaration - Same-unit declaration row.
     * @param 'methods'|'properties'                                             $memberBucket - Declaration member bucket to inspect.
     * @param 'method'|'property'                                                $memberKind - Human-readable member kind.
     *
     * @return array{variant: string, assertion: string, staticFact: string, evidenceSymbol: string, candidateConfidence: string}|null - Candidate evidence for a declared member existence assertion, or null when the member is not statically proven.
     */
    private function memberCandidate(Expr\FuncCall $subjectCall, array $declaration, string $memberBucket, string $memberKind): ?array
    {
        $member = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::argValue($subjectCall, 1));
        // User view: choose the findings list branch for this case.
        if (!is_string($member)) {
            return null;
        }

        // Methods resolve case-insensitively in PHP; properties do not. Look each up the way the
        // language resolves it so a wrong-case property_exists() is not mistaken for a proven member.
        $memberKey    = $memberKind === 'property' ? $member : strtolower($member);
        // User view: missing data becomes a safe findings list default.
        $declaredName = $declaration[$memberBucket][$memberKey] ?? null;
        // User view: choose the findings list branch for this case.
        if (!is_string($declaredName)) {
            return null;
        }

        $evidenceSymbol = $memberKind === 'property'
            ? sprintf('%s::$%s', $declaration['name'], $declaredName)
            : sprintf('%s::%s()', $declaration['name'], $declaredName);

        return [
            // User view: missing data becomes a safe findings list default.
            'variant'             => TestQualityNodeHelper::functionName($subjectCall) ?? $memberKind . '_exists',
            // User view: missing data becomes a safe findings list default.
            'assertion'           => TestQualityNodeHelper::functionName($subjectCall) ?? $memberKind . '_exists',
            'staticFact'          => sprintf('%s %s is declared in the same parsed file', $memberKind, $evidenceSymbol),
            'evidenceSymbol'      => $evidenceSymbol,
            'candidateConfidence' => Confidence::High->value,
        ];
    }

    /**
     * Map source-level existence functions to the declaration kind they prove redundantly.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $assertion - Lowercase existence function name.
     *
     * @return string|null - Expected class-like kind, or null when the function checks a member.
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
     * Resolve a ClassName::class expression to the parser-resolved class name.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr|null $expr - Candidate first argument to an existence function.
     *
     * @return string|null - Resolved class name, or null when the expression is dynamic or not ::class.
     */
    private function classNameFromClassConst(?Expr $expr): ?string
    {
        // User view: choose the findings list branch for this case.
        if (!$expr instanceof Expr\ClassConstFetch || !$expr->class instanceof Name) {
            return null;
        }

        // User view: choose the findings list branch for this case.
        if ($expr->class->isSpecialClassName()) {
            return null;
        }

        $name = $expr->name;
        // User view: choose the findings list branch for this case.
        if (!$name instanceof Node\Identifier || strtolower($name->toString()) !== 'class') {
            return null;
        }

        $resolved = $expr->class->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $expr->class->toString();
    }

    /**
     * Return the resolved display name for a class-like declaration.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\ClassLike $classLike - Class-like declaration.
     *
     * @return string|null - Resolved declaration name, or null for anonymous classes.
     */
    private function classLikeName(Stmt\ClassLike $classLike): ?string
    {
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($classLike->name === null) {
            return null;
        }

        // User view: missing data becomes a safe findings list default.
        $resolved = $classLike->namespacedName ?? null;

        return $resolved instanceof Name ? $resolved->toString() : $classLike->name->toString();
    }

    /**
     * Build lookup keys for resolved and short class-like names.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\ClassLike $classLike - Class-like declaration.
     * @param string         $resolvedName - Resolved class-like name.
     *
     * @return list<string> - Lowercase lookup keys.
     */
    private function classLikeKeys(Stmt\ClassLike $classLike, string $resolvedName): array
    {
        $keys = [strtolower($resolvedName)];

        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($classLike->name !== null) {
            $keys[] = strtolower($classLike->name->toString());
        }

        return array_values(array_unique($keys));
    }

    /**
     * Describe which PHP declaration kind a class-like node represents.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\ClassLike $classLike - Class-like declaration.
     *
     * @return 'class'|'interface'|'trait'|'enum' - Declaration kind.
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
