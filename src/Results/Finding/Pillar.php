<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

/**
 * The quality dimension a rule belongs to - the bucket its findings are grouped and scored under.
 *
 * Every rule declares one pillar, and gruff uses it to shape what the user sees: the report groups
 * findings by pillar, the score breakdown grades each dimension on its own, and a user can tune or
 * mute a whole area (say, quiet `documentation` while tightening `security`) by naming its pillar in
 * config. The cases below are the complete set of dimensions gruff assesses, from raw size through
 * security, testing, and architecture.
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
