<?php

declare(strict_types=1);

namespace GruffPhp\Finding;

enum Pillar: string
{
    case Size = 'size';
    case Complexity = 'complexity';
    case Coupling = 'coupling';
    case DeadCode = 'dead-code';
    case Naming = 'naming';
    case Documentation = 'documentation';
    case Security = 'security';
    case SensitiveData = 'sensitive-data';
    case Design = 'design';
    case Modernisation = 'modernisation';
    case TestQuality = 'test-quality';
    case Architecture = 'architecture';
    case Maintainability = 'maintainability';
    case Mutation = 'mutation';
}
