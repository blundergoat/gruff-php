<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
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
     * @return RuleDefinition Rule metadata and defaults.
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for weak cryptography usage.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            if ($name === null) {
                continue;
            }

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

        // Empty when the file calls no md5/sha1/mcrypt_* primitive; the caller treats that as a clean file.
        return $findings;
    }
}
