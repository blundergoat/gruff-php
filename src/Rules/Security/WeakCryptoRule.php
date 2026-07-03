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
use PhpParser\Node\Expr;

/**
 * Detects weak cryptography primitives that should be replaced with modern alternatives.
 */
final class WeakCryptoRule implements RuleInterface
{
    /**
     * Stable rule identifier for weak cryptography findings.
     */
    public const ID = 'security.weak-crypto';

    /**
     * Describe the weak cryptography security rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // High confidence: md5/sha1/mcrypt_* are unambiguous names, so a match is a near-certain weak-primitive use.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Weak cryptography primitives',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find weak hashing and cryptography primitives in source code.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for weak cryptography usage; empty when the file calls none.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($name === null) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (!in_array($name, ['md5', 'sha1'], true) && !str_starts_with($name, 'mcrypt_')) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('Weak cryptography primitive detected: %s().', $name),
                filePath:    $analysisUnit->file->displayPath,
                line:        $call->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                remediation: 'Use password_hash(), hash_hmac(), random_bytes(), or libsodium/OpenSSL primitives appropriate to the use case.',
                metadata:    [
                    'function' => $name,
                ],
            );
        }

        return $findings;
    }
}
