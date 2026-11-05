<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;

/**
 * Flags a documented function or method whose `@param` tags state a type and name but no description, once per
 * declaration, naming every such parameter.
 *
 * The parameter sibling of docs.return-comment: a docblock with a summary or a described tag over a bare
 * `@param Type $name` trips neither docs.missing-param-tag (presence is satisfied) nor docs.bare-phpdoc-tags
 * (the docblock is not tags-only). It stays silent when there is no docblock, and on a wholly tags-only
 * function or method docblock, which docs.bare-phpdoc-tags already reports, so one docblock is never reported
 * by both; with `everyCallable` on, a bound closure's tags-only block is still checked because
 * docs.bare-phpdoc-tags never reads closures. Off by default; advisory when enabled.
 */
final readonly class ParamCommentRule implements RuleInterface
{
    /**
     * Stable rule identifier for described-param-tag findings.
     */
    public const ID = 'docs.param-comment';

    /**
     * Describes the described-param-tag documentation rule for the registry and reports.
     *
     * @return RuleDefinition - rule metadata and defaults for the registry and listings
     */
    public function definition(): RuleDefinition
    {
        // High confidence: a @param tag with nothing after its variable is unambiguous; off by default so teams opt in.
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Described param tag',
            pillar:             Pillar::Documentation,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::High,
            isEnabledByDefault: false,
            defaultOptions:     [DocumentedCallables::EVERY_CALLABLE => false],
            optionDescriptions: [DocumentedCallables::EVERY_CALLABLE => DocumentedCallables::EVERY_CALLABLE_DESCRIPTION],
            description:        'A function or method must describe each parameter in its @param tag, not just restate the type and name, so a reviewer can check what a caller must pass. Fires once per declaration whose docblock has a @param tag with nothing after the variable, naming every such parameter. Silent without a docblock; missing @param tags are owned by docs.missing-param-tag, and a function or method docblock made only of bare @param and @return tags, with no summary or other tag, by docs.bare-phpdoc-tags. With `everyCallable`, also closures and arrow functions bound to a name, read from the docblock above the assignment, bare-tags-only blocks included. Off by default; enable it via .gruff-php.yaml.',
        );
    }

    /**
     * Reports each documented function-like whose docblock carries at least one undescribed `@param` tag.
     *
     * @param AnalysisUnit $unit    - parsed unit to inspect
     * @param RuleContext  $context - rule context for this analysis pass
     *
     * @return list<Finding> - one finding per declaration, naming every parameter whose `@param` tag is bare
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $findings   = [];

        // Check every method and function in the file, plus bound closures when everyCallable is on.
        foreach (DocumentedCallables::inUnit($unit, $context, $definition) as ['node' => $node, 'doc' => $docComment, 'symbol' => $symbol]) {
            if ($docComment === null) {
                // No docblock means no @param tag to describe.
                continue;
            }

            // docs.bare-phpdoc-tags reports a wholly tags-only docblock on a function or method, so stay silent there and
            // report it once. It never reads bound closures, so their tags-only blocks stay with this rule.
            if (!($node instanceof Closure || $node instanceof ArrowFunction) && PhpdocTagText::isBareTagsOnly($docComment->getText())) {
                continue;
            }

            $bareNames = PhpdocTagText::bareParamTagNames($docComment->getText());

            if ($bareNames === []) {
                // Every @param tag carries a description, or there is none to check.
                continue;
            }

            $variables = implode(', ', array_map(static fn(string $name): string => '$' . $name, $bareNames));
            $message   = count($bareNames) === 1
                ? sprintf('%s has a bare @param tag for %s; describe what the parameter means, not just its type.', $symbol, $variables)
                : sprintf('%s has bare @param tags for %s; describe what each parameter means, not just its type.', $symbol, $variables);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     $message,
                filePath:    $unit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: 'Describe each parameter after its name, e.g. `@param int $limit - most rows to return; 0 means no limit`, so the contract states what a caller must pass.',
            );
        }

        return $findings;
    }
}
