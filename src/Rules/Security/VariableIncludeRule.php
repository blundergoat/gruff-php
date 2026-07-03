<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Detects include and require paths built from variables.
 */
final class VariableIncludeRule implements RuleInterface
{
    /**
     * Stable rule identifier for variable include findings.
     */
    public const ID = 'security.variable-include';

    /**
     * Pattern an ALL-CAPS global constant name must match to count as a fixed deployment path.
     */
    private const FIXED_CONSTANT_NAME_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    /**
     * Global path-inspection functions that cannot mutate an include-path local passed by value.
     *
     * @var array<string, true>
     */
    private const NON_MUTATING_PATH_FUNCTIONS = [
        'file_exists' => true,
        'is_dir'      => true,
        'is_file'     => true,
        'is_readable' => true,
        'is_writable' => true,
        'realpath'    => true,
    ];

    /**
     * Describe the variable include security rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence: a dynamic path is suspicious but often safe (allow-listed upstream), so warn not error.
        return new RuleDefinition(
            id:                  self::ID,
            name:                'Variable include or require path',
            pillar:              Pillar::Security,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Warning,
            confidence:          Confidence::Medium,
            defaultOptions:      [
                                     'treatGlobalConstantsAsFixed' => true,
                                     'dynamicPathConstants'        => [],
                                 ],
            optionDescriptions:  [
                                     'treatGlobalConstantsAsFixed' => 'Treat ALL-CAPS global constants (ABSPATH, WC_ABSPATH, ...) as fixed path segments; class constants and non-ALL-CAPS names always stay dynamic.',
                                     'dynamicPathConstants'        => 'Constant names to keep treating as dynamic even when treatGlobalConstantsAsFixed is on, for projects whose path constants carry runtime data.',
                                 ],
            falsePositiveShapes: [
                                     [
                                         'shape'      => 'Bootstrap include concatenating an ALL-CAPS deployment constant with a literal (require_once ABSPATH . \'wp-admin/x.php\').',
                                         'mitigation' => 'Recognised as fixed by default; list the constant in options.dynamicPathConstants to re-flag it.',
                                     ],
                                     [
                                         'shape'      => 'Include through a local whose only same-scope assignments are fixed paths ($dir = __DIR__ . \'/inc/\'; require $dir . \'z.php\').',
                                         'mitigation' => 'Recognised as fixed when every same-scope assignment is provably fixed; any tainted or unprovable assignment keeps the include flagged.',
                                     ],
                                 ],
        );
    }

    /**
     * Find include and require expressions using dynamic paths.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for variable include paths; empty when every include uses a fixed path.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $settings                    = $ruleContext->settingsFor($this->definition());
        $shouldTreatConstantsAsFixed = $settings->option('treatGlobalConstantsAsFixed') === true;
        $dynamicConstantNames        = $settings->stringListOption('dynamicPathConstants');
        $findings                    = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Include_::class) as $include) {
            $isFixedPath = $this->isFixedIncludeExpression(
                expression:                  $include->expr,
                analysisUnit:                $analysisUnit,
                shouldTreatConstantsAsFixed: $shouldTreatConstantsAsFixed,
                dynamicConstantNames:        $dynamicConstantNames,
                canFollowAssignments:        true,
            );
            // User view: choose the findings list branch for this case.
            if ($isFixedPath) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     'Variable include/require path detected.',
                filePath:    $analysisUnit->file->displayPath,
                line:        $include->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Use fixed include paths or map request values through an allow-list before loading files. Paths built only from literals, __DIR__/__FILE__, ALL-CAPS path constants, or locals whose every assignment is such a fixed path are not flagged.',
            );
        }

        return $findings;
    }

    /**
     * Treat literal paths and paths derived only from compile-time constants as fixed bootstrap includes.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr         $expression - Include/require path expression, recursed into for concatenation and dirname() wrappers.
     * @param AnalysisUnit $analysisUnit - Unit owning the include, used for same-scope assignment propagation.
     * @param bool         $shouldTreatConstantsAsFixed - Whether ALL-CAPS global constants count as fixed segments.
     * @param list<string> $dynamicConstantNames - Constant names configured to stay dynamic.
     * @param bool         $canFollowAssignments - Whether variable leaves may be resolved through same-scope assignments.
     *
     * @return bool - True when the include path cannot vary from request or runtime data.
     */
    private function isFixedIncludeExpression(
        Expr         $expression,
        AnalysisUnit $analysisUnit,
        bool         $shouldTreatConstantsAsFixed,
        array        $dynamicConstantNames,
        bool         $canFollowAssignments,
    ): bool {
        // User view: choose the findings list branch for this case.
        if (SecurityNodeHelper::isStringLiteral($expression) || $expression instanceof Scalar\MagicConst\Dir || $expression instanceof Scalar\MagicConst\File) {
            // String literals and __DIR__/__FILE__ resolve at compile time, so the path is fixed and attacker-proof.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if ($expression instanceof Expr\ConstFetch) {
            // ALL-CAPS deployment constants (ABSPATH, JETPACK_DIR, ...) are defined once at bootstrap; anything else stays dynamic.
            return $shouldTreatConstantsAsFixed && $this->isFixedGlobalConstant($expression, $dynamicConstantNames);
        }

        // User view: choose the findings list branch for this case.
        if ($expression instanceof Expr\BinaryOp\Concat) {
            // A concatenation is only as fixed as its parts, so both sides must independently resolve to fixed paths.
            return $this->isFixedIncludeExpression(
                       expression:                  $expression->left,
                       analysisUnit:                $analysisUnit,
                       shouldTreatConstantsAsFixed: $shouldTreatConstantsAsFixed,
                       dynamicConstantNames:        $dynamicConstantNames,
                       canFollowAssignments:        $canFollowAssignments,
                   )
                && $this->isFixedIncludeExpression(
                       expression:                  $expression->right,
                       analysisUnit:                $analysisUnit,
                       shouldTreatConstantsAsFixed: $shouldTreatConstantsAsFixed,
                       dynamicConstantNames:        $dynamicConstantNames,
                       canFollowAssignments:        $canFollowAssignments,
                   );
        }

        // User view: choose the findings list branch for this case.
        if ($expression instanceof Expr\FuncCall && SecurityNodeHelper::globalFunctionName($expression) === 'dirname') {
            return $this->isFixedDirnameCall(
                call:                        $expression,
                analysisUnit:                $analysisUnit,
                shouldTreatConstantsAsFixed: $shouldTreatConstantsAsFixed,
                dynamicConstantNames:        $dynamicConstantNames,
                canFollowAssignments:        $canFollowAssignments,
            );
        }

        // User view: choose the findings list branch for this case.
        if ($canFollowAssignments && $expression instanceof Expr\Variable) {
            // A local is fixed only when every same-scope assignment to it is itself a provably fixed path.
            return $this->isVariableWithOnlyFixedAssignments($expression, $analysisUnit, $shouldTreatConstantsAsFixed, $dynamicConstantNames);
        }

        // Function results and other dynamic expressions can carry request data, so the path is not fixed.
        return false;
    }

    /**
     * Decide whether a dirname() wrapper preserves a fixed include path.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall $call - dirname() call to inspect.
     * @param AnalysisUnit  $analysisUnit - Unit owning the include, used for same-scope assignment propagation.
     * @param bool          $shouldTreatConstantsAsFixed - Whether ALL-CAPS global constants count as fixed segments.
     * @param list<string>  $dynamicConstantNames - Constant names configured to stay dynamic.
     * @param bool          $canFollowAssignments - Whether variable leaves may be resolved through same-scope assignments.
     *
     * @return bool - True when dirname() is applied to a fixed path with a literal (or absent) levels argument.
     */
    private function isFixedDirnameCall(
        Expr\FuncCall $call,
        AnalysisUnit  $analysisUnit,
        bool          $shouldTreatConstantsAsFixed,
        array         $dynamicConstantNames,
        bool          $canFollowAssignments,
    ): bool {
        $path = SecurityNodeHelper::argumentValue($call->args, 0);
        $isFixedInnerPath = $path instanceof Expr && $this->isFixedIncludeExpression(
            expression:                  $path,
            analysisUnit:                $analysisUnit,
            shouldTreatConstantsAsFixed: $shouldTreatConstantsAsFixed,
            dynamicConstantNames:        $dynamicConstantNames,
            canFollowAssignments:        $canFollowAssignments,
        );
        // User view: choose the findings list branch for this case.
        if (!$isFixedInnerPath) {
            // dirname() of a dynamic path is still dynamic, so the whole expression is not a fixed include.
            return false;
        }

        $levels = SecurityNodeHelper::argumentValue($call->args, 1);

        // dirname() stays fixed only when the optional levels arg is omitted or an int literal, never a variable.
        // User view: missing data becomes the expected findings list state.
        return $levels === null || $levels instanceof Scalar\Int_;
    }

    /**
     * Decide whether a bare constant fetch names a fixed deployment-path constant.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\ConstFetch $constant - Constant fetch inside the include path.
     * @param list<string>    $dynamicConstantNames - Constant names configured to stay dynamic.
     *
     * @return bool - True when the name matches the ALL-CAPS define() pattern and is not configured as dynamic.
     */
    private function isFixedGlobalConstant(Expr\ConstFetch $constant, array $dynamicConstantNames): bool
    {
        $parts = $constant->name->getParts();
        // User view: choose the findings list branch for this case.
        if (count($parts) !== 1) {
            // Namespaced constants are outside the bootstrap define() convention, so stay conservative and flag.
            return false;
        }

        $name = $parts[0];
        // User view: choose the findings list branch for this case.
        if (in_array($name, $dynamicConstantNames, true)) {
            // The project re-listed this constant as dynamic, so it never counts as fixed.
            return false;
        }

        // Only the ALL-CAPS define() pattern (ABSPATH, WC_ABSPATH, ...) is trusted; lowercase names stay flagged.
        return preg_match(self::FIXED_CONSTANT_NAME_PATTERN, $name) === 1;
    }

    /**
     * Decide whether every same-scope assignment to an include-path variable is a fixed expression.
     *
     * The walk inverts the taint propagation used by SecurityNodeHelper: instead of looking for one
     * tainted assignment, it requires every plain assignment in the variable's scope to be provably
     * fixed, with at least one occurring before the include. Any non-plain write (parameter binding,
     * compound assignment, by-reference use, call argument, foreach binding, global/static declaration,
     * or destructuring) disqualifies the variable because its value can no longer be proven fixed.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\Variable $variable - Variable used as (part of) the include path.
     * @param AnalysisUnit  $analysisUnit - Unit owning the include.
     * @param bool          $shouldTreatConstantsAsFixed - Whether ALL-CAPS global constants count as fixed segments.
     * @param list<string>  $dynamicConstantNames - Constant names configured to stay dynamic.
     *
     * @return bool - True when every same-scope assignment to the variable is fixed and one precedes the include.
     */
    private function isVariableWithOnlyFixedAssignments(
        Expr\Variable $variable,
        AnalysisUnit  $analysisUnit,
        bool          $shouldTreatConstantsAsFixed,
        array         $dynamicConstantNames,
    ): bool {
        $name = $variable->name;
        // User view: choose the findings list branch for this case.
        if (!is_string($name)) {
            // A variable-variable target cannot be traced, so it is never provably fixed.
            return false;
        }

        $sinkPosition = $variable->getStartFilePos();
        // User view: choose the findings list branch for this case.
        if ($sinkPosition < 0) {
            // A missing byte offset means assignments cannot be ordered against the include; stay safe and flag.
            return false;
        }

        $scope      = SecurityNodeHelper::enclosingFunctionLike($variable);
        // User view: missing data becomes a safe findings list default.
        $statements = $scope instanceof FunctionLike ? ($scope->getStmts() ?? []) : $analysisUnit->statements;
        // User view: choose the findings list branch for this case.
        if ($this->hasUnprovableWrite($name, array_values($statements), $scope, $sinkPosition)) {
            return false;
        }

        $hasReachingAssignment = false;
        // User view: add each item that can appear in findings list.
        foreach ($this->plainAssignmentsTo($name, array_values($statements), $scope) as $assignment) {
            $isFixedAssignment = $this->isFixedIncludeExpression(
                expression:                  $assignment->expr,
                analysisUnit:                $analysisUnit,
                shouldTreatConstantsAsFixed: $shouldTreatConstantsAsFixed,
                dynamicConstantNames:        $dynamicConstantNames,
                canFollowAssignments:        false,
            );
            // User view: choose the findings list branch for this case.
            if (!$isFixedAssignment) {
                // One non-fixed assignment can reach the include (directly or via a loop), so the variable stays dynamic.
                return false;
            }

            // User view: choose the findings list branch for this case.
            if ($assignment->getStartFilePos() >= 0 && $assignment->getStartFilePos() < $sinkPosition) {
                $hasReachingAssignment = true;
            }
        }

        // Without an assignment before the include the value comes from elsewhere (parameter, extract, global state).
        return $hasReachingAssignment;
    }

    /**
     * List plain same-scope assignments to a variable name.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string            $name - Variable name without the leading `$`.
     * @param list<Stmt>        $statements - Statements of the owning scope.
     * @param FunctionLike|null $scope - Owning function-like scope, or null for file scope.
     *
     * @return list<Expr\Assign> - assignments whose target is exactly the named variable, in source order.
     */
    private function plainAssignmentsTo(string $name, array $statements, ?FunctionLike $scope): array
    {
        $assignments = [];
        $nodeFinder  = new NodeFinder();

        // User view: add each item that can appear in findings list.
        foreach ($nodeFinder->find($statements, static fn(Node $candidate): bool => $candidate instanceof Expr\Assign) as $assignment) {
            // User view: choose the findings list branch for this case.
            if (!$assignment instanceof Expr\Assign || !$assignment->var instanceof Expr\Variable || $assignment->var->name !== $name) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (SecurityNodeHelper::enclosingFunctionLike($assignment) !== $scope) {
                continue;
            }

            $assignments[] = $assignment;
        }

        return $assignments;
    }

    /**
     * Detect writes to a variable that defeat the fixed-assignment proof.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string            $name - Variable name without the leading `$`.
     * @param list<Stmt>        $statements - Statements of the owning scope.
     * @param FunctionLike|null $scope - Owning function-like scope, or null for file scope.
     * @param int               $sinkPosition - Byte offset of the include-path variable.
     *
     * @return bool - True when the name is a parameter or can be written through any construct other than a plain assignment.
     */
    private function hasUnprovableWrite(string $name, array $statements, ?FunctionLike $scope, int $sinkPosition): bool
    {
        // User view: choose the findings list branch for this case.
        if ($scope instanceof FunctionLike && $this->isParameterName($name, $scope)) {
            // A parameter carries caller-controlled data the assignment walk cannot see, so the variable is unprovable.
            return true;
        }

        $unprovableWrite = (new NodeFinder())->findFirst(
            $statements,
            fn(Node $candidate): bool => $this->isCandidateBeforeSink($candidate, $sinkPosition)
                                        && $this->isUnprovableWriteNode($candidate, $name),
        );

        return $unprovableWrite instanceof Node;
    }

    /**
     * Check whether a candidate can affect the include expression by source order.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $candidate - Candidate write or call node.
     * @param int  $sinkPosition - Byte offset of the include-path variable.
     *
     * @return bool - True when the candidate precedes the include, or ordering is unavailable.
     */
    private function isCandidateBeforeSink(Node $candidate, int $sinkPosition): bool
    {
        $candidatePosition = $candidate->getStartFilePos();

        return $candidatePosition < 0 || $candidatePosition < $sinkPosition;
    }

    /**
     * Check whether a name is bound as a parameter of the given scope.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string       $name - Variable name without the leading `$`.
     * @param FunctionLike $scope - Function-like scope owning the include.
     *
     * @return bool - True when any declared parameter binds the name.
     */
    private function isParameterName(string $name, FunctionLike $scope): bool
    {
        // User view: add each item that can appear in findings list.
        foreach ($scope->getParams() as $parameter) {
            // User view: choose the findings list branch for this case.
            if ($parameter->var instanceof Expr\Variable && $parameter->var->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify a node as a write that defeats the fixed-assignment proof for a variable name.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node   $candidate - Node to classify.
     * @param string $name - Variable name without the leading `$`.
     *
     * @return bool - True for compound/by-ref assignments, call arguments, foreach bindings, global/static declarations,
     *                by-ref closure captures, and destructuring assignments that touch the name.
     */
    private function isUnprovableWriteNode(Node $candidate, string $name): bool
    {
        // User view: choose the findings list branch for this case.
        if (($candidate instanceof Expr\AssignOp || $candidate instanceof Expr\AssignRef)
            && $candidate->var instanceof Expr\Variable
            && $candidate->var->name === $name
        ) {
            return true;
        }

        // User view: choose the findings list branch for this case.
        if ($candidate instanceof Expr\FuncCall || $candidate instanceof Expr\MethodCall || $candidate instanceof Expr\StaticCall) {
            // User view: choose the findings list branch for this case.
            if ($this->doesPassNameAsDirectMutableArgument($candidate, $name)) {
                return true;
            }
        }

        // User view: choose the findings list branch for this case.
        if ($candidate instanceof Stmt\Foreach_ && ($this->doesBindName($candidate->valueVar, $name) || ($candidate->keyVar instanceof Expr && $this->doesBindName($candidate->keyVar, $name)))) {
            return true;
        }

        // User view: choose the findings list branch for this case.
        if ($candidate instanceof Stmt\Global_ || $candidate instanceof Stmt\Static_) {
            // Global/static declarations splice in cross-scope state the walk cannot order, so the name is unprovable.
            return $this->doesDeclareName($candidate, $name);
        }

        // User view: choose the findings list branch for this case.
        if ($candidate instanceof Node\ClosureUse && $candidate->byRef && $candidate->var->name === $name) {
            // A by-ref capture lets a nested closure rewrite the variable after the walk, so the name is unprovable.
            return true;
        }

        // Destructuring writes hide the target inside a list/array pattern instead of a plain variable target.
        return $candidate instanceof Expr\Assign
               && !$candidate->var instanceof Expr\Variable
               && $this->doesBindName($candidate->var, $name);
    }

    /**
     * Detect calls that hand the include-path local to code that may mutate it before the include.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node whose arguments are inspected.
     * @param string                                        $name - Variable name without the leading `$`.
     *
     * @return bool - True when the variable is passed as a direct argument and could be mutated by reference.
     */
    private function doesPassNameAsDirectMutableArgument(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, string $name): bool
    {
        // User view: add each item that can appear in findings list.
        foreach ($call->args as $argument) {
            // User view: choose the findings list branch for this case.
            if (!$argument instanceof Node\Arg) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if ($argument->value instanceof Expr\Variable && $argument->value->name === $name) {
                return $argument->byRef || !$this->isKnownNonMutatingPathFunction($call);
            }
        }

        return false;
    }

    /**
     * Check whether a direct argument is being read by a known path guard instead of handed to unknown code.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node carrying the argument.
     *
     * @return bool - True for global built-ins that inspect path strings without mutating their arguments.
     */
    private function isKnownNonMutatingPathFunction(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        // User view: choose the findings list branch for this case.
        if (!$call instanceof Expr\FuncCall) {
            // Method/static calls can be userland code with by-reference parameters, so remain unprovable.
            return false;
        }

        $functionName = SecurityNodeHelper::globalFunctionName($call);

        // User view: missing data becomes the expected findings list state.
        return $functionName !== null && isset(self::NON_MUTATING_PATH_FUNCTIONS[$functionName]);
    }

    /**
     * Check whether a binding expression (variable, list, or array pattern) binds a name.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr   $binding - Foreach value/key var or destructuring target.
     * @param string $name - Variable name without the leading `$`.
     *
     * @return bool - True when any variable leaf inside the binding carries the name.
     */
    private function doesBindName(Expr $binding, string $name): bool
    {
        $bound = (new NodeFinder())->findFirst(
            $binding,
            static fn(Node $candidate): bool => $candidate instanceof Expr\Variable && $candidate->name === $name,
        );

        return $bound instanceof Node;
    }

    /**
     * Check whether a global or static statement declares a name.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\Global_|Stmt\Static_ $declaration - Declaration statement to inspect.
     * @param string                    $name - Variable name without the leading `$`.
     *
     * @return bool - True when the declaration lists the name.
     */
    private function doesDeclareName(Stmt\Global_|Stmt\Static_ $declaration, string $name): bool
    {
        // User view: choose the findings list branch for this case.
        if ($declaration instanceof Stmt\Global_) {
            // User view: add each item that can appear in findings list.
            foreach ($declaration->vars as $declared) {
                // User view: choose the findings list branch for this case.
                if ($declared instanceof Expr\Variable && $declared->name === $name) {
                    return true;
                }
            }

            return false;
        }

        // User view: add each item that can appear in findings list.
        foreach ($declaration->vars as $staticVar) {
            // User view: choose the findings list branch for this case.
            if ($staticVar->var->name === $name) {
                return true;
            }
        }

        return false;
    }
}
