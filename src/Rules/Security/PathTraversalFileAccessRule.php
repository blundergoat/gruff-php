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

/**
 * Flags a filesystem call whose path comes from the request - `file_get_contents`, `fopen`, `unlink`, a
 * SplFileObject, and the like - the shape that lets an attacker climb out of the intended directory (`../`).
 *
 * Runs per file over known path-taking functions and filesystem-iterator constructors whose path argument
 * reaches user input, skipping URL literals. Warning, medium confidence - a likely traversal sink, not certain.
 */
final class PathTraversalFileAccessRule implements RuleInterface
{
    /**
     * Stable rule identifier for request-controlled filesystem paths.
     */
    public const ID = 'security.path-traversal-file-access';

    /**
     * Path-taking filesystem functions mapped to the argument indices that hold a path.
     *
     * @var array<string, list<int>>
     */
    private const PATH_ARGUMENTS = [
        'chdir' => [0],
        'copy' => [0, 1],
        'file_get_contents' => [0],
        'file_put_contents' => [0],
        'fopen' => [0],
        'glob' => [0],
        'mkdir' => [0],
        'readfile' => [0],
        'realpath' => [0],
        'rename' => [0, 1],
        'rmdir' => [0],
        'scandir' => [0],
        'unlink' => [0],
    ];

    /**
     * Describes the path-traversal-file-access rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence by default: request data reaching a path argument is a likely traversal sink, not certain.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Path traversal file access',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            falsePositiveShapes: [
                [
                    'shape'      => 'A path already confined before use, such as one resolved with realpath() and compared against a base directory, or a basename taken from an allow-list.',
                    'mitigation' => 'The check asks whether request data reaches the argument, not whether a guard exists, so build the final path in a local that does not reference the superglobal.',
                ],
            ],
        );
    }

    /**
     * Reports each filesystem sink fed a request-controlled path.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per filesystem sink fed request-controlled data.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Check every function call in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            // Only the known path-taking functions can be traversal sinks.
            if ($name === null || !isset(self::PATH_ARGUMENTS[$name])) {
                continue;
            }

            // Weigh each path argument the function takes.
            foreach (self::PATH_ARGUMENTS[$name] as $argumentIndex) {
                $pathArg = SecurityNodeHelper::sinkArgumentValue($call, $argumentIndex);
                // A URL, or a path with no request data, is not local traversal.
                if ($pathArg === null || SecurityNodeHelper::containsUrlLiteral($pathArg) || !SecurityNodeHelper::containsUserInput($pathArg)) {
                    continue;
                }

                $findings[] = $this->finding($analysisUnit, $call, $name);
                break;
            }
        }

        // Check filesystem iterator and file-object constructions too.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\New_::class) as $new) {
            // Only the path-taking filesystem classes qualify.
            if (!SecurityNodeHelper::hasMatchingClassName($new->class, ['FilesystemIterator', 'RecursiveDirectoryIterator', 'SplFileObject'])) {
                continue;
            }

            $pathArg = SecurityNodeHelper::argumentValue($new->args, 0);
            // A request-controlled path is the traversal risk.
            if ($pathArg !== null && SecurityNodeHelper::containsUserInput($pathArg)) {
                $findings[] = $this->finding($analysisUnit, $new, 'filesystem-object');
            }
        }

        return $findings;
    }

    /**
     * Builds the path-traversal finding.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying the display path recorded on the finding.
     * @param Node         $node - Call or `new` node whose start line localises the finding for the reviewer.
     * @param string       $sink - Sink label (function name, or `filesystem-object`) recorded on the finding.
     *
     * @return Finding - Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $sink): Finding
    {
        // Report against the sink call's own line so the reviewer lands on the tainted filesystem access.
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('Filesystem access with request-controlled path detected: %s.', $sink),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Normalize paths under an allow-listed base directory and reject traversal segments before filesystem access.',
            metadata:    [
                'sink' => $sink,
            ],
        );
    }
}
