<?php

declare(strict_types=1);

namespace GruffPhp\Rule\DeadCode;

/**
 * Summarises a project-owned symbol declaration without retaining AST nodes.
 */
final readonly class DeadCodeSymbolDeclaration
{
    /**
     * Capture one declaration that can be checked for project-wide references.
     *
     * @param string       $fqn - Fully qualified symbol name without a leading slash.
     * @param string       $displayPath - Project-relative path where the declaration appears.
     * @param int          $line - Declaration start line.
     * @param string       $kind - Symbol kind: class, interface, trait, enum, function, or constant.
     * @param list<string> $attributes - Fully qualified attribute names applied to the declaration.
     * @param bool         $isAbstract - Whether the declaration is an abstract class or interface-like contract.
     * @param bool         $isTestFile - Whether the declaration lives in a test path.
     */
    public function __construct(
        public string $fqn,
        public string $displayPath,
        public int $line,
        public string $kind,
        public array $attributes = [],
        public bool $isAbstract = false,
        public bool $isTestFile = false,
    ) {
    }
}
