<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleDefinition;

/**
 * Carries the shared inputs the identifier-quality rules need to evaluate and emit a finding - the rule
 * metadata, the parsed unit under inspection, the tokenizer, and the configured placeholder, generic,
 * ignored, and accepted-abbreviation lists.
 *
 * Bundled into one object so each naming check reads from a single context instead of threading a long
 * argument list through every helper.
 */
final readonly class IdentifierFindingContext
{
    /**
     * Captures rule metadata, parsed unit, tokenizer, and configured identifier lists.
     *
     * @param RuleDefinition      $definition - Rule metadata used on emitted findings.
     * @param AnalysisUnit        $analysisUnit - Parsed unit being inspected.
     * @param IdentifierTokenizer $tokenizer - Tokenizer used to split identifier names.
     * @param list<string>        $placeholderNames - Lowercase placeholder names to flag.
     * @param list<string>        $genericTokens - Lowercase generic tokens to flag.
     * @param list<string>        $ignoredNames - Lowercase names ignored by this rule.
     * @param list<string>        $acceptedAbbreviations - Lowercase accepted project abbreviations.
     */
    public function __construct(
        public RuleDefinition $definition,
        public AnalysisUnit $analysisUnit,
        public IdentifierTokenizer $tokenizer,
        public array $placeholderNames,
        public array $genericTokens,
        public array $ignoredNames,
        public array $acceptedAbbreviations,
    ) {
    }
}
