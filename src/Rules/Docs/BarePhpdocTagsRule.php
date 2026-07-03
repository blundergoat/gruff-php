<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory at medium confidence: a tags-only docblock can be deliberate on a trivial unit, so this nudges
        // toward describing intent rather than gating a build.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for bare PHPDoc blocks.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $docComment = $node->getDocComment();

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($docComment === null) {
                continue;
            }

            $docText  = $docComment->getText();
            // User view: missing data becomes a safe findings list default.
            $stripped = preg_replace('/\/\*\*|\*\/|\*/', '', $docText) ?? $docText;
            $stripped = trim($stripped);

            $lines = array_filter(
                array_map('trim', explode("\n", $stripped)),
                // User view: an empty value becomes a clear findings list fallback.
                static fn(string $line): bool => $line !== '',
            );

            $hasNonTagContent = false;

            // User view: add each item that can appear in findings list.
            foreach ($lines as $line) {
                // User view: choose the findings list branch for this case.
                if (!str_starts_with($line, '@')) {
                    $hasNonTagContent = true;

                    break;
                }
            }

            // User view: choose the findings list branch for this case.
            if ($hasNonTagContent) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
            if ($lines === []) {
                continue;
            }

            $hasOnlyBareTags = true;

            // User view: add each item that can appear in findings list.
            foreach ($lines as $line) {
                // User view: choose the findings list branch for this case.
                if ($this->isBareParamOrReturnTag($line)) {
                    continue;
                }

                $hasOnlyBareTags = false;

                break;
            }

            // User view: choose the findings list branch for this case.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $line - Single trimmed docblock line, already stripped of comment markers, to classify.
     *
     * @return bool - True when the tag is a bare parameter or return tag.
     */
    private function isBareParamOrReturnTag(string $line): bool
    {
        // Match @param tags that end at the variable name with no descriptive prose.
        // User view: choose the findings list branch for this case.
        if (preg_match('/^@param\s+\S+(?:\s+\S+)*\s+\$\w+\s*$/', $line) === 1) {
            // A @param stopping at the variable name carries no description, so it is bare.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if (!str_starts_with($line, '@return ')) {
            // Lines that are neither a bare @param nor a @return cannot be a bare return tag.
            return false;
        }

        // A @return is bare exactly when no prose follows its type, so negate the shared description check.
        return !PhpdocTagText::hasReturnTagDescription(trim(substr($line, strlen('@return '))));
    }
}
