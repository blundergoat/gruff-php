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
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Detects PHPDoc blocks that list tags without descriptive intent.
 */
final readonly class BarePhpdocTagsRule implements RuleInterface
{
    /**
     * Stable rule identifier for bare PHPDoc tag findings.
     */
    public const ID = 'docs.bare-phpdoc-tags';

    /**
     * Describe the bare PHPDoc tag rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Bare PHPDoc tags',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find docblocks that only list parameter or return tags.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for bare PHPDoc blocks.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $docComment = $node->getDocComment();

            if ($docComment === null) {
                continue;
            }

            $docText  = $docComment->getText();
            $stripped = preg_replace('/\/\*\*|\*\/|\*/', '', $docText) ?? $docText;
            $stripped = trim($stripped);

            $lines = array_filter(
                array_map('trim', explode("\n", $stripped)),
                static fn (string $line): bool => $line !== '',
            );

            $hasNonTagContent = false;

            foreach ($lines as $line) {
                if (!str_starts_with($line, '@')) {
                    $hasNonTagContent = true;

                    break;
                }
            }

            if ($hasNonTagContent) {
                continue;
            }

            if ($lines === []) {
                continue;
            }

            $hasOnlyBareTags = true;

            foreach ($lines as $line) {
                if ($this->isBareParamOrReturnTag($line)) {
                    continue;
                }

                $hasOnlyBareTags = false;

                break;
            }

            if (!$hasOnlyBareTags) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s has PHPDoc tags but no descriptive summary or tag descriptions.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: 'Add a short summary, or describe the parameter and return tags so the docblock states intent.',
            );
        }

        return $findings;
    }

    /**
     * Check whether one PHPDoc tag has a type but no description.
     *
     * @return bool True when the tag is a bare parameter or return tag.
     */
    private function isBareParamOrReturnTag(string $line): bool
    {
        // Match @param tags that end at the variable name with no descriptive prose.
        if (preg_match('/^@param\s+\S+(?:\s+\S+)*\s+\$\w+\s*$/', $line) === 1) {
            return true;
        }

        if (!str_starts_with($line, '@return ')) {
            return false;
        }

        return !$this->hasReturnTagDescription(trim(substr($line, strlen('@return '))));
    }

    /**
     * Detect prose after a return type while tolerating spaces inside PHPDoc generic types.
     *
     * @return bool True when text follows the type.
     */
    private function hasReturnTagDescription(string $body): bool
    {
        $depth  = 0;
        $length = strlen($body);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $body[$offset];

            if (str_contains('<{[(', $character)) {
                $depth++;
                continue;
            }

            if (str_contains('>}])', $character) && $depth > 0) {
                $depth--;
                continue;
            }

            if ($depth === 0 && ctype_space($character)) {
                return trim(substr($body, $offset + 1)) !== '';
            }
        }

        return false;
    }
}
