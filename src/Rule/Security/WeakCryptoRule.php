<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

final class WeakCryptoRule implements RuleInterface
{
    public const ID = 'security.weak-crypto';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Weak cryptography primitives',
            pillar: Pillar::Security,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            if ($name === null) {
                continue;
            }

            if (!in_array($name, ['md5', 'sha1'], true) && !str_starts_with($name, 'mcrypt_')) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf('Weak cryptography primitive detected: %s().', $name),
                filePath: $unit->file->displayPath,
                line: $call->getStartLine(),
                severity: Severity::Warning,
                pillar: Pillar::Security,
                tier: RuleTier::V01,
                confidence: Confidence::High,
                remediation: 'Use password_hash(), hash_hmac(), random_bytes(), or libsodium/OpenSSL primitives appropriate to the use case.',
                metadata: [
                    'function' => $name,
                ],
            );
        }

        return $findings;
    }
}
