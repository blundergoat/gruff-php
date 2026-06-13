<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

/**
 * Groups rules by the quality dimension they primarily assess.
 */
enum Pillar: string
{
    /**
     * Source size, length, and breadth concerns.
     */
    case Size = 'size';

    /**
     * Control-flow and expression complexity concerns.
     */
    case Complexity = 'complexity';

    /**
     * Dependency and collaboration breadth concerns.
     */
    case Coupling = 'coupling';

    /**
     * Unused or unreachable code concerns.
     */
    case DeadCode = 'dead-code';

    /**
     * Identifier and naming consistency concerns.
     */
    case Naming = 'naming';

    /**
     * PHPDoc and explanatory documentation concerns.
     */
    case Documentation = 'documentation';

    /**
     * Security-sensitive code pattern concerns.
     */
    case Security = 'security';

    /**
     * Secret and sensitive-data exposure concerns.
     */
    case SensitiveData = 'sensitive-data';

    /**
     * Object design and abstraction shape concerns.
     */
    case Design = 'design';

    /**
     * Modern PHP language and API usage concerns.
     */
    case Modernisation = 'modernisation';

    /**
     * Test reliability and test design concerns.
     */
    case TestQuality = 'test-quality';

    /**
     * Architectural boundary and layering concerns.
     */
    case Architecture = 'architecture';

    /**
     * Maintainability metric and readability concerns.
     */
    case Maintainability = 'maintainability';

    /**
     * Mutation testing signal and survivor-budget concerns.
     */
    case Mutation = 'mutation';
}
