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
 * Flags a call to a weak cryptographic primitive - `md5()`, `sha1()`, or any `mcrypt_*` function - so the
 * user moves to a modern hashing or encryption API before the value can be forged or reversed.
 *
 * Runs per file over global calls to the known-weak function names. Warning, high confidence - the names are
 * unambiguous, so a match is a near-certain weak-primitive use.
 */
final class WeakCryptoRule implements RuleInterface
{
    /**
     * Stable rule identifier for weak cryptography findings.
     */
    public const ID = 'security.weak-crypto';

    /**
     * Describes the weak-cryptography rule for the registry and reports.
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
     * Reports each call to a weak hashing or cryptography primitive.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for weak cryptography usage; empty when the file calls none.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Check every function call in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            // A dynamic or method call has no global name to match.
            if ($name === null) {
                continue;
            }

            // Only md5, sha1, and the mcrypt_* family are the weak primitives.
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
