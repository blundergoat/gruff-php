<?php

declare(strict_types=1);

namespace GruffPhp\Rule\DeadCode;

/**
 * Summarises one supported symbol reference without retaining AST nodes.
 */
final readonly class DeadCodeSymbolReference
{
    /**
     * Capture one project-wide reference to a declaration candidate.
     *
     * @param string      $fqn          Fully qualified referenced symbol without a leading slash.
     * @param string|null $originSymbol Enclosing declaration FQN, when known; used to ignore purely self-recursive references.
     * @param bool        $isTestFile   Whether the reference comes from a test path.
     */
    public function __construct(
        public string $fqn,
        public ?string $originSymbol,
        public bool $isTestFile,
    ) {
    }
}
