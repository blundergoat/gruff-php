<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Naming;

use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleDefinition;

/**
 * Carries shared inputs needed to evaluate and emit identifier-quality findings.
 */
final readonly class IdentifierFindingContext
{
    /**
     * Capture rule metadata, parsed unit, tokenizer, and configured identifier lists.
     *
     * @param RuleDefinition      $definition            Rule metadata used on emitted findings.
     * @param AnalysisUnit        $analysisUnit          Parsed unit being inspected.
     * @param IdentifierTokenizer $tokenizer             Tokenizer used to split identifier names.
     * @param list<string>        $placeholderNames      Lowercase placeholder names to flag.
     * @param list<string>        $genericTokens         Lowercase generic tokens to flag.
     * @param list<string>        $ignoredNames          Lowercase names ignored by this rule.
     * @param list<string>        $acceptedAbbreviations Lowercase accepted project abbreviations.
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
