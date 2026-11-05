<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use GruffPhp\Support\DeclarationLine;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Flags three or more test methods in a class whose bodies share the same call and control-flow shape but
 * carry no data provider - a sign the same scenario is copy-pasted with different literals and would read
 * better as one parametrised test. The default groupFindings mode compares full syntax under family
 * contract section 16. Both modes run per class and honour path exemptions. Advisory, low confidence.
 */
final readonly class RepeatedStructureMissingDataProviderRule implements RuleInterface
{
    /**
     * Stable identifier for the repeated-structure rule.
     */
    public const ID = 'test-quality.repeated-structure-missing-data-provider';

    /**
     * Minimum identical-shape group size before recommending a data provider.
     */
    private const MIN_GROUP_SIZE = 3;

    /**
     * Describes the repeated-structure-missing-data-provider rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Repeated test structure missing data provider',
            pillar:             Pillar::TestQuality,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::Low,
            defaultOptions:     ['ignoredPathPatterns' => [], 'groupFindings' => true],
            optionDescriptions: [
                'groupFindings' => 'When true, compare complete test bodies within one class, with matching attributes and annotation tags, '
                    . 'at least two top-level statements and three members. Keep ASCII-whitespace strings and literal keys or indices exact; '
                    . 'keep declarations, their PHPDoc and all body attribute arguments exact; normalize other literal values by kind. '
                    . 'Report every member and its location. Enabled by default; set groupFindings to false for legacy shape-group findings.',
            ],
            falsePositiveShapes: [
                [
                    'shape' => 'Three or more tests that share a call shape while testing genuinely different behaviours, where one parametrised test would hide what each case proves.',
                    'mitigation' => 'Only the call and control-flow shape is compared, never the intent, so add the path to options.ignoredPathPatterns or keep the separate tests.',
                ],
            ],
        );
    }

    /**
     * Reports repeated test bodies that look like data-provider candidates.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for repeated test structures.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        if ($this->isPathIgnored($analysisUnit->file->displayPath, $settings->stringListOption('ignoredPathPatterns'))) {
            // Paths the project has opted out report nothing; their repetition is accepted by configuration.
            return [];
        }

        if (($settings->options['groupFindings'] ?? false) === true) {
            return $this->groupFindings($analysisUnit);
        }

        $nodeFinder = new NodeFinder();
        $findings   = [];

        // Weigh every class declaration in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            $className = $class->name?->toString();
            // An anonymous class has no name to report against.
            if ($className === null) {
                continue;
            }

            $groups = [];

            // Inspect each test method's body shape.
            foreach ($class->getMethods() as $classMethod) {
                // Only real test methods are grouped by shape.
                if (!TestQualityNodeHelper::isTestMethod($classMethod)) {
                    continue;
                }

                // A method already driven by a data provider is exempt.
                if ($this->usesDataProvider($classMethod)) {
                    continue;
                }

                $stmts = array_values($classMethod->stmts ?? []);
                // A one-statement body is too trivial to call repetitive.
                if (count($stmts) < 2) {
                    continue;
                }

                $shape = $this->fingerprint($stmts, $nodeFinder);
                $groups[$shape] ??= [];
                $groups[$shape][] = $classMethod;
            }

            // Weigh each group of identically shaped tests.
            foreach ($groups as $methods) {
                // Only a large enough group is worth collapsing into a provider.
                if (count($methods) < self::MIN_GROUP_SIZE) {
                    continue;
                }

                $names = array_map(static fn (Stmt\ClassMethod $classMethod): string => $classMethod->name->toString(), $methods);
                $first = $methods[0];

                $findings[] = new Finding(
                    ruleId:  self::ID,
                    message: sprintf(
                        '%s has %d structurally identical test methods (%s) that look like a data provider would replace.',
                        $className,
                        count($methods),
                        implode(', ', $names),
                    ),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        DeclarationLine::of($first),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::Low,
                    symbol:      sprintf('%s::%s()', $className, $first->name->toString()),
                    remediation: 'Collapse the repeated tests into one method driven by #[DataProvider] or @dataProvider with the differing values as data rows. If a path consistently produces structurally similar tests by design, add it to `rules.test-quality.repeated-structure-missing-data-provider.options.ignoredPathPatterns` in `.gruff-php.yaml`.',
                    metadata:    ['count' => count($methods), 'methods' => $names],
                );
            }
        }

        return $findings;
    }

    /**
     * Reports complete groups under section 16, keeping each class a separate test container.
     *
     * @param AnalysisUnit $unit - Parsed file whose test methods are compared.
     * @return list<Finding> - One finding per qualifying group, ordered by its first method.
     */
    private function groupFindings(AnalysisUnit $unit): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($unit, Stmt\Class_::class) as $class) {
            if ($class->name === null) {
                continue;
            }

            /** @var array<string, list<Stmt\ClassMethod>> $groups - Retains every matching method until the complete group is reported. */
            $groups = [];
            foreach ($class->getMethods() as $method) {
                $statements = array_values(array_filter($method->stmts ?? [], static fn (Stmt $statement): bool => !$statement instanceof Stmt\Nop));
                if (!TestQualityNodeHelper::isTestMethod($method) || count($statements) < 2 || $this->isGroupTableDriven($method, $statements)) {
                    continue;
                }

                // Enclosing attributes are common to this class; only each method's own entries can split its groups.
                $key            = hash('sha256', serialize([$this->groupShape($method->attrGroups, true), $this->annotationTags($method), $this->groupShape($statements)]));
                $groups[$key][] = $method;
            }

            foreach ($groups as $methods) {
                if (count($methods) >= self::MIN_GROUP_SIZE) {
                    $findings[] = $this->groupFinding($unit, $class->name->toString(), $methods);
                }
            }
        }

        usort($findings, static fn (Finding $left, Finding $right): int => $left->line <=> $right->line);

        return $findings;
    }

    /**
     * Lists every method and declaration line in both the advice context and native metadata.
     *
     * @param AnalysisUnit           $unit      - File containing the group.
     * @param string                 $className - Native class name used in finding symbols.
     * @param list<Stmt\ClassMethod> $methods   - Matching methods in source order.
     * @return Finding - Group finding anchored at its first member.
     */
    private function groupFinding(AnalysisUnit $unit, string $className, array $methods): Finding
    {
        $members = [];
        $listed  = [];
        foreach ($methods as $method) {
            $symbol           = $className . '::' . $method->name->toString() . '()';
            $line             = DeclarationLine::of($method);
            $members[$symbol] = $line;
            $listed[]         = sprintf('%s (line %d)', $symbol, $line);
        }

        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('%d tests share one body shape: %s - fold them into one PHPUnit data provider with a descriptive label per case.', count($methods), implode(', ', $listed)),
            filePath:    $unit->file->displayPath,
            line:        DeclarationLine::of($methods[0]),
            severity:    Severity::Advisory,
            pillar:      Pillar::TestQuality,
            tier:        RuleTier::V01,
            confidence:  Confidence::Low,
            endLine:     $methods[0]->getEndLine(),
            symbol:      array_key_first($members),
            remediation: 'Fold the repeated tests into one PHPUnit data provider, giving each data row a descriptive label so a failure still names the scenario it broke.',
            metadata:    ['groupSize' => count($methods), 'members' => $members],
        );
    }

    /**
     * Recognises methods already driven by PHPUnit case data or a literal table loop.
     *
     * @param Stmt\ClassMethod $method     - Test whose attributes and annotations declare case data.
     * @param list<Stmt>       $statements - Its non-empty top-level statements.
     * @return bool - Whether consolidation into another table would repeat an existing table.
     */
    private function isGroupTableDriven(Stmt\ClassMethod $method, array $statements): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (in_array(strtolower($attribute->name->getLast()), ['dataprovider', 'dataproviderexternal', 'testwith', 'testwithjson'], true)) {
                    return true;
                }
            }
        }

        // A dataProvider or testWith annotation already supplies case data.
        if (preg_match('/^@(?:dataProvider|testWith)\b/im', implode("\n", $this->annotationTags($method))) === 1) {
            return true;
        }

        $tableVariables = [];
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Assign) {
                $assignment = $statement->expr;
                if ($assignment->var instanceof Expr\Variable && is_string($assignment->var->name)) {
                    $tableVariables[$assignment->var->name] = $assignment->expr instanceof Expr\Array_;
                }
            }
            if ($this->isLiteralTableLoop($statement, $tableVariables)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recognises a top-level table loop over an inline array or a preceding local array assignment.
     *
     * @param Stmt                $statement      - Statement that may iterate case rows.
     * @param array<string, bool> $tableVariables - Whether each latest local assignment was an array literal.
     * @return bool - True when the iterable has a known literal table shape.
     */
    private function isLiteralTableLoop(Stmt $statement, array $tableVariables): bool
    {
        if (!$statement instanceof Stmt\Foreach_) {
            return false;
        }

        $iterable = $statement->expr;

        return $iterable instanceof Expr\Array_
            || ($iterable instanceof Expr\Variable && is_string($iterable->name) && ($tableVariables[$iterable->name] ?? false));
    }

    /**
     * Keeps annotation entries in source order while leaving prose out of the attribute comparison.
     *
     * @param Stmt\ClassMethod $method - Method whose PHPDoc may carry runner annotations.
     * @return list<string> - Trimmed annotation entries, including wrapped argument text.
     */
    private function annotationTags(Stmt\ClassMethod $method): array
    {
        $text = $method->getDocComment()?->getText() ?? '';
        $text = preg_replace('/^\/\*\*|\*\/$/', '', $text) ?? $text;
        $tags = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/^\s*\*\s?/', '', $line) ?? $line);
            if (str_starts_with($line, '@')) {
                $tags[] = $line;
            } elseif ($line !== '' && $tags !== []) {
                $tags[array_key_last($tags)] .= ' ' . $line;
            }
        }

        return $tags;
    }

    /**
     * Serializes syntax while retaining declarations, payload strings and selector literals.
     *
     * getSubNodeNames excludes comments, source positions and parent links, so this visits only the body.
     *
     * @param mixed $node              - Syntax node, node list or scalar field value.
     * @param bool  $keepLiterals      - Retain every literal inside declarations, attributes, keys or indices.
     * @param bool  $keepDocumentation - Retain PHPDoc throughout a declaration subtree.
     * @return string - Structural comparison key; local names, calls and statement order stay significant.
     */
    private function groupShape(mixed $node, bool $keepLiterals = false, bool $keepDocumentation = false): string
    {
        if ($node instanceof Node) {
            // A declaration cannot become case data; a closure remains an expression, but its attributes stay exact.
            $keepDocumentation = $keepDocumentation || $node instanceof Stmt\Function_ || $node instanceof Stmt\ClassLike;
            $keepLiterals      = $keepLiterals || $keepDocumentation || $node instanceof Node\Attribute;

            return $this->literalShape($node, $keepLiterals) ?? $this->nodeShape($node, $keepLiterals, $keepDocumentation);
        }

        if (is_array($node)) {
            $parts = [];
            foreach ($node as $item) {
                if (!$item instanceof Stmt\Nop) {
                    $parts[] = $this->groupShape($item, $keepLiterals, $keepDocumentation);
                }
            }

            return '[' . implode(',', $parts) . ']';
        }

        return serialize($node);
    }

    /**
     * Normalizes eligible literal values; other node kinds need structural traversal.
     *
     * @param Node $node         - Possible literal node.
     * @param bool $keepLiterals - Retain the literal value in an attribute or selector.
     * @return string|null - Literal shape, or null when this is not a supported literal.
     */
    private function literalShape(Node $node, bool $keepLiterals): ?string
    {
        if ($node instanceof Scalar\String_ || $node instanceof Node\InterpolatedStringPart) {
            return $keepLiterals || strpbrk($node->value, " \t\n\r\f\v") !== false ? 'string:' . serialize($node->value) : '<string>';
        }

        if ($node instanceof Scalar\Int_ || $node instanceof Scalar\Float_) {
            return $keepLiterals ? 'number:' . serialize($node->value) : '<number>';
        }

        if ($node instanceof Expr\ConstFetch && in_array(strtolower($node->name->toString()), ['true', 'false', 'null'], true)) {
            $literal = strtolower($node->name->toString());

            return $keepLiterals ? 'literal:' . $literal : ($literal === 'null' ? '<null>' : '<boolean>');
        }

        return null;
    }

    /**
     * Compares syntax fields and declaration PHPDoc, preserving literals below declarations, keys or indices.
     *
     * @param Node $node              - Non-literal syntax node.
     * @param bool $keepLiterals      - Whether an enclosing field retains literal values.
     * @param bool $keepDocumentation - Whether an enclosing declaration retains PHPDoc.
     * @return string - Node type and ordered field shapes.
     */
    private function nodeShape(Node $node, bool $keepLiterals, bool $keepDocumentation): string
    {
        $fields = $keepDocumentation ? ['documentation=' . serialize($node->getDocComment()?->getText())] : [];
        foreach ($node->getSubNodeNames() as $name) {
            $selector = $node instanceof Node\ArrayItem && $name === 'key' || $node instanceof Expr\ArrayDimFetch && $name === 'dim';
            $fields[] = $name . '=' . $this->groupShape($node->{$name}, $keepLiterals || $selector, $keepDocumentation);
        }

        return $node->getType() . '(' . implode(',', $fields) . ')';
    }

    /**
     * Reports whether a project-configured path exemption applies.
     *
     * @param string       $displayPath - File path under analysis; matched after backslashes are normalised to slashes.
     * @param list<string> $patterns    - Glob patterns for accepted test shapes.
     *
     * @return bool - True when the display path matches an ignored pattern.
     */
    private function isPathIgnored(string $displayPath, array $patterns): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);

        // Weigh the display path against each configured exemption glob.
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $normalizedPath, FNM_NOESCAPE)) {
                // First matching glob is enough to exempt the path; no need to test the rest.
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a test method already declares a data provider.
     *
     * @param Stmt\ClassMethod $classMethod - Test method whose attributes and docblock are scanned for a provider.
     *
     * @return bool - True when an attribute or docblock data provider is present.
     */
    private function usesDataProvider(Stmt\ClassMethod $classMethod): bool
    {
        // Read the method's attributes for a DataProvider.
        foreach ($classMethod->attrGroups as $group) {
            // One group can hold several attributes.
            foreach ($group->attrs as $attr) {
                if (strtolower($attr->name->getLast()) === 'dataprovider') {
                    // A #[DataProvider] attribute already drives this method, so it is exempt from the heuristic.
                    return true;
                }
            }
        }

        return str_contains($classMethod->getDocComment()?->getText() ?? '', '@dataProvider');
    }

    /**
     * Reduces a test body to a structure fingerprint of its call and control-flow shape.
     *
     * @param list<Node> $stmts      - Statements of one test method body; the unit being fingerprinted.
     * @param NodeFinder $nodeFinder - Finder shared across the class scan so one instance serves every method.
     *
     * @return string - Structure fingerprint for comparison across tests.
     */
    private function fingerprint(array $stmts, NodeFinder $nodeFinder): string
    {
        $tokens = [];

        // Walk the body's calls and control-flow nodes in order.
        foreach ($nodeFinder->find($stmts, static fn (Node $node): bool => $node instanceof Expr\New_
            || $node instanceof Expr\FuncCall
            || $node instanceof Expr\MethodCall
            || $node instanceof Expr\StaticCall
            || $node instanceof Stmt\If_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\For_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Return_
            || $node instanceof Expr\Throw_) as $node) {
            $tokens[] = $this->tokenFor($node);
        }

        return implode('|', $tokens);
    }

    /**
     * Returns a stable fingerprint token for a structural AST node.
     *
     * @param Node $node - One structural node from a test body; call targets fold into the token, plain shapes do not.
     *
     * @return string - Fingerprint token.
     */
    private function tokenFor(Node $node): string
    {
        // A construction folds in its class name.
        if ($node instanceof Expr\New_) {
            $class = $node->class instanceof Name ? $node->class->toString() : 'expr';

            // Fold the constructed class in so `new A` and `new B` fingerprint as distinct shapes.
            return 'new:' . $class;
        }

        if ($node instanceof Expr\FuncCall) {
            // Key on the function name so differing calls separate; dynamic targets collapse to a stable placeholder.
            return 'func:' . (TestQualityNodeHelper::functionName($node) ?? 'expr');
        }

        if ($node instanceof Expr\MethodCall) {
            // Key on the method name; an unresolved dynamic call collapses to the same placeholder for stability.
            return 'method:' . (TestQualityNodeHelper::callName($node) ?? 'expr');
        }

        // A static call folds in both class and method.
        if ($node instanceof Expr\StaticCall) {
            $class = $node->class instanceof Name ? $node->class->toString() : 'expr';

            // Fold both class and method so distinct static calls stay distinguishable in the fingerprint.
            return 'static:' . $class . '::' . (TestQualityNodeHelper::callName($node) ?? 'expr');
        }

        // Control-flow nodes (if/foreach/return/throw) carry no comparable name, so their class alone is the token.
        return $node::class;
    }
}
