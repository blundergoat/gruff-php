<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Naming\FunctionLikeScope;
use GruffPhp\Rule\Naming\FunctionLikeScopeWalker;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers function-like scope walking: isolation of nested closures, shadowed parameters, separate scopes for arrow functions inside foreach, and
 * method-before-body-closure yield order.
 */
final class FunctionLikeScopeWalkerTest extends TestCase
{
    /**
     * Verify nested closures keep parameter and local-variable names isolated by scope.
     *
     * @return void
     */
    public function testScopesIsolateNestedClosuresAndShadowedParameters(): void
    {
        $scopes = $this->scopesFor(<<<'PHP'
            <?php

            function outer($item): void
            {
                $outerValue = 1;
                $callback = function ($item) use ($outerValue): int {
                    $insideValue = $item;
                    $nested = function ($item): int {
                        $deepValue = $item;

                        return $deepValue;
                    };

                    return $insideValue;
                };
            }
            PHP
        );

        self::assertSame(['function', 'closure', 'closure'], array_map(
            static fn(FunctionLikeScope $scope): string => $scope->kind,
            $scopes,
        ));

        self::assertSame(['item'], array_keys($scopes[0]->parameterNames));
        self::assertSame(['outerValue', 'callback'], array_keys($scopes[0]->localVariables));

        self::assertSame(['item'], array_keys($scopes[1]->parameterNames));
        self::assertSame(['insideValue', 'nested'], array_keys($scopes[1]->localVariables));
        self::assertArrayNotHasKey('outerValue', $scopes[1]->localVariables);

        self::assertSame(['item'], array_keys($scopes[2]->parameterNames));
        self::assertSame(['deepValue'], array_keys($scopes[2]->localVariables));
    }

    /**
     * Verify arrow functions inside foreach bodies become separate scopes.
     *
     * @return void
     */
    public function testArrowFunctionsInsideForeachAreSeparateScopes(): void
    {
        $scopes = $this->scopesFor(<<<'PHP'
            <?php

            function buildCallbacks(array $values): array
            {
                $callbacks = [];

                foreach ($values as $index => $value) {
                    $callbacks[] = fn ($entry): string => $entry . ':' . $value;
                }

                return $callbacks;
            }
            PHP
        );

        self::assertSame(['function', 'arrow'], array_map(
            static fn(FunctionLikeScope $scope): string => $scope->kind,
            $scopes,
        ));

        self::assertSame(['values'], array_keys($scopes[0]->parameterNames));
        self::assertSame(['callbacks', 'index', 'value'], array_keys($scopes[0]->localVariables));
        self::assertArrayNotHasKey('entry', $scopes[0]->localVariables);

        self::assertSame(['entry'], array_keys($scopes[1]->parameterNames));
        self::assertSame(['value'], array_keys($scopes[1]->localVariables));
    }

    /**
     * Verify class methods are yielded before closures declared in their bodies.
     *
     * @return void
     */
    public function testMethodsAreYieldedBeforeBodyClosures(): void
    {
        $scopes = $this->scopesFor(<<<'PHP'
            <?php

            final class Example
            {
                public function handle($request): void
                {
                    $runner = static function ($request): void {
                        $result = $request;
                    };
                }
            }
            PHP
        );

        self::assertSame(['method', 'closure'], array_map(
            static fn(FunctionLikeScope $scope): string => $scope->kind,
            $scopes,
        ));

        self::assertSame(['request'], array_keys($scopes[0]->parameterNames));
        self::assertSame(['runner'], array_keys($scopes[0]->localVariables));

        self::assertSame(['request'], array_keys($scopes[1]->parameterNames));
        self::assertSame(['result'], array_keys($scopes[1]->localVariables));
    }

    /**
     * Build function-like scopes for a test fixture.
     *
     * @param string $source Inline PHP source defining the functions and closures to walk.
     *
     * @return list<FunctionLikeScope> - the fixture's scopes in depth-first declaration order, the positions tests index by
     */
    private function scopesFor(string $source): array
    {
        $unit = $this->parseSource($source);

        // FunctionLikeScopeWalker yields in depth-first declaration order; callers index $scopes
        // by fixed position, so that order is the contract this helper hands back unchanged.
        return (new FunctionLikeScopeWalker())->scopes($unit->statements);
    }

    /**
     * Parse inline PHP source through the production parser.
     *
     * @param string $source Inline PHP written to a throwaway temp file before parsing.
     *
     * @return AnalysisUnit - parsed statements plus diagnostics, asserted diagnostic-free so callers walk valid input
     */
    private function parseSource(string $source): AnalysisUnit
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-scope-');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $source));

        try {
            $unit = (new PhpFileParser())->parse(new SourceFile($path, 'inline.php'));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        self::assertSame([], $unit->diagnostics);

        // Hand back the unit only once it parsed cleanly, so scope tests start from valid input.
        return $unit;
    }
}
